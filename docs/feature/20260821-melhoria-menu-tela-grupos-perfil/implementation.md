# Implementation — Melhoria do Menu, Tela de Grupos e Perfil

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-111 | Concluída | 20260821 | IA | `npx vitest run src/layouts/SimpleShellLayout.test.tsx src/layouts/GroupShellLayout.test.tsx` — 10 passed (2 files). `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 63 passed (14 files). | `accountSettingsNavItems.ts` novo, compartilhado pelos 2 arquivos de navegação. Rótulo do filho "Grupos" virou "Meus Grupos" (pedido do usuário: "Configurações > Meus Grupos, Minha Conta, Alterar Senha") — ajustou testes que checavam o rótulo antigo. `Configurações` do menu com grupo selecionado deixou de linkar direto para `/groups/:id/edit`; esse acesso só volta a existir via ícone de editar na listagem de grupos (TASK-116/117, ainda não implementadas). |
| TASK-112 | Concluída | 20260821 | IA | `npx vitest run src/layouts/Sidebar.test.tsx` — 5 passed (1 file), incluindo o teste novo de item com `onAction`. `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 64 passed (14 files). | `GroupNavItem` ganhou `onAction?: () => void`; `SidebarNavItem` ganhou um 3º branch de renderização (depois de `to`, antes do placeholder `href="#"`) para item com `onAction`. |
| TASK-113 | Concluída | 20260821 | IA | `npx vitest run src/auth/logout.test.ts src/layouts/SimpleShellLayout.test.tsx src/layouts/GroupShellLayout.test.tsx` — 14 passed (3 files), incluindo os 2 testes novos de "Sair" (um por shell) e os 2 do helper `logout`. `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 68 passed (15 files). | `frontend/src/auth/logout.ts` novo — chama `POST /api/logout` (endpoint já existia, nunca usado por nenhuma tela), limpa `localStorage` e navega para `/` mesmo se a chamada ao backend falhar (`try/finally`). `simpleNavItems`/`groupNavItems` passaram a receber `navigate` como parâmetro; `GroupSidebar` (componente) chama `useNavigate()` internamente para poder repassar. "Sair" é item de topo (irmão de "Configurações"), não filho dela. |
| TASK-114 | Concluída | 20260821 | IA | `./vendor/bin/pint --test app/Http/Controllers/GroupController.php tests/Feature/GroupControllerTest.php` — PASS, 2 files. `php artisan test --filter=GroupControllerTest` — 18 passed (46 assertions), incluindo os 3 testes novos (lista de `members`, `expenses_max_date_payment` com despesas, `expenses_max_date_payment` nulo sem despesas). `php artisan test` (suíte completa) — 69 passed (169 assertions). | `GroupController@index` ganhou `with('creator:id,email', 'members:id,name,email')` e `withMax('expenses', 'date_payment')` na mesma query — serve tanto a TASK-115 (Home/Despesas) quanto a TASK-116 (tabela com avatares). |
| TASK-115 | Concluída | 20260821 | IA | `npx vitest run src/pages/mostActiveGroup.test.ts src/pages/SummaryEntry.test.tsx src/pages/ExpensesEntry.test.tsx` — 12 passed (3 files). `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 74 passed (16 files). | Extraído helper compartilhado `mostActiveGroup.ts` (não estava no plan.md explicitamente, mas evita duplicar a mesma lógica de ordenação em `SummaryEntry.tsx`/`ExpensesEntry.tsx` — mesmo padrão de outras extrações já feitas no projeto, ex. `getInitials.ts`). Tela de escolha (grid de cards) removida das duas páginas — com o auto-redirect valendo sempre que há pelo menos 1 grupo, o grid nunca mais seria alcançado. |
| TASK-116 | Concluída | 20260821 | IA | `npx vitest run src/pages/Dashboard.test.tsx` — 10 passed (1 file), incluindo o teste novo de avatares com iniciais. `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 75 passed (16 files). | `Dashboard.tsx`: `Grid`/`Card` → `Table` (colunas Nome/Responsável/Integrantes/Ações); iniciais via `getInitials(member.email)`, mesmo padrão de `GroupMembersForm.tsx` (iniciais de e-mail sem espaço tendem a ser de 1 letra só, não 2 — comportamento herdado, não um bug novo). Coluna de descrição do grupo foi removida (não fazia parte do pedido do usuário: "nome do grupo e ícones das ações" + "imagens dos integrantes"). Ícone de excluir fica para TASK-117. |
