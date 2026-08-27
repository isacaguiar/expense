# Implementation — Navegação mobile do SimpleShellLayout

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260826

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-208 | Concluída | 2026-08-26 | IA (Claude Code) | `cd frontend && npx tsc --noEmit` — sem erro. `npx vitest run src/layouts/SimpleShellLayout.test.tsx` — 7/7 verde (inclui teste novo "opens the mobile nav drawer via the header menu button, and closes it after navigating"). `npx vitest run` (suíte completa) — 24 arquivos / 162 testes, todos verdes. Validação manual: `npm run dev` (Vite em `http://localhost:5176`) + script Playwright ad-hoc (instalado como devDependency temporária, removido ao final via `npm uninstall playwright`) com viewport 375×800, localStorage `accessToken` mockado e rotas `/api/me`/`/api/groups` interceptadas — confirmado nas 3 rotas do `SimpleShellLayout` (`/meus-grupos`, `/profile`, `/change-password`): botão hambúrguer visível, clique abre `MobileNavDrawer` com os 8 itens de `simpleNavItems` (Home, Despesas, Participantes, Pagamentos, Relatórios, submenu Configurações expandido com o item ativo destacado, Sair), `Escape` fecha o drawer. Screenshots capturadas nas 3 rotas confirmam visualmente. | — |

## 3. Diff resumido

- `frontend/src/layouts/SimpleShellLayout.tsx`: adiciona estado `mobileNavOpen`, renderiza `MobileNavDrawer` com `simpleNavItems(navigate)`, troca `onMenuClick={() => {}}` por `onMenuClick={() => setMobileNavOpen(true)}` no `GroupHeader`.
- `frontend/src/layouts/SimpleShellLayout.test.tsx`: novo teste cobrindo abrir/fechar o drawer via botão hambúrguer.
- Nenhuma mudança em `MobileNavDrawer.tsx`, `NavList.tsx` ou `GroupHeader.tsx` — reaproveitados como já existiam.
