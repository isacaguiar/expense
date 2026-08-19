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
