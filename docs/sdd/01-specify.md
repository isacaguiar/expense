# Specify — Controle de Despesas Compartilhadas

> Documento de negócio. Descreve o sistema **como ele existe hoje no código** (controllers, models, migrations, telas), não a visão aspiracional dos READMEs antigos. Serve de baseline para todo trabalho futuro: qualquer feature nova referencia uma seção daqui; qualquer mudança de comportamento atualiza este arquivo.
>
> Fontes usadas para levantar este documento: `backend/routes/api.php`, `backend/app/Models/*`, `backend/app/Http/Controllers/*`, `backend/database/migrations/*`, `frontend/src/pages/*`.

Versão: 1.0 · Última atualização: 2026-08-17

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
| `Participation` | `ex_participations` | `group_id`, `quota_id`, `state` | **Existe na migration/model mas não é populada por nenhum endpoint hoje** — ver seção 6, "Divergências". |

## 3. Fluxos implementados

### 3.1 Autenticação e conta
- `POST /register` — cria usuário (nome, e-mail, senha ≥6 chars). Público.
- `POST /login` — autentica via `tymon/jwt-auth`, retorna `access_token` (bearer) + TTL. Público.
- `GET /me`, `POST /logout`, `GET /dashboard` — autenticados.
- `POST /user/pix` — usuário autenticado define/atualiza a própria chave Pix.

### 3.2 Convite de usuário — **dois fluxos distintos e independentes**
1. `POST /invitations` (`InvitationController::invite`, autenticado): cria o `User` diretamente com senha temporária aleatória, envia e-mail com link contendo `email` + `token` em query string; `POST /invitations/verify` (público) valida o token (comparado por hash) e define a senha definitiva.
2. `POST /groups/{groupId}/members` (`GroupMemberController::store`, autenticado): se o e-mail informado não tem `User`, cria um novo com senha aleatória via `Str::random(10)`, gera token via `Password::getRepository()` e envia `UserInvitedMail` — fluxo de reset de senha padrão do Laravel, diferente do fluxo 1.
3. `POST /forgot-password` (público): gera token, guarda em cache por 60 min, limita a 1 pedido a cada 15 min, envia e-mail de recuperação.

> Os fluxos 1 e 2 resolvem o mesmo problema (convidar alguém novo) de formas diferentes e não compartilham código — ver "Divergências".

### 3.3 Grupos
- `GET/POST/PUT/DELETE /groups` (`GroupController`, autenticado, via `Route::apiResource`):
  - `index`: lista apenas grupos não deletados dos quais o usuário autenticado é membro.
  - `store`: cria grupo e associa automaticamente o criador como membro.
  - `show`/`update`/`destroy` (soft delete): **não verificam se o usuário autenticado é membro do grupo** (ver `00-constitution.md`, Segurança item 5).
- `GET/POST /groups/{groupId}/members`, `DELETE /groups/{groupId}/members/{userId}`: listar/adicionar membro (ou convidar, ver 3.2); a rota de remoção existe mas **o método `destroy` não está implementado** em `GroupMemberController` — chamada quebra hoje.

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
`LoginPage`, `Dashboard`, `GroupList`, `GroupForm`, `GroupMembersForm`, `ExpenseManager`, com navegação em `Navbar`/`InternalLayout`. Este é o conjunto de telas que serve de referência para o Plan de migração para React Native (`02-plan.md`).

## 4. Regras de negócio confirmadas

1. Uma despesa pertence a um grupo, tem um criador (`user_creator_id`) e um pagador (`user_payer_id`) — que podem ser pessoas diferentes.
2. Uma despesa é `IN_CASH` (1 parcela) ou `IN_INSTALLMENTS` (N parcelas, N informado pelo cliente).
3. O valor é dividido **igualmente** entre os `payers` da despesa (não necessariamente todos os membros do grupo).
4. Relatórios de "quem deve a quem" excluem o próprio pagador da lista de devedores dele mesmo.
5. Grupo e despesa nunca são apagados fisicamente por essas rotas — apenas marcados `deleted = true`.

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
