# Specify — Controle de Despesas Compartilhadas

> Documento de negócio. Descreve o sistema **como ele existe hoje no código** (controllers, models, migrations, telas), não a visão aspiracional dos READMEs antigos. Serve de baseline para todo trabalho futuro: qualquer feature nova referencia uma seção daqui; qualquer mudança de comportamento atualiza este arquivo.
>
> Fontes usadas para levantar este documento: `backend/routes/api.php`, `backend/app/Models/*`, `backend/app/Http/Controllers/*`, `backend/database/migrations/*`, `frontend/src/pages/*`.

Versão: 1.3 · Última atualização: 2026-11-10

---

## 1. Objetivo do produto

Permitir que um grupo de pessoas (família, república, grupo de amigos) registre despesas compartilhadas, divida o valor entre os participantes definidos por despesa, e enxergue quanto cada pessoa deve a quem, mês a mês, incluindo cobrança via Pix.

## 2. Glossário de domínio (real)

| Entidade | Tabela | Campos principais | Observação |
|---|---|---|---|
| `User` | `ex_users` | `name`, `email`, `password` (hash), `role`, `pix`, `invited_by` | Tem chave Pix opcional (`pix`) usada para gerar cobrança. |
| `Group` | `ex_groups` | `name`, `description`, `create_date`, `deleted` | Soft delete via `deleted`. N:N com `User` via `ex_groups_members`. |
| `Expense` | `ex_expenses` | `description`, `total_value`, `expense_type` (`IN_CASH`\|`IN_INSTALLMENTS`), `installments`, `date_payment`, `group_id`, `user_creator_id`, `user_payer_id`, `deleted` | `user_payer_id` = quem **pagou de fato** a despesa. |
| Pagadores/participantes da despesa | `ex_expenses_payers` | `expense_id`, `user_id` | N:N — **quem participa da divisão** do valor (pode incluir ou não o `user_payer_id`). Não existe entidade `GrupoPagadores` separada — o vínculo é direto na despesa. |
| `Quota` | `ex_quotas` | `expense_id`, `number`, `value_quota`, `date_expected`, `paid` | Parcela da despesa. Para `IN_CASH`, ainda é criada 1 quota. |
| Pré-cadastro | `ex_user_pre_create` | `name`, `email`, `whatsapp`, `password` (hash), `code_hash`, `handle_hash`, `attempts`, `resend_count`, `expires_at`, `last_sent_at`, `consumed_at` | Dados do formulário de auto-cadastro **antes** de existir um `User`. Vira `User` quando o código de 6 dígitos é confirmado; a linha é marcada `consumed_at` (nunca apagada) e tem o material de credencial esvaziado. |
| `Participation` | `ex_participations` | `group_id`, `quota_id`, `state` | **Existe na migration/model mas não é populada por nenhum endpoint hoje** — ver seção 6, "Divergências". |
| Quem paga por quem | `ex_group_member_responsibles` | `group_id`, `user_id` (o devedor), `responsible_user_id` | Vínculo **por grupo** entre um devedor e quem pode pagar por ele, isto é, enviar o comprovante dos acertos dele; cada devedor tem nenhum, um ou vários. Na interface é "Quem paga por você": "Responsável" na tela continua sendo o criador do grupo. Quem enviou cada comprovante fica em `ex_settlement_confirmations.confirmed_by` (nula = o próprio devedor). |

## 3. Fluxos implementados

### 3.1 Autenticação e conta
- **Auto-cadastro em duas etapas** (público, `PreRegisterController` + `PreRegistrationService`) — é o caminho que a tela `/cadastro` usa:
  1. `POST /pre-register` — recebe nome, e-mail (+ confirmação), telefone opcional e senha (+ confirmação). **Não cria usuário**: grava uma linha em `ex_user_pre_create` com a senha já hasheada, envia um código de 6 dígitos por e-mail (validade 15 min) e devolve um `handle` opaco a quem submeteu.
  2. `POST /pre-register/verify` — recebe e-mail + `handle` + código. Confirmando, cria o `User` com `email_verified_at` preenchido e o telefone em `whatsapp`, e devolve o mesmo payload de `POST /login` (a conta já nasce autenticada).
  3. `POST /pre-register/resend` — novo código para o mesmo pré-cadastro. Cooldown de 60 s, teto de 5 reenvios, 5 tentativas erradas por código.

  O `handle` existe porque o pré-cadastro é chaveado por e-mail: sem ele, um terceiro poderia sobrescrever o pré-cadastro pendente de outra pessoa com a senha dele. Ver `docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/plan.md` §10.
- `POST /register` — cria usuário (nome, e-mail, senha ≥6 chars) **sem confirmar o e-mail**. Público, e nenhum cliente o chama desde que o auto-cadastro existe. Mantido por compatibilidade de contrato (`00-constitution.md` §4.1); depreciá-lo é item de backlog.
- `POST /login` — autentica via `tymon/jwt-auth`, retorna `access_token` (bearer) + TTL. Público.
- `GET /me`, `POST /logout`, `GET /dashboard` — autenticados.
- `POST /user/pix` — usuário autenticado define/atualiza a própria chave Pix.

### 3.2 Convite de usuário — **dois fluxos distintos e independentes**
1. `POST /invitations` (`InvitationController::invite`, autenticado): cria o `User` diretamente com senha temporária aleatória, envia e-mail com link contendo `email` + `token` em query string; `POST /invitations/verify` (público) valida o token (comparado por hash) e define a senha definitiva.
2. `POST /groups/{groupId}/members` (`GroupMemberController::store`, autenticado): **convida, não adiciona** (feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/`, item 133 do backlog): cria um convite pendente (ver 3.3) e quem é convidado só entra no grupo ao aceitá-lo. Se o e-mail informado não tem `User`, cria um (nome = a parte do e-mail antes do `@`, com uma senha provisória que ninguém conhece, então o login dessa conta dá 401 até a ativação) e, depois de enviar a resposta, manda o `UserInvitedMail`: o link leva um token de ativação guardado em cache (`invitation-token:<email>`, 2 dias, dedicado: não é o de "esqueci a senha", que dura 60 min) e o texto avisa que há um convite pendente. A pessoa define a senha em `POST /invitations/verify` (público, o mesmo endpoint que atende a recuperação de senha, item 3). Convidar de novo a mesma pessoa não reenvia esse e-mail, e uma falha no envio só vira log.
3. `POST /forgot-password` (público): gera token, guarda em cache por 60 min, limita a 1 pedido a cada 15 min, envia e-mail de recuperação.

> Os fluxos 1 e 2 resolvem o mesmo problema (convidar alguém novo) de formas diferentes e não compartilham código — ver "Divergências". O auto-cadastro de 3.1 é um terceiro caminho de criação de `User`, independente destes dois: quem se cadastra sozinho não passa por convite.

### 3.3 Grupos
- `GET/POST/PUT/DELETE /groups` (`GroupController`, autenticado, via `Route::apiResource`):
  - `index`: lista apenas grupos não deletados dos quais o usuário autenticado é membro.
  - `store`: cria grupo e associa automaticamente o criador como membro.
  - `show`/`update`/`destroy` (soft delete): **não verificam se o usuário autenticado é membro do grupo** (ver `00-constitution.md`, Segurança item 5).
- `GET/POST /groups/{groupId}/members`, `DELETE /groups/{groupId}/members/{userId}` (`GroupMemberController`, autenticado): listar membros, **convidar** (o `POST` cria um convite pendente, ver "Convite pendente" abaixo; o fluxo de e-mail novo está em 3.2) e sair do grupo ou remover membro. O `destroy` vem da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/` (item 086 do backlog):
  - **Quem pode**: o próprio usuário sai; o criador do grupo (`created_by`) remove outro membro; grupo sem `created_by` só permite a saída própria. Não membro, grupo inexistente ou excluído e alvo que não é membro recebem o mesmo **404**; membro comum tentando remover outro recebe **403**.
  - **Efeito**: só tira a linha de `ex_groups_members`, em transação (exclusão física do vínculo, não de dado de negócio). Despesas, parcelas, participantes (`ex_expenses_payers`), pagamentos e fechamentos ficam; quem saiu segue como credor ou devedor do que já existe. O contato e a foto de quem saiu (e-mail, Pix, WhatsApp, avatar) deixam de sair nas respostas aos membros que ficaram: só `id` e nome. O criador que sai mantém `created_by`, e `creator` passa a vir nulo em `GET /groups` e `GET /groups/{id}`.
  - **Bloqueios** (**422** com `message` e `blocking`, até 10 despesas): ser credor de despesa com parcela não paga de outra pessoa, ou participar de despesa fixa ainda ativa (ou ser credor dela com outros participantes). Devedor de parcelas e participante de fixa já interrompida saem.
  - **Depois da saída**: o último membro que sai deixa o grupo com `deleted = true`; só quem foi removido por outra pessoa recebe a notificação `group_member_removed` (sair sozinho não notifica); sair libera a vaga do limite de 3 grupos criados; `PUT /expenses/{id}` segue aceitando quem saiu como participante, mas o novo credor só pode ser membro ou o credor atual.
- **Convite pendente** (feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/`, item 133 do backlog; tabela `ex_group_invitations`: uma linha por par grupo × convidado, com `status` `pending`, `accepted`, `declined` ou `cancelled`, `expires_at`, `invited_by`, `notified_at` e `responded_at`). **Ninguém vira membro sem aceitar**: só o aceite e a criação do grupo (`GroupController::store`) inserem em `ex_groups_members`.
  - **Convidar** (`POST /groups/{groupId}/members`, corpo `{ email }`, e-mail válido com até 255 caracteres): qualquer membro do grupo convida. Responde **201** `{ message: 'Convite enviado.', pending: true, invitation: { id, email, expires_at } }`, o mesmo para e-mail com e sem conta (não revela quem já usa o sistema), ou **409** se a pessoa já é membro; grupo inexistente, excluído ou do qual quem chama não é membro dá **404**. O 201 deixou de significar "membro adicionado" (mudança de sentido de contrato, `00-constitution.md` §4.1). O convite vale **7 dias**: convidar de novo quem tem convite vivo só renova a validade e mantém quem convidou primeiro; convite respondido, cancelado ou vencido é reaberto como novo.
  - **Receber** (`MyInvitationController`): `GET /me/invitations` lista os convites vivos (`pending` e dentro da validade) de grupos não excluídos, do mais recente ao mais antigo, só com `id`, grupo (`id`, `name`), quem convidou (`id`, `name`) e `expires_at`: nada de e-mail, membros, despesas ou saldos. `POST /me/invitations/{invitationId}/accept` faz a pessoa membro e responde 200 `{ message, group: { id, name } }`; quem convidou é avisado se ainda for membro. `POST /me/invitations/{invitationId}/decline` recusa e **ninguém é avisado**. O que não dá para responder recebe o **mesmo 404**, qualquer que seja o motivo: convite de outra pessoa, inexistente, vencido, já respondido ou cancelado; no aceite, também grupo excluído e quem já é membro.
  - **Acompanhar e cancelar** (`GroupInvitationController`): `GET /groups/{groupId}/invitations` mostra a qualquer membro os convites vivos do grupo (`id`, `email`, quem convidou, `expires_at`); `DELETE /groups/{groupId}/invitations/{invitationId}` é permitido a quem convidou ou ao criador do grupo (outro membro recebe **403**) e só enquanto o convite está vivo. Cancelar é um `UPDATE` para `cancelled`, não apaga a linha (`00-constitution.md` §6.0), e não avisa ninguém. Quem só tem convite ainda não é membro: nessas rotas leva o mesmo 404 de um grupo que não existe.
  - **Avisos**: o convite cria a notificação `group_invitation` para quem foi convidado (de novo só se o último aviso tem mais de 24 h) e o aceite cria `group_invitation_accepted` para quem convidou. Os dois são best effort: se falharem, vira log e o convite existe do mesmo jeito. A notificação `group_member_added` deixou de ser criada (as antigas continuam legíveis).
  - **Telas**: em Participantes o botão é **Convidar** e há a lista **Convites pendentes** com **Cancelar**; em Meus Grupos há **Convites recebidos** com **Aceitar** e **Recusar**; o clique na notificação `group_invitation` leva a Meus Grupos.
  - **Limite conhecido** (D10 da feature): o convite segue válido se quem convidou sair do grupo antes do aceite; o criador vê a lista de pendentes e cancela à mão, e quem convidou só é avisado do aceite enquanto ainda for membro.
- **Quem paga por quem** (feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/`; tabela `ex_group_member_responsibles`, uma linha por grupo × devedor × responsável). Um membro indica **nenhuma, uma ou várias** pessoas do mesmo grupo que também podem enviar o comprovante do pagamento dele; só isso, sem ver nada que já não veja como membro.
  - **Escolher** (`PUT /groups/{groupId}/members/{userId}/responsibles`, `GroupMemberResponsibleController`, corpo `{ responsible_user_ids: [...] }`): a lista **inteira**, que substitui a anterior, com no máximo **50 pessoas**; a lista vazia desfaz tudo (não há `DELETE`). **Só o próprio devedor** (`userId` = quem chama) escolhe: outro membro, inclusive o criador do grupo, recebe **403**; não membro, grupo inexistente ou excluído recebem **404**. Responsável que não é membro, o próprio devedor, repetido ou lista com mais de 50 dá **422** e nada muda. Responde 200 `{ message: 'Responsáveis atualizados.', responsibles: [{ id, name }] }`. Quem entra na lista recebe a notificação `payment_responsible_assigned`; quem já estava ou sai não é avisado.
  - **Ler** (`GET /groups/{groupId}/members`): cada membro traz `responsibles` (`[{ id, name }]`, vazia quando não há), visível a todos os membros; nunca e-mail, Pix ou contato do responsável.
  - **Pagar** (`POST /groups/{groupId}/settlements/confirm`): ganha `from_user_id` opcional. Quando difere de quem chama, exige o vínculo (este grupo, esse devedor, quem chama); sem ele, **403**, antes de gravar o arquivo ou a linha. O acerto **continua do devedor**: valores, saldos, selo e snapshots não mudam, e `confirmed_by` registra quem enviou; reenviar substitui foto, nota e `confirmed_by` (o último vence). Todo o resto da regra vale para o responsável: competência fechada e não selada, comprovante obrigatório, nota de até 500 caracteres. `pay()` (o "recebi" do credor) não muda. `summary`, `close` e `reopen` trazem `settlements[].confirmedBy` (`{ id, name }`, só quando difere do devedor, senão `null`), e a notificação `settlement_confirmed` do credor ganha `paidByName` nesse caso.
  - **Saída e remoção**: os vínculos somem, nos dois sentidos (como devedor e como responsável), na mesma transação de `GroupMemberController::destroy`. É exclusão física de uma relação, não de dado de negócio.
  - **Telas**: em Participantes, o botão **Quem paga por mim** (só na linha do próprio usuário) abre o diálogo "Quem pode pagar por você"; as linhas mostram `Quem paga por você: A, B` ou `Quem paga por {membro}: …` e o selo `Paga por {dependente}`; o diálogo de sair ou remover avisa que a escolha será desfeita. Em Pagamentos, nos acertos de quem o usuário paga, o selo `Você paga por {nome}` e os botões de hoje (sem botão quando o usuário é quem recebe o acerto), `Enviado por {nome}` ao lado de "Comprovante enviado", e as saídas de quem ele paga logo depois das dele.
  - **Limite conhecido** (D6 da feature): só a tela Pagamentos do grupo enxerga quem paga por quem; o Painel pessoal e `GET /me/overview` seguem assumindo o usuário logado como devedor de tudo.

### 3.4 Despesas
- `POST /expenses` (`ExpenseController::store`, autenticado): cria a despesa, associa `payers` (sem desassociar antigos — `syncWithoutDetaching`) e cria as `Quota`s recebidas do cliente (o front já calcula datas/valores de cada parcela e envia pronto).
- `GET /groups/{groupId}/expenses/monthly`: soma total de despesas por mês/ano de um grupo.
- As demais operações do `apiResource('expenses', ...)` (`index`, `show`, `update`, `destroy`) estão **registradas na rota mas não implementadas** no controller — chamada quebra hoje.
- Divisão do valor: `valor_da_parcela / quantidade_de_participantes_em_payers` — igualitária, sem peso por pessoa.

### 3.5 Relatórios
- `GET /groups/{groupId}/expenses/report/{year}` — para cada despesa do grupo, projeta as parcelas a partir do mês seguinte ao pagamento, monta lista mensal de despesas e um `receivedSummary` (quanto cada participante deve ao pagador, excluindo o próprio pagador). O fechamento anual líquido (`finalSettlement`) está **implementado mas comentado/desativado** no código.
- `GET /group/{groupId}/report-monthly/{year}` — mesma lógica, mas calcula e retorna `finalSettlement` **por mês** (líquido entre pares de pessoas, mês a mês).
- Esses relatórios calculam "quem deve quanto" **em memória, a cada requisição**, a partir de `Expense.payers` + `Expense.payer` + `Quota.value_quota` — não leem nem escrevem `Participation`.

### 3.6 Pix
- `GET /pix/generate?email=...&valor=...` — gera QR Code + "copia e cola" Pix para a chave cadastrada do usuário do e-mail informado. **Hoje esta rota é pública** (fora do grupo `jwt.auth`) — ver `00-constitution.md`, Governança/Segurança.

### 3.7 Telas do frontend web atual (`expense/frontend/src/pages`)
`LoginPage`, `RegisterPage` (`/cadastro`, auto-cadastro em duas etapas), `Dashboard`, `GroupList`, `GroupForm`, `GroupMembersForm`, `ExpenseManager`, com navegação em `Navbar`/`InternalLayout`. Este é o conjunto de telas que servia de referência para o plano de migração para React Native (`02-plan.md`, superado pelo `decisions/ADR-010`: o app móvel passa a ser em Flutter).

## 4. Regras de negócio confirmadas

1. Uma despesa pertence a um grupo, tem um criador (`user_creator_id`) e um pagador (`user_payer_id`) — que podem ser pessoas diferentes.
2. Uma despesa é `IN_CASH` (1 parcela) ou `IN_INSTALLMENTS` (N parcelas, N informado pelo cliente).
3. O valor é dividido **igualmente** entre os `payers` da despesa (não necessariamente todos os membros do grupo).
4. Relatórios de "quem deve a quem" excluem o próprio pagador da lista de devedores dele mesmo.
5. Grupo e despesa nunca são apagados fisicamente por essas rotas — apenas marcados `deleted = true`.
6. **Só o devedor escolhe quem paga por ele.** Por grupo, nenhuma, uma ou várias pessoas do mesmo grupo, nunca ele mesmo. Quem é escolhido só pode enviar o comprovante dos acertos dele; o acerto continua do devedor, e `confirmed_by` registra quem enviou.

## 5. Fora de escopo hoje / backlog de negócio

- Pesos personalizados por membro na divisão de despesa (hoje é sempre igualitário).
- Persistir de fato quem já pagou sua parte (`Participation`/`Quota.paid` não são atualizados por nenhum endpoint após a criação — não há "marcar como pago").
- Notificações (e-mail além de convite/reset, WhatsApp, push).
- Fechamento anual líquido (`finalSettlement` anual está no código mas desligado).
- Unificar os dois fluxos de convite (3.2) em um só.

## 6. Divergências entre os READMEs antigos e o sistema real

| README dizia | Sistema real |
|---|---|
| `GrupoPagadores` como entidade própria, subconjunto de `MembroGrupo` | Não existe — pagadores/participantes são anexados direto na `Expense` via `ex_expenses_payers`. |
| `MembroGrupo` com `data_entrada`, `status` | Pivot `ex_groups_members` é simples (sem metadados extras hoje). |
| `Participacao` com `status: PENDENTE\|PAGO` controlando pagamento | Tabela/model existem, mas **nenhum código popula ou atualiza** `Participation` — o "quem deve quanto" é só calculado on-the-fly nos relatórios (seção 3.5), sem persistência de status de pagamento. |

Essas divergências não são bugs a corrigir silenciosamente — são decisões de produto a serem tomadas conscientemente (retomar o modelo antigo? formalizar o modelo atual?) e vão para `03-tasks.md` como itens de backlog, não como correção automática.
