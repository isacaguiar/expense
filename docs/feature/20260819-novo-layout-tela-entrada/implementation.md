# Implementation — Novo Layout da Tela de Resumo (Entrada pós-login)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260819

---

## 1. Desvios do fluxo padrão (se houver)

Mesmo desvio já adotado na feature anterior (`novo-layout-tela-login`): todas as tasks são commitadas diretamente na branch da feature (`frontend/20260819-novo-layout-tela-entrada`), sem sub-branch por task — feature pequena, só frontend, sem gate humano em nenhuma task.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-078 | Concluída | 2026-08-19 | IA (Claude) | Criado `frontend/src/theme/brandColors.ts` (export `brandColors`, mesmos 4 valores de `loginColors`); atualizados os imports em `LoginBrandingPanel.tsx`/`LoginFormCard.tsx`; removido `pages/login/colors.ts`; `grep -rn "pages/login/colors\|loginColors"` sem resultado. `npx tsc --noEmit` → exit 0. `npx vitest run src/pages/LoginPage.test.tsx` → 4/4 passed. | Rename mecânico, sem mudança de cor/comportamento visual. |
| TASK-079 | Concluída | 2026-08-19 | IA (Claude) | Em `App.tsx`, movida a rota `/groups/:id/summary` para fora de `<Route element={<InternalLayout />}>`, mantida dentro de `<Route element={<RequireAuth />}>`. `npx tsc --noEmit` → exit 0. `npx vitest run` → 25/25 passed (nenhum teste dependia da posição da rota). Verificação visual via preview (`read_page`): `/groups/1/summary` renderiza só "Resumo do Grupo" + spinner, sem `banner`/links da `Navbar`; `/dashboard` continua mostrando a `Navbar` normalmente (`banner` com Dashboard/Grupos/Despesas/Resumo/Sair). `git diff -- frontend/src/components/Navbar.tsx frontend/src/layouts/InternalLayout.tsx` vazio. | — |
| TASK-080 | Concluída | 2026-08-19 | IA (Claude) | Criados `pages/summary/GroupSummarySidebar.tsx` (nav de 280px, `data-group-id`) e `pages/summary/GroupSummaryHeader.tsx` (casca com props tipadas `groups`/`groupId`/`onGroupChange`, ainda sem uso em `GroupSummary.tsx` — conteúdo e wiring entram na TASK-082). `GroupSummary.tsx` reestruturado: `Container` raiz virou `Box` flex (`minHeight: 100vh`) com `GroupSummarySidebar` + `Container component="main"` contendo exatamente o conteúdo de antes (título/Select, navegação de ciclo, cards, listas), sem remover/alterar nenhum dado. `npx tsc --noEmit` → exit 0. `npx vitest run src/pages/GroupSummary.test.tsx src/pages/SummaryEntry.test.tsx` → 8/8 passed. Verificação visual: `document.querySelector('nav').getBoundingClientRect().width === 280` em `/groups/1/summary`. | `GroupSummaryHeader.tsx` fica sem uso (arquivo criado, não importado) até a TASK-082 — transição intencional, não é código morto esquecido. |
| TASK-081 | Concluída | 2026-08-19 | IA (Claude) | `GroupSummarySidebar.tsx` implementado: wordmark "Shared Expense" (logo + Poppins), 6 itens (`ListItemButton`) — Resumo/Despesas/Participantes com `component={RouterLink} to=".../groups/:id/..."`, Pagamentos/Relatórios/Configurações com `component="a" href="#"` e cor `text.disabled`; item ativo via `useLocation().pathname === item.to` + classe `.Mui-selected` com `brandColors.primaryLight`/`primary`. `npx tsc --noEmit` → exit 0. Verificação visual via preview (`read_page` em `/groups/1/summary`, com token de sessão ainda válido no momento da leitura): sidebar lista os 6 itens com os `href` corretos (`/groups/1/summary`, `/groups/1/expenses`, `/groups/1/members`, `#`, `#`, `#`). | Uma leitura seguinte da mesma página redirecionou pra `/` por token expirado (comportamento existente de `GroupSummary.tsx` em 401, não é bug desta task) — confirmação do destaque visual do item ativo fica coberta pelo teste automatizado da TASK-087, não por captura ao vivo. |
