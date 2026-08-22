# Plan — Meus Grupos: renomear rota e exclusão de grupo com preservação de histórico

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Rota `/meus-grupos` (specify §2.1)

- `frontend/src/App.tsx:38`: troca o `path` da rota existente de `/dashboard` para `/meus-grupos`, mantendo `element={<Dashboard />}` — não renomear o arquivo/componente `Dashboard.tsx` (é só troca de path, renomear o arquivo é ruído de diff sem valor nesta feature).
- Adiciona uma rota de compatibilidade `/dashboard` no mesmo bloco (`<Route element={<SimpleShellLayout />}>`, `App.tsx:37-44`) usando `<Route path="/dashboard" element={<Navigate to="/meus-grupos" replace />} />` — evita duplicar a página, como pedido.
- Atualiza todos os pontos que hoje apontam para `/dashboard` como destino, para `/meus-grupos`:
  - `frontend/src/layouts/accountSettingsNavItems.ts:8` (item de menu "Meus Grupos").
  - `frontend/src/pages/LoginPage.tsx:38` (`navigate` pós-login).
  - `frontend/src/pages/GroupForm.tsx:84` e `:150` (voltar/cancelar).
  - `frontend/src/pages/GroupMembersForm.tsx:160` (voltar).
- Não mexe em `frontend/src/components/Navbar.tsx` nem em `frontend/src/layouts/InternalLayout.tsx` — confirmado fora de escopo (specify §3).
- Não mexe em `GET /api/dashboard` (`backend/routes/api.php:21`) — endpoint de backend não relacionado.
- Testes:
  - `frontend/src/layouts/SimpleShellLayout.test.tsx`: troca as ocorrências de `/dashboard` (rota registrada e `initialEntries`) para `/meus-grupos`; a asserção de `href` do link "Meus Grupos" (`:103`) passa a esperar `/meus-grupos`.
  - `frontend/src/components/RequireAuth.test.tsx`: troca `/dashboard` por `/meus-grupos` no `MemoryRouter`/`Route` de teste.
  - `frontend/src/layouts/GroupShellLayout.test.tsx:85`: mesma troca na asserção de `href`.
  - `frontend/src/pages/Dashboard.test.tsx`: revisar se referencia o path da rota (renderiza `<Dashboard />` isolado, fora de um `Router` com path fixo) — se só renderiza o componente, não precisa mudar.
  - `frontend/src/layouts/Sidebar.test.tsx`: **não muda** — usa `/dashboard` só como valor de exemplo genérico de `to` para testar o componente `Sidebar` isoladamente (não é uma rota real do app).
  - Adicionar um teste novo cobrindo o redirect `/dashboard` → `/meus-grupos` (ex.: em `SimpleShellLayout.test.tsx` ou um teste dedicado de `App.tsx`).

## 2. Critério de "competência fechada" e exclusão física vs. lógica (specify §2.2 + §2.3)

- Critério técnico (conforme leitura adotada em `specify.md` §1.2): grupo **tem** competência fechada ⟺ existe pelo menos uma linha em `ex_group_cycle_snapshots` para aquele `group_id`.
- Adiciona relação `cycleSnapshots()` (`hasMany(GroupCycleSnapshot::class, 'group_id')`) em `backend/app/Models/Group.php`, ao lado das relações já existentes (`expenses`, `participations`, `members`, `creator` — `Group.php:28-46`).
- Expõe esse dado para o frontend decidir a cópia do modal de confirmação **antes** de chamar o DELETE: adiciona `->withExists('cycleSnapshots')` à query de `GroupController@index` (`backend/app/Http/Controllers/GroupController.php:18-24`, ao lado do `withMax('expenses', 'date_payment')` já existente), o que popula um atributo `cycle_snapshots_exists` (boolean) em cada grupo retornado — mesmo padrão já usado nessa query, sem endpoint novo.
- `GroupController@destroy` (`backend/app/Http/Controllers/GroupController.php:97-105`) passa a ramificar:
  - `$hasClosedCycle = $group->cycleSnapshots()->exists();`
  - **Sem competência fechada** → exclusão física, dentro de uma transação (`DB::transaction`), na ordem de dependência (filhos antes dos pais, usando as relations já existentes nos models em vez de SQL cru):
    1. `Participation` de cada `Quota` de cada despesa do grupo (`Participation belongsTo Quota`, `backend/app/Models/Participation.php:29-32`).
    2. `Quota` de cada despesa (`Expense::quotas()`, `backend/app/Models/Expense.php:65-68`).
    3. Pivot `ex_expenses_payers` de cada despesa (`Expense::payers()`, `Expense.php:60-63`).
    4. Despesas do grupo (`Group::expenses()`, `Group.php:28-30`).
    5. Pivot `ex_groups_members` (`Group::members()->detach()`, `Group.php:38-40`).
    6. A linha do grupo em `ex_groups`.
    - Decisão: apagar explicitamente em código (não depender de `ON DELETE CASCADE` do schema) — mais previsível e não exige alterar migrations existentes; confirmar em tempo de implementação se alguma FK já cascateia, para não duplicar trabalho, mas o código deve funcionar independentemente disso.
  - **Com competência fechada** → mantém o comportamento atual: `$group->update(['deleted' => true])`.
  - Resposta HTTP: mantém formato simples de mensagem (`{'message': ...}`), com texto diferente por caso (ex.: `'Grupo excluído permanentemente.'` vs. `'Grupo marcado como deletado.'` — este último já é o texto atual) para o frontend exibir no `Snackbar` existente (`frontend/src/pages/Dashboard.tsx:236-245`) sem precisar de um campo estruturado novo.
- Frontend (`frontend/src/pages/Dashboard.tsx`):
  - Tipo `Group` (`Dashboard.tsx:39-47`) ganha `cycle_snapshots_exists: boolean`.
  - O `Dialog` de confirmação (`Dashboard.tsx:215-234`) passa a variar o texto conforme `removeGroup.cycle_snapshots_exists`: aviso de ação irreversível quando `false` (exclusão física), aviso de que o histórico será preservado quando `true` (exclusão lógica). Mantém a mesma estrutura de `Dialog`/`handleDeleteGroup` já existente — não é um componente novo.

## 3. Bloqueio de escrita em grupo excluído logicamente (specify §2.3, gap)

- Adiciona um guard `abort_if($group->deleted, 404, 'Grupo não encontrado.')` logo após cada `Group::findOrFail(...)` relevante — mesmo código HTTP e mesma justificativa já usada para membership (`GroupController.php:107-109`, "404 para não confirmar a existência do grupo a quem não é membro"): um grupo excluído logicamente se comporta, para escrita, como se não existisse mais, independentemente de o chamador ainda ser membro.
- Pontos a guardar:
  - `GroupMemberController@store` (`backend/app/Http/Controllers/GroupMemberController.php:35`, logo após `$group = Group::findOrFail($groupId);`).
  - `ExpenseController@store` (`backend/app/Http/Controllers/ExpenseController.php:223`, logo após `$group = Group::findOrFail($request->group_id);`) — guard distinto do `rejectIfCompetenceClosed` já existente (`ExpenseController.php:196-215`, que trata competência fechada por data/snapshot, não grupo excluído).
  - `ExpenseController@close` (`ExpenseController.php:427`) e `ExpenseController@reopen` (`ExpenseController.php:472`).
- **Não** adiciona guard em `GroupController@update` (editar nome/descrição do grupo) — não faz parte do pedido (`specify.md` §2.3 só lista despesas, participantes e fechamentos); manter fora do escopo evita comportamento não pedido.
- `GroupMemberController@destroy` (remover membro) e `GroupController@show` continuam sem guard — remover membro de um grupo já excluído não é uma escrita que cria dado novo, e `show` só é usado para ver/editar (já coberto por não bloquear `update`).

## N. Ordem de execução

Sem dependência técnica forte entre os itens 1 (rota), 2 (exclusão física/lógica) e 3 (bloqueio de escrita) — tocam arquivos diferentes e podem ser feitos em qualquer ordem entre si. Dentro do item 2, a parte de frontend (`Dashboard.tsx` consumindo `cycle_snapshots_exists`) depende da parte de backend (expor o campo em `GroupController@index`) estar pronta antes, então a task de backend do item 2 vem antes da task de frontend do item 2 em `tasks.md`.

Critério de ordenação usado em `tasks.md`: primeiro o item 3 (fecha uma lacuna de integridade de dado — hoje é possível escrever em grupo "excluído"), depois o item 2 (funcionalidade nova de exclusão física, que depende conceitualmente do mesmo modelo de "grupo excluído" que o item 3 termina de proteger), por último o item 1 (rename de rota, cosmético/organizacional, sem risco de dado).
