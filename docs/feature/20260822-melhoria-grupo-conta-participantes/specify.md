# Specify — Meus Grupos: renomear rota e exclusão de grupo com preservação de histórico

> Feature: renomeia a URL da listagem de grupos de `/dashboard` para `/meus-grupos` (mantendo o título "Meus Grupos" na UI) e introduz exclusão de grupo com dois comportamentos distintos conforme o histórico financeiro do grupo (física quando nunca houve competência fechada, lógica com bloqueio de novas escritas quando já houve). Pedido novo, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

### 1.1 URL da listagem de grupos

A listagem "Meus Grupos" vive hoje na rota `/dashboard` (`frontend/src/App.tsx:38`, `<Route path="/dashboard" element={<Dashboard />} />`), um nome que não reflete mais a função da página — o título visual já é "Meus Grupos" desde a feature `docs/feature/20260820-melhoria-tela-grupos` (confirmado pelo teste `frontend/src/layouts/SimpleShellLayout.test.tsx:119`, que verifica o heading "Meus Grupos" na rota `/dashboard`). O caminho `/dashboard` ficou como resíduo do nome antigo da página.

Existe também uma rota de **backend** `GET /api/dashboard` (`backend/routes/api.php:21`, `AuthController::dashboard`) — endpoint de API sem relação com a rota de frontend `/dashboard`; não deve ser confundido nem afetado por esta mudança (ver §3).

### 1.2 Exclusão de grupo

Hoje `GroupController@destroy` (`backend/app/Http/Controllers/GroupController.php:97-105`) faz **sempre** o mesmo tipo de exclusão — lógica (`$group->update(['deleted' => true])`) — independentemente de o grupo já ter tido alguma competência fechada ou não. Não existe hoje nenhum caminho de exclusão física.

Além disso, a exclusão lógica atual tem uma lacuna: marcar `deleted = true` já faz o grupo sumir de "Meus Grupos" (o filtro `Group::where('deleted', 0)` em `GroupController@index`, `backend/app/Http/Controllers/GroupController.php:18`), mas **não bloqueia nenhuma escrita nova** sobre esse grupo:

- `GroupMemberController@store` (`backend/app/Http/Controllers/GroupMemberController.php:27-77`) não verifica `$group->deleted` antes de adicionar um novo participante.
- As rotas de despesas do grupo (`POST /groups/{groupId}/expenses/close`, `ExpenseController@close`, `backend/app/Http/Controllers/ExpenseController.php:425-460`, e a criação de despesas via `apiResource('expenses', ...)`) também não checam `Group.deleted` — os únicos filtros `where('deleted', ...)` existentes em `ExpenseController.php` são sobre `Expense.deleted`, nunca sobre o grupo.

Ou seja: hoje é possível continuar adicionando membros, despesas e fechando competências num grupo já "excluído".

O sistema já tem o conceito de competência (ciclo de fechamento) via `BillingCycle` (`backend/app/Support/BillingCycle.php`) e fechamento manual persistido em `GroupCycleSnapshot`/`ex_group_cycle_snapshots` (`backend/app/Models/GroupCycleSnapshot.php`, populado em `ExpenseController@close`, `backend/app/Http/Controllers/ExpenseController.php:425-452`). A passagem automática da data de fechamento (`BillingCycle::statusFor` retornando `closed`) é só um status calculado on-the-fly — **não gera nenhum registro persistido**. O único evento de "fechamento" que fica gravado no banco é a chamada manual a `close()`, que cria/atualiza uma linha em `ex_group_cycle_snapshots`.

**Leitura técnica adotada aqui** (a confirmar em `plan.md`): "grupo com competência fechada" = existe pelo menos uma linha em `ex_group_cycle_snapshots` para aquele `group_id`. Um grupo cuja competência apenas "venceu" pela data (status `closed` calculado por `BillingCycle`), sem nunca ter passado por `close()`, conta como **sem** competência fechada para efeito desta regra, pois nenhum fechamento foi de fato registrado.

## 2. Requisitos

### 2.1 Renomear rota de `/dashboard` para `/meus-grupos`

- Rota principal: `frontend/src/App.tsx:38` passa a registrar `/meus-grupos` (elemento `Dashboard`, sem renomear o componente/arquivo `Dashboard.tsx` — troca é só de path).
- Manter uma rota `/dashboard` que redireciona para `/meus-grupos` (compatibilidade com links/favoritos antigos), sem duplicar a página — coerente com a preferência do pedido original de não manter duas páginas independentes.
- Título visual da página continua "Meus Grupos" — nenhuma mudança de copy.
- Referências internas a atualizar para apontar para `/meus-grupos`:
  - Menu lateral: `frontend/src/layouts/accountSettingsNavItems.ts:8` (`{ label: 'Meus Grupos', ..., to: '/dashboard' }`).
  - Navegação pós-login: `frontend/src/pages/LoginPage.tsx:38` (`navigate('/dashboard')`).
  - Retorno/cancelamento em formulários de grupo: `frontend/src/pages/GroupForm.tsx:84` e `:150`, `frontend/src/pages/GroupMembersForm.tsx:160`.
  - Componente `frontend/src/components/Navbar.tsx:16` e `:20` — usado por `frontend/src/layouts/InternalLayout.tsx`, que **não aparece registrado em nenhuma rota de `frontend/src/App.tsx`** (grep não encontrou `InternalLayout` no arquivo de rotas); confirmar se é código morto antes de decidir se atualiza ou remove (ver §3).
- Testes que hoje fixam `/dashboard` como path e precisam ser revistos: `frontend/src/layouts/SimpleShellLayout.test.tsx` (múltiplas ocorrências, inclusive a asserção de `href` do link "Meus Grupos" e do heading), `frontend/src/components/RequireAuth.test.tsx`, `frontend/src/layouts/GroupShellLayout.test.tsx:85`, `frontend/src/layouts/Sidebar.test.tsx` (usa `/dashboard` como exemplo genérico de `to`, avaliar se precisa mudar ou é só fixture arbitrária), `frontend/src/pages/Dashboard.test.tsx` (confirmar se referencia o path ou só renderiza o componente isolado).

### 2.2 Exclusão física de grupo sem competência fechada

- Aplica-se quando não existe nenhuma linha em `ex_group_cycle_snapshots` para o grupo (ver leitura técnica em §1.2).
- Front-end: exige confirmação via modal antes de excluir. Já existe um `Dialog` de confirmação em `frontend/src/pages/Dashboard.tsx:215-234` acionado por `handleDeleteGroup` (`Dashboard.tsx:93-110`) — hoje ele dispara sempre o mesmo `DELETE /api/groups/:id`, sem diferenciar física de lógica; a copy do modal deve deixar claro quando a exclusão é definitiva.
- Back-end: `GroupController@destroy` precisa passar a remover fisicamente o grupo e os registros dependentes quando a condição acima é satisfeita — hoje ele nunca faz isso (§1.2). Registros a considerar (relações declaradas em `backend/app/Models/Group.php:28-46`): despesas do grupo (`ex_expenses`, incluindo `ex_expenses_payers` e `ex_quotas` associados a cada despesa — ver `backend/app/Models/Expense.php:60-68`), participações (`ex_participations`, `backend/app/Models/Participation.php`), e membros (pivot `ex_groups_members`).
- Validação da condição (ausência de competência fechada) deve existir no backend, não só no frontend — regra explícita do `CLAUDE.md` raiz.

### 2.3 Exclusão lógica de grupo com competência fechada

- Aplica-se quando existe pelo menos uma linha em `ex_group_cycle_snapshots` para o grupo.
- Mantém o comportamento atual de `GroupController@destroy` (marcar `deleted = true`, sem apagar nada) — já preserva despesas, participantes, fechamentos e saldos automaticamente, por nunca tocar nessas linhas.
- Grupo já some da listagem "Meus Grupos" hoje (`GroupController@index`, `backend/app/Http/Controllers/GroupController.php:18`, filtro `deleted = 0`) — este comportamento já está correto e não muda.
- Gap a fechar (novidade desta feature): depois da exclusão lógica, o grupo não pode mais aceitar novas despesas, novos participantes, nem novos fechamentos de competência. Isso exige adicionar checagem de `Group.deleted` (hoje ausente, ver §1.2) em:
  - `GroupMemberController@store` (`backend/app/Http/Controllers/GroupMemberController.php:27`).
  - Criação de despesas para o grupo e `ExpenseController@close`/`@reopen` (`backend/app/Http/Controllers/ExpenseController.php:425`, `:470`, mais o `store` de despesas).
- Confirmação via modal antes de excluir: mesmo modal do §2.2 (`Dashboard.tsx:215-234`), com texto adaptado para deixar claro que é uma exclusão que preserva o histórico, não uma remoção definitiva de dados.

## 3. Fora de escopo desta feature

- `GET /api/dashboard` (`backend/routes/api.php:21`, `AuthController::dashboard`) — endpoint de backend sem relação com a rota de frontend renomeada; não é tocado.
- `frontend/src/components/Navbar.tsx` / `frontend/src/layouts/InternalLayout.tsx` — parecem não estar em uso nas rotas atuais (`App.tsx`); decidir se é código morto a remover fica fora desta feature (registrar em `docs/backlog/` se confirmado, não decidir aqui).
- Restringir quem pode excluir o grupo (hoje `authorizeMembership` em `GroupController.php:110-115` permite a qualquer membro chamar `destroy`, não só ao criador) — o pedido não menciona essa mudança; manter o comportamento atual de autorização (qualquer membro pode excluir), a menos que o usuário confirme que também quer restringir a criador.
- Cancelamento de convites pendentes, remoção de configuração de Pix do grupo, ou qualquer outro efeito colateral da exclusão além do listado em §2.2/§2.3 — não mencionado no pedido original.
- Backfill ou correção de grupos já hoje marcados como `deleted = true` sob a regra antiga (sem bloqueio de escrita) — rodar esse tipo de ajuste em ambiente compartilhado/produção é gate humano explícito (`CLAUDE.md`), fora do escopo autônomo desta feature.
- Qualquer alteração ao menu lateral além de apontar o link "Meus Grupos" para a nova URL (§2.1) — o posicionamento do item dentro de "Configurações" já foi resolvido pela feature `docs/feature/20260820-melhoria-tela-grupos` e não muda aqui.
