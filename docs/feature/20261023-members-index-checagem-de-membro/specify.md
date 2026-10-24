# Specify — Checagem de membro na lista de membros do grupo

> Feature: `GET /api/groups/{groupId}/members` passa a responder **só a quem é membro do grupo**; hoje devolve e-mail, Pix e WhatsApp de todos os membros de qualquer grupo a qualquer usuário autenticado. Origem: item **085** do backlog (achado X1 da revisão de segurança da TASK-414 da feature `20261010-painel-financeiro-pessoal`), promovido em 2026-10-23 por pedido do usuário, com escopo **só o 085** (o 086 e o 060 ficam no backlog).

Versão: 1.0 · Criado em: 20261023

---

## 1. Problema

É vazamento de dado pessoal e de dado de cobrança (Pix) entre contas, com a exploração mais simples possível: um token válido e um `id` de grupo. O `id` é numérico e sequencial, então enumerar é trivial. Quem tem uma conta recebe a lista de e-mails, Pix e WhatsApp dos participantes de **todos** os grupos. O backend já está em produção, e o risco cresce com a base de usuários.

Há ainda um efeito lateral: hoje um `id` que não existe devolve `404` e um que existe devolve `200`, o que confirma a existência de grupos a quem não pertence a eles.

A `00-constitution.md` §1 item 6 pede que toda rota autenticada defina "quem pode acessar o quê", e diz que isso ainda não vale em todos os endpoints: "é dívida a resolver, não padrão a repetir". Este item é um caso dessa dívida que a Constitution não lista (a lista de achados da §5 item 3 cita só `GroupController@show/update/destroy`, `GET /pix/generate` e o log de credenciais).

Origem e reprodução: `docs/backlog/concluidos/group-members-index-sem-checagem-de-membro.md` (o item foi para `concluidos/` ao abrir o PR; ver a seção Resolução dele). Foi reproduzido em 2026-10-14 com uma sonda descartável: um usuário que não é membro do grupo `G` chama o endpoint com o próprio token e recebe `200` com os dados de cada membro. A reprodução será refeita como teste de regressão na primeira task (RED antes do conserto).

## 2. Achados confirmados (lidos no código em 2026-10-23, `origin/dev` em `e426f86914`)

### 2.1 `GroupMemberController::index()` não autoriza

`backend/app/Http/Controllers/GroupMemberController.php:17-26`: só faz `Group::with('members')->findOrFail($groupId)` e devolve `response()->json($group->members)`. Não chama nenhuma checagem de membro. A rota é `GET /groups/{groupId}/members` (`backend/routes/api.php:55`), dentro do grupo `jwt.auth`, que só garante que o usuário está **autenticado**.

O que sai: os models `User` serializados, isto é, tudo que **não** está em `$hidden` (`backend/app/Models/User.php:36-41`: `password`, `remember_token`, `google_id`, `photo_path`). Entre os campos expostos: `name`, `email`, `pix`, `whatsapp`, `notify_whatsapp`, `role`, `invited_by`, `email_verified_at` e `avatar_url` (accessor), mais o `pivot`.

### 2.2 O próprio controller já define o padrão correto

No `store()` da mesma classe (linhas 36-42): `Group::findOrFail`, `$this->authorizeMembership($group)` e `abort_if($group->deleted, 404)`. O helper (`GroupMemberController.php:94-99`) devolve **`404`, não `403`**, "para não confirmar a existência do grupo a quem não é membro". `GroupController::show/update/destroy` e `ExpenseController`/`GroupExpenseReportController` (via `Controller::authorizeGroupMembership`, `Controller.php:17`) seguem a mesma convenção. Existem **três cópias** desse helper (`Controller.php:17`, `GroupMemberController.php:94`, `GroupController.php:136`), com o mesmo corpo; unificá-las não é desta feature.

### 2.3 A lacuna é só no `index`

Auditoria das rotas autenticadas por grupo (`backend/routes/api.php:35-78`): `GroupController` (`show`, `update`, `destroy`), as rotas de despesas (`ExpenseController`), os relatórios (`GroupExpenseReportController`, linhas 22 e 114) e `GET /pix/generate` (`PixController`, exige grupo em comum) conferem membro. `GroupMemberController::store` também. **Só o `index` não confere.** (A rota `DELETE /groups/{groupId}/members/{userId}` aponta para um `destroy` que não existe; é do item 086 e a Constitution §2 item 4 já a registra.)

### 2.4 Consumidores e testes

- Os quatro consumidores no web só chamam o endpoint em telas de um grupo que o usuário abriu como membro: `ExpenseView.tsx:204`, `ExpenseForm.tsx:52`, `GroupMembersForm.tsx:47` e `:73`, `Payments.tsx:65`. Nenhum outro arquivo de `frontend/src` (fora de testes) chama `/members`; a tela de convite (`AcceptInvitePage.tsx`) usa `POST /api/invitations/verify`, não este endpoint.
- **Não existe nenhum teste do `index`**: `backend/tests/Feature/GroupMemberControllerTest.php` tem só 3 testes, todos de `store()` (`test_member_can_add_member_to_group`, `test_non_member_cannot_add_member_to_group`, `test_cannot_add_member_to_deleted_group`).

### 2.5 Reads e writes tratam grupo excluído de formas diferentes (importa para a decisão D1)

Em todo o backend, **escrita** em grupo excluído logicamente dá `404` (`GroupMemberController::store`, `ExpenseController::close` e `reopen`: `abort_if($group->deleted, 404)`), mas **leitura** não confere `deleted` (`GroupController::show`, `ExpenseController::indexByGroup`, `getMonthlyExpenses` e `summary`). Hoje o `index` de membros também não confere, e o item 085 recomendava passar a conferir.

## 3. Regras adotadas (R1 a R6 não dependem de decisão; R7 a R9 vêm das decisões do §4)

| # | Regra |
|---|---|
| R1 | Quem não é membro recebe **`404`**, a mesma convenção do `store()` (não `403`). |
| R2 | Grupo **inexistente** e grupo **existente do qual o usuário não é membro** devem ser **indistinguíveis**: mesmo status **e mesmo corpo** da resposta. Sem isso a checagem continuaria confirmando a existência. Provado por teste que compara os dois casos. |
| R3 | O payload para quem **é** membro **não muda** (Constitution §4.1: contrato aditivo; o web e o futuro app consomem). Só muda quem recebe. |
| R4 | Teste de regressão: não membro recebe `404`; membro recebe `200` com a lista; grupo inexistente e não membro são indistinguíveis. **Mutação:** remover a checagem derruba o teste do não membro. |
| R5 | O agent `security-reviewer` revisa a rota alterada antes da integração (`.claude/agents/`; Constitution §5.2). |
| R6 | Sem migration, sem mudança de frontend, sem evento de analytics. Só backend e testes. |
| R7 | **(D1, decidida pelo usuário em 2026-10-23: opção B.)** Grupo excluído logicamente continua devolvendo `200` a quem **é membro**, como as demais leituras (`show`, `indexByGroup`, `summary`). O `index` **não** ganha `abort_if($group->deleted, 404)`. Um teste trava isso, para a regra não mudar por acidente. O que o item 085 recomendava (opção A) fica descartado: ver o §2.5. |
| R8 | **(D2, aprovada em 2026-10-23: opção A.)** Os campos devolvidos a quem **é** membro ficam **como hoje** (inclui `email`, `pix`, `whatsapp`). A minimização é do item 091. |
| R9 | **(D3, aprovada em 2026-10-23: opção A.)** O `index` usa o `authorizeMembership` que o `GroupMemberController` **já tem** (`:94`). O `store()` não é tocado, e as três cópias do helper não são unificadas aqui. |

## 4. Decisões (todas tomadas; viram as regras R7 a R9)

Specify **aprovado pelo usuário em 2026-10-23**, com D1 = B, D2 = A e D3 = A.

| # | Decisão | Opções consideradas | Escolha | Por quê |
|---|---|---|---|---|
| D1 | Grupo **excluído logicamente** (`deleted = 1`) no `index` | A) `404` também para membros, como o `store()` (o que o item 085 recomendava)<br>B) mantém `200` para membros, como as demais leituras | **B** (R7) | Toda leitura de grupo excluído devolve `200` hoje (`show`, `indexByGroup`, `summary`; §2.5), e só as escritas dão `404`. O vazamento é entre contas, não de grupo excluído; mudar só o `index` abriria uma exceção sem ganho de segurança e pode quebrar uma tela de histórico. |
| D2 | Campos devolvidos a quem **é** membro | A) manter como hoje<br>B) devolver só o necessário a cada tela | **A** (R8) | B muda contrato e exige varrer os quatro consumidores. A minimização é do item 091, junto do app. Esta feature fecha só **quem** recebe. |
| D3 | Reuso da checagem | A) usar o `authorizeMembership` que o controller já tem<br>B) trocar pelo `Controller::authorizeGroupMembership` herdado e remover a cópia | **A** (R9) | Menor mudança, sem tocar `store()`. Unificar as três cópias do helper é refatoração à parte e não precisa vir junto de uma correção de segurança. |

## 5. Fora de escopo desta feature

- **Item 086:** consentimento de quem é adicionado, rota de saída e `GroupMemberController::destroy`. É produto novo e tem decisões abertas. Esta feature não mexe em `store()`.
- **Item 060:** `Log::info` do `User` inteiro em `GroupController::store`.
- **Item 091:** minimizar e-mail e Pix do credor no `/me/overview` e trocar o lookup de `GET /pix/generate` por `user_id`.
- **Item 095:** `GroupController::destroy` apagar fisicamente o grupo a pedido de qualquer membro.
- **Unificar as três cópias do helper de membro** (`Controller.php`, `GroupMemberController.php`, `GroupController.php`).
- **Remediar o que já vazou:** esta feature fecha a porta. Não investiga acessos passados nem avisa usuários.
- **Frontend e contrato:** nenhuma tela muda; o payload de membro é o mesmo.
- **Limite de chamadas por rota:** o limiter global de 60 por minuto continua valendo; não há limiter novo.
