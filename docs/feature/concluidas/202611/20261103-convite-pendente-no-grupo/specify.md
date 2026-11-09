# Specify — Convite Pendente com Aceite ou Recusa para Entrar no Grupo

> Feature: a entrada num grupo passa a depender do **aceite** de quem é convidado. Hoje qualquer membro coloca no grupo qualquer pessoa cadastrada, só pelo e-mail, e a pessoa vira membro na hora. Promove o item 133 do backlog (a D1-B que a feature `20261030-saida-e-remocao-de-membro`, PR #262, deixou como candidata a backlog).

Versão: 1.1 · Criado em: 20261103

**v1.1 (2026-11-03):** acrescenta a **D10** e o **RK9**, decididos pelo usuário depois da revisão de segurança da TASK-576. Nenhuma das D1 a D9 mudou.

---

## 1. Problema

`GroupMemberController::store` faz o `attach` direto: quem é adicionado já é membro quando fica sabendo, sem aceitar nem recusar. Desde o PR #262 (item 086) a pessoa consegue **sair** e o criador consegue **remover**, mas a **entrada** continua sem consentimento, e a saída tem bloqueios (D3-B daquela feature): quem é participante de despesa fixa ativa, ou credor de parcela em aberto de outra pessoa, não sai enquanto isso valer. Por isso um membro mal-intencionado ainda consegue prender alguém: adiciona a pessoa, cadastra uma fixa com ela como participante, e ela só sai quando a fixa for interrompida (o RK1 do specify da feature 086, registrado ali como limite conhecido com esta feature como correção completa).

Há também o lado de privacidade (LGPD): o e-mail e o contato de quem nunca escolheu estar no grupo passam a ser vistos por todos os membros.

O objetivo é fechar a entrada: **só entra num grupo quem aceita um convite.** A urgência é menor que a do item 086 original (por isso a prioridade `MEDIA` do backlog), porque agora existe saída e remoção.

### 1.1 Verificação prévia

Li o código listado abaixo, sem rodar nada: o achado é de leitura, e os testes existentes (`GroupMemberControllerTest`, `NotifierTriggersTest`) já fixam o comportamento atual (adição direta). Nenhum fluxo foi reproduzido em navegador.

- Backend: `GroupMemberController` (`store`, `index`, `destroy`), `GroupController` (`index`, `store`), `InvitationController::verify`, `UserInvitedMail` e a view `email/invitation`, `Notifier::groupMemberAdded`, o modelo `Notification` e as migrations de `ex_groups_members` e `ex_users`, `AuthController::login`, `PreRegisterRequest`, `routes/api.php`.
- Frontend: `GroupMembersForm`, `AcceptInvitePage`, `NotificationsMenu`, `notificationText`, `Dashboard` (Meus Grupos) e `App.tsx`.
- Documentação: o specify da feature `20260822-atualizacao-participantes` (que unificou os dois fluxos de convite) e a Constitution (§4, §5.2, §6).

## 2. Achados confirmados

### 2.1 A entrada é um `attach` direto, nos dois casos

`store()` (`backend/app/Http/Controllers/GroupMemberController.php:32-92`): com e-mail que já tem conta, faz o `attach` (`:82`) e chama `Notifier::groupMemberAdded` (`:86`); com e-mail sem conta, cria o `User` (`:57`), grava o token `invitation-token:<e-mail>` no cache por 2 dias (`:67`), envia o `UserInvitedMail` (`:71`) e **também** faz o `attach`. Quem já é membro recebe 409 (`:75-78`). A resposta é 201 e a mensagem difere conforme o caso (`:90-91`).

### 2.2 Só dois lugares inserem em `ex_groups_members`

`GroupController::store` (`:80`, o criador entra ao criar o grupo) e `GroupMemberController::store` (`:82`). Exigir aceite é, portanto, mudar um único ponto; o criador continua entrando sem convite.

### 2.3 O convite por e-mail existe, mas só ativa a conta

O link do e-mail (`UserInvitedMail.php:33`) leva a `/app/aceitar-convite?email=…&token=…`. A página (`AcceptInvitePage.tsx`) chama `POST /api/invitations/verify` (pública, `InvitationController.php:15`), que define a senha, preenche `email_verified_at` (`:41`) e consome o token (`:49`). Em nenhum momento pergunta sobre o grupo, porque a pessoa já está nele.

### 2.4 A conta nascida de um convite fica num limbo

`PreRegisterRequest.php:26` (`unique:ex_users,email`, mensagem em `:41`) recusa o auto-cadastro com esse e-mail ("Este e-mail já está cadastrado."), e o login recusa quem não verificou o e-mail (`AuthController.php:36-41`, "E-mail não verificado. Use "Esqueci minha senha"…"). A conta é criada a partir de um dado digitado por outra pessoa, sem a anuência do dono do e-mail. Isso já é assim hoje; esta feature não piora e, na D2-A, também não resolve.

### 2.5 O `store()` revela se um e-mail tem conta

As duas mensagens de sucesso são diferentes ("Usuário criado e convidado com sucesso." e "Membro adicionado com sucesso.", `:90-91`): qualquer membro descobre quem já usa o sistema testando e-mails. Uma resposta uniforme (R4) fecha isso junto.

### 2.6 A notificação de adição leva o convidado a uma tela que ele não poderá abrir

`Notifier::groupMemberAdded` (`backend/app/Support/Notifier.php:206-214`) grava `group_id` e `data.groupId`, e o sino navega para `/groups/{id}/summary` (`NotificationsMenu.tsx:96-98`). Para quem ainda não é membro essa rota dá 404. A feature 086 já enfrentou o mesmo com `group_member_removed`, que grava `group_id` nulo e deixa o clique só marcar como lido.

### 2.7 As listas de membros são só de membros, e assim devem continuar

`GET /groups` traz `members:id,name,email` (`GroupController.php:23`) e `GET /groups/{id}/members` lista `ex_groups_members`. Guardar o convite pendente no próprio pivô faria `authorizeMembership` e as consultas `whereHas('members')` tratarem o convidado como membro; por isso o convite fica fora do pivô (D1).

### 2.8 Nada limita convites, e o teto de 3 grupos só conta grupos criados

Não há `throttle` em `POST /groups/{id}/members` (item 128). O teto de 3 (`GroupController.php:63`, D4-A da feature 086) conta grupos **criados**; convite pendente não entra nele.

### 2.9 Testes que mudam por design

Dois testes assumem o `attach` direto: `GroupMemberControllerTest::test_member_can_add_member_to_group` (`:34`) e `NotifierTriggersTest::test_group_member_added_notifies_the_new_member_only` (`:352`). Os demais seguem valendo: 404 de não membro e de grupo excluído e 409 de já membro (`GroupMemberControllerTest`, `NotifierTriggersTest::...already_a_member`). Nenhum outro teste usa `POST /members` para montar cenário. No frontend, `GroupMembersForm.test.tsx` só afirma que o `POST` é feito.

## 3. Requisitos

### 3.1 O que a feature entrega

- `POST /groups/{groupId}/members` deixa de adicionar: cria (ou renova) um **convite pendente** e avisa o convidado. Ninguém vira membro sem aceitar.
- O convidado vê os convites que recebeu e **aceita** (vira membro) ou **recusa**.
- Quem convidou, e o criador do grupo, podem **cancelar** um convite pendente; os membros veem a lista de pendentes do grupo.
- Notificações: convite recebido (ao convidado) e convite aceito (a quem convidou).
- Web: convites recebidos em Meus Grupos, pendentes e cancelamento em Participantes, textos novos no sino e o e-mail de convite ajustado.

### 3.2 Regras adotadas

Não dependem de decisão; as que dependem estão em §3.4.

| # | Regra |
|---|---|
| R1 | **Entrada só por convite aceito.** A única exceção é o criador, que entra ao criar o grupo (`GroupController::store`). Nenhum outro caminho insere em `ex_groups_members`. |
| R2 | `POST /groups/{groupId}/members` mantém as checagens de hoje (membro autenticado; 404 para não membro e para grupo excluído; 409 se o e-mail já é de um membro) e passa a **criar um convite pendente**: grupo, convidado, quem convidou e validade. Não faz `attach`. |
| R3 | **Um convite por (grupo, pessoa).** Convidar de novo quem já tem convite pendente não duplica: renova a validade (D5). |
| R4 | **Resposta uniforme.** A mesma mensagem e as mesmas chaves para e-mail com ou sem conta, o que fecha o achado 2.5. |
| R5 | E-mail **sem conta**: como hoje (cria o `User`, token de 2 dias, `UserInvitedMail`, com o texto trocado para falar em convite pendente; D2). A pessoa ativa a conta, entra e aceita depois. |
| R6 | Convidado **com conta** recebe a notificação in-app `group_invitation`, **sem** `group_id` e **sem** `data.groupId`, para o sino não navegar para o grupo; o clique leva a Meus Grupos (§3.6). |
| R7 | **Aceitar:** só o próprio convidado, convite pendente e não expirado, grupo não excluído. Numa transação: `attach`, marca aceito e avisa quem convidou (`group_invitation_accepted`). Convite de outra pessoa, expirado, já respondido ou de grupo excluído dá o **mesmo 404**. |
| R8 | **Recusar:** só o próprio convidado; marca recusado; não cria nada e não avisa ninguém (D6). Repetir dá 404. |
| R9 | **Cancelar:** quem convidou ou o criador do grupo, só enquanto pendente (D7). Membro comum que não convidou recebe 403; quem não é membro, 404. |
| R10 | O convidado pendente **não é membro**: `authorizeMembership` segue dando 404 em todas as rotas do grupo. Do grupo ele só vê o que está no próprio convite (nome do grupo e quem convidou), nunca membros, despesas nem saldos. |
| R11 | Convite pendente não aparece em `members` de `GET /groups` nem em `GET /groups/{id}/members`, e não conta para o teto de 3 grupos criados. |
| R12 | O convite tem validade (D4), calculada na leitura, sem job: expirado não aceita e some das listas. |
| R13 | Quem saiu ou foi removido do grupo pode ser convidado de novo; voltar exige aceite. |
| R14 | Quem já é membro hoje **continua** membro: nenhuma migração de dados de membros, ninguém é removido nem reconvidado por esta feature. |
| R15 | Falha de notificação ou de e-mail não pode deixar convite órfão nem derrubar a resposta (best effort, como o `Notifier::guard` de hoje). |

### 3.3 Contrato da API (proposta; o Plan fecha os nomes)

Rotas novas, aditivas, sob `jwt.auth`. O `store()` mantém a rota, o 201 e o `message`, e ganha `pending` e `invitation` (D8).

| Rota | Quem | Efeito |
|---|---|---|
| `POST /groups/{id}/members` (existente) | membro | cria ou renova o convite; 201 `{message, pending: true, invitation: {id, email, expires_at}}`; 409 se já é membro; 404 como hoje |
| `GET /groups/{id}/invitations` | membro | convites pendentes do grupo: `{id, email, invited_by: {id, name}, expires_at}` |
| `DELETE /groups/{id}/invitations/{invitationId}` | quem convidou ou o criador | cancela um convite pendente |
| `GET /me/invitations` | o próprio usuário | convites que ele recebeu: `{id, group: {id, name}, invited_by: {id, name}, expires_at}` |
| `POST /me/invitations/{id}/accept` | o convidado | aceita; vira membro |
| `POST /me/invitations/{id}/decline` | o convidado | recusa |

- **Notificações novas:** `group_invitation` (ao convidado, sem `group_id`) e `group_invitation_accepted` (a quem convidou, com `groupId`, porque quem convidou é membro). `group_member_added` deixa de ser emitida; as já gravadas continuam com o texto de hoje.
- **Migration:** uma tabela nova `ex_group_invitations` (prefixo `ex_`, aditiva). Constitution §4, item 2: sem `drop`/`rename`. **Gate humano antes de rodar em banco compartilhado ou produção**, e atenção: o deploy aplica as migrations automaticamente ao mergear em `main` (ADR-008).
- **Constitution §4, item 1:** o shape é aditivo, mas o **sentido** do `store()` muda (201 deixa de significar "virou membro"). O único consumidor é a web, atualizada no mesmo PR; o app Flutter (`app/`) ainda não existe.

### 3.4 Decisões em aberto

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde guardar o convite | A) tabela nova `ex_group_invitations`<br>B) coluna `status` no pivô `ex_groups_members` | **A** | No B, `authorizeMembership` e as consultas `whereHas('members')` espalhadas pelo backend passariam a tratar o convidado como membro, e cada uma teria de filtrar o status: é a forma mais fácil de reabrir o vazamento que a feature 086 fechou. |
| D2 | E-mail **sem conta** | A) continua criando o `User` na hora, como hoje<br>B) só cria a conta quando a pessoa aceita | **A** | O B reescreve a ativação (nome, senha e aceite numa tela só) e muda o `AcceptInvitePage`. O limbo do achado 2.4 já existe hoje; fica como limite conhecido e candidato a backlog. O A mantém o `UserInvitedMail` e o `verify` como estão. |
| D3 | Onde o convidado responde | A) dentro do app: Meus Grupos e o sino, depois de entrar<br>B) também por botões no e-mail | **A** | O e-mail de hoje serve só para quem não tem conta e já leva à criação de senha. Aceitar por link no e-mail abriria uma rota pública que muda estado; o A mantém toda resposta atrás do `jwt.auth`. |
| D4 | Validade do convite | A) 7 dias<br>B) 2 dias, igual ao token do e-mail<br>C) sem validade | **A** | O B deixa o convidado novo sem convite se demorar a ativar a conta (ele ainda pode usar "Esqueci minha senha"). O C deixa convite esquecido valendo para sempre. Sete dias dá folga sem acumular lixo. |
| D5 | Convidar de novo quem já tem convite pendente | A) renova a validade, não duplica, e só reenvia o aviso se o último tiver mais de 24 h<br>B) devolve 409 | **A** | Quem digitou o e-mail errado ou quer lembrar a pessoa não fica preso a um erro, e não vira spam de notificação. |
| D6 | Quem é avisado da resposta | A) aceite avisa quem convidou; recusa não avisa ninguém<br>B) os dois avisam<br>C) ninguém | **A** | Quem convidou precisa saber que a pessoa entrou. Avisar a recusa expõe a escolha de quem recusou sem benefício claro. |
| D7 | Cancelar convite pendente | A) quem convidou ou o criador<br>B) deixar para depois | **A** | Custa pouco e corrige o convite para o e-mail errado sem esperar a validade acabar. |
| D8 | Contrato do `store()` | A) mantém a rota, 201 e `message`, acrescenta `pending` e `invitation`<br>B) rota nova de convite e o `store()` antigo passa a responder erro | **A** | O B quebra o único cliente sem benefício e deixaria a rota de adição direta existindo por um período. O A muda o sentido, mas o shape é aditivo e a web é atualizada junto (Constitution §4.1). |
| D9 | Reconvidar quem recusou | A) livre; o volume fica com o item 128 (throttle)<br>B) bloqueio de alguns dias | **A** | O B adiciona estado e regra para um abuso que o convite pendente já torna inofensivo (sem aceite nada acontece); o incômodo de volume é o item 128. |
| D10 | O convite continua válido depois que quem convidou sai ou é removido do grupo (achado A3 da revisão de segurança) | A) cancelar os convites vivos de quem sai ou é removido, na mesma transação do `destroy` de membro<br>B) o aceite recusa se quem convidou deixou de ser membro<br>C) manter o convite como está e registrar a decisão | **C** (decidido pelo usuário em 2026-11-03) | A autoridade de convidar vale no momento do convite, e o criador já cancela qualquer convite pendente pela lista (D7-A). A e B mexeriam no `destroy` de membro (feature 086) ou numa regra do aceite, e o usuário preferiu não ampliar o escopo. Fica como limite conhecido (RK9). |

### 3.5 Falhas e consistência

| # | Cenário | Tratamento |
|---|---|---|
| F1 | O grupo é excluído entre o convite e o aceite | Aceitar dá 404 (R7); o convite fica inerte |
| F2 | Aceite duplo, em duas abas ou duas requisições | Transação com `lockForUpdate` no convite; o segundo recebe 404; um só `attach` |
| F3 | O convidado já é membro quando aceita | 404 igual (convite já respondido ou membro) |
| F4 | Falha do `Notifier` ou do e-mail | Não desfaz o convite (R15); o plano define a ordem para não deixar convite sem aviso silencioso |
| F5 | Convidar o próprio e-mail, ou o de quem já é membro | 409 (já é membro) |
| F6 | Dois membros convidam a mesma pessoa | Um convite (R3); mantém o `invited_by` do primeiro e renova a validade |
| F7 | O grupo é excluído de vez (cascata) ou a conta do convidado é apagada | A FK em cascata remove os convites |
| F8 | O convidado recusa e é convidado de novo | Permitido (D9-A, R13) |

### 3.6 Interface (web)

- **Meus Grupos** (`Dashboard.tsx`): seção "Convites recebidos", com nome do grupo, quem convidou, **Aceitar** e **Recusar**; aceitar leva ao grupo.
- **Participantes** (`GroupMembersForm.tsx`): a mensagem de sucesso do formulário passa a dizer que o convite foi enviado, e entra uma lista "Convites pendentes" com **Cancelar** (para quem convidou e para o criador).
- **Sino** (`notificationText.ts`, `NotificationsMenu.tsx`): textos de `group_invitation` e `group_invitation_accepted`; o clique no convite recebido leva a Meus Grupos, e não a `/groups/{id}/summary`.
- **E-mail de convite** (`email/invitation.blade.php`): texto trocado para "convite pendente: crie sua senha, entre e aceite o convite".
- Sem rota nova de página no frontend.

## 4. Riscos

| # | Risco | Efeito | Tratamento |
|---|---|---|---|
| RK1 | Aceitar ou recusar convite alheio (IDOR) | Alguém entra no grupo de outro, ou cancela o convite de outro | R7, R8, R9 e testes de autorização; o `security-reviewer` revisa as rotas novas |
| RK2 | A migration roda automaticamente no deploy | Tabela nova em produção sem aval | Migration aditiva; gate humano antes de banco compartilhado; atenção ao ADR-008 no PR de promoção |
| RK3 | `store()` muda de sentido | Um cliente que presumia "201 = membro" erra | Só a web consome; atualizada no mesmo PR (Constitution §4.1) |
| RK4 | Convidado novo precisa de um passo a mais | Ativa a conta e ainda tem de aceitar | Texto do e-mail e do convite pendente deixam claro; a D3-A mantém o fluxo simples |
| RK5 | Contas em limbo continuam (achado 2.4) | Quem não ativa mantém a conta e bloqueia o auto-cadastro | D2-A, limite conhecido; candidato a backlog |
| RK6 | Spam de convites e de e-mails para endereços alheios | Incômodo de volume | Item 128 (throttle), fora de escopo; sem aceite nada acontece |
| RK7 | O e-mail do convidado fica visível aos membros na lista de pendentes | Dado pessoal exposto | É o e-mail que um membro digitou; só membros veem; o convidado vê só nome do grupo e de quem convidou (R10) |
| RK8 | Testes existentes que mudam | Regressão escondida | §2.9: só os dois listados mudam, e a mudança é por design; os demais ficam sem edição |
| RK9 | Um membro hostil convida cúmplices e depois sai ou é removido | O convite dele segue válido por até 7 dias e o convidado entra com acesso a despesas e saldos | D10-C: o criador vê a lista de pendentes (com quem convidou, em `GET /groups/{id}/invitations`) e cancela à mão; não há aviso automático. Limite conhecido, sem backlog aberto por decisão do usuário |

## 5. Critérios de aceite

### 5.1 Backend

- **CA-1**: `POST /groups/{id}/members` cria um convite e **não** um membro: 201 com `pending: true`; sem linha nova em `ex_groups_members`; o convidado não aparece em `GET /groups/{id}/members` nem em `members` de `GET /groups`; e o grupo não aparece em `GET /groups` do convidado.
- **CA-2**: resposta uniforme: e-mail com e sem conta devolvem a mesma mensagem e as mesmas chaves; 409 para quem já é membro e 404 para não membro e para grupo excluído, como hoje.
- **CA-3**: repetir o convite não duplica (uma linha por grupo e pessoa) e renova a validade; o aviso só é reenviado depois de 24 h (D5-A).
- **CA-4**: aceitar: só o convidado; vira membro; o convite fica aceito; quem convidou recebe `group_invitation_accepted`; outro usuário, convite expirado, já respondido ou de grupo excluído recebem o mesmo 404.
- **CA-5**: recusar: só o convidado; não vira membro; ninguém é avisado; repetir dá 404.
- **CA-6**: cancelar: quem convidou e o criador cancelam; membro comum que não convidou recebe 403; não membro, 404; convite já respondido, 404.
- **CA-7**: o convidado pendente leva 404 em todas as rotas do grupo, como qualquer não membro; `GET /me/invitations` devolve só as chaves combinadas, sem e-mail de outros membros, sem despesas e sem saldos.
- **CA-8**: convite expirado não aceita e não aparece nas listas.
- **CA-9**: o convidado com conta recebe `group_invitation` com `group_id` nulo e sem `data.groupId`; falha do `Notifier` não derruba a resposta.
- **CA-10**: e-mail sem conta mantém o fluxo de hoje (cria o `User`, token de 2 dias, `UserInvitedMail` com o texto novo) e **não** cria membro.
- **CA-11**: dois aceites simultâneos geram um único `attach`.
- **CA-12**: `pint --test` e a suíte inteira do backend verdes; a migration é só aditiva; o `security-reviewer` rodou nas rotas novas.
- **CA-13**: os únicos testes existentes alterados são os dois de §2.9; o resto da suíte fica sem edição.

### 5.2 Frontend

- **CA-14**: Meus Grupos mostra os convites recebidos com **Aceitar** e **Recusar**; aceitar leva ao grupo e recusar tira o convite da lista.
- **CA-15**: Participantes mostra a mensagem de convite enviado, a lista de pendentes e **Cancelar**.
- **CA-16**: o sino tem os textos novos, e o clique no convite recebido leva a Meus Grupos sem navegar para o grupo.
- **CA-17**: `npx tsc --noEmit` limpo e os testes Vitest dos arquivos tocados verdes; a suíte inteira do frontend só vale com `--maxWorkers=1` nesta máquina, conferindo que rodaram todos os arquivos.

### 5.3 Documentação

- **CA-18**: `docs/sdd/01-specify.md` §3.2 e §3.3 descrevem o convite pendente no lugar da adição direta. A Constitution, se algo nela ficar desatualizado, só é sinalizada ao usuário, nunca editada.

## 6. Fora de escopo desta feature

- **128**: `throttle` em `POST /groups` e `POST /groups/{id}/members` (segue no backlog, vale à parte).
- **D2-B**: criar a conta só quando a pessoa aceita, e a limpeza das contas em limbo (candidatos a backlog).
- Convite por link ou código compartilhável, e aceitar por botão dentro do e-mail (D3-B).
- E-mail de convite para quem **já** tem conta (hoje só há aviso in-app; continua assim).
- Job de expiração ou de limpeza de convites antigos (a validade é lida na consulta).
- Bloqueio de reconvite (D9-B) e reenvio manual do e-mail.
- Mudar `GroupController::store` (o criador continua entrando sem convite) e a saída e remoção de membro (feature 086).
- **095** (qualquer membro apaga o grupo em cascata) e **091** (oráculo de e-mail do `GET /pix/generate`), que seguem abertos.
- O app Flutter (`app/` ainda não existe).
