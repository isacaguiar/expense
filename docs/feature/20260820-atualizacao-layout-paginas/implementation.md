# Implementation — Atualização de Layout das Demais Páginas

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260820

---

## 1. Desvios do fluxo padrão

- **Branch criada a partir de `frontend/20260819-novo-layout-tela-entrada`, não de `dev`.** `04-implementation.md` §1 pede branch a partir de `dev` atualizada. Esta feature depende diretamente de código que só existe naquela branch (`GroupSummarySidebar`/`GroupSummaryHeader`, `theme/brandColors.ts`) — o PR dela contra `dev` (`https://github.com/isacaguiar/expense/pull/new/frontend/20260819-novo-layout-tela-entrada`) ainda não foi aprovado/mergeado (confirmado via `git merge-base --is-ancestor ... origin/dev` → não é ancestral). Branchear de `dev` significaria reescrever esses componentes do zero ou duplicá-los. Consequência: o PR desta feature contra `dev` só pode ser aberto/mergeado depois (ou junto) do PR de `novo-layout-tela-entrada` — vai carregar os commits daquela branch até lá ser integrada.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-088 | Concluída | 2026-08-20 | IA (Claude) | `npx tsc --noEmit` — limpo. `npx vitest run src/pages/GroupSummary.test.tsx` — 9/9 (1 falha inicial ajustada, ver observação). | `GroupSidebar`/`GroupHeader`/`getInitials` movidos de `pages/summary/` para `layouts/group/`; `GroupHeader` agora recebe `title` via prop. Aproveitei para já aplicar a decisão do `plan.md` §1 de apontar "Configurações" para `/groups/:id/edit` (estava `href="#"`) — ajustei a asserção correspondente em `GroupSummary.test.tsx` (era o único teste que quebrou). |
| TASK-089 | Concluída | 2026-08-20 | IA (Claude) | `npx tsc --noEmit` — limpo. `npx vitest run src/pages/GroupSummary.test.tsx src/layouts/GroupShellLayout.test.tsx` — 10/10. `npx vitest run` (suíte completa) — 30/30. | Criado `GroupShellLayout.tsx` (sidebar+header, busca `groups`/`userName` uma vez, título derivado do item ativo de `groupNavItems`, troca de grupo preserva a página atual via `location.pathname.replace`). `GroupSummary.tsx` migrado para consumir o shell via `<Outlet/>` (perdeu os `useEffect` de `groups`/`userName` e o wrapper `Box`/`Container`). Rota `/groups/:id/summary` movida para dentro de `<GroupShellLayout/>` em `App.tsx`. 3 testes que validavam comportamento de shell (link da sidebar, nome/iniciais do usuário, navegação ao trocar de grupo) saíram de `GroupSummary.test.tsx` e viraram `GroupShellLayout.test.tsx` (testado com `Routes` reais, não mais `useParams` mockado, para cobrir a derivação de título e a preservação de página ao trocar de grupo). |
