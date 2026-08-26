# Implementation — Navegação mobile do GroupShellLayout

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260826

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Segue `04-implementation.md` §1 (fluxo ADR-003: branch única da feature, sub-branch por task mergeada nela localmente sem PR, checklist antes de integrar e antes do PR único da feature contra `dev`).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-204 | Integrada na branch da feature | 2026-08-26 | Claude | `npx vitest run src/layouts/Sidebar.test.tsx` — 5/5 verde; `npx tsc --noEmit` — sem erro; `npx vitest run src/layouts/` — 17/17 verde | Extraída `NavList`/`NavListItem` de `Sidebar.tsx` para `frontend/src/layouts/NavList.tsx`, com `onNavigate?: () => void` opcional (não usado ainda por `Sidebar`, será usado pelo `MobileNavDrawer` na TASK-205). Primeira task da feature — implementada direto na branch `frontend/20260826-navegacao-mobile-group-shell`, sem sub-branch. |
| TASK-205 | Integrada na branch da feature | 2026-08-26 | Claude | `npx vitest run src/layouts/MobileNavDrawer.test.tsx` — 4/4 verde; `npx tsc --noEmit` — sem erro | Criado `frontend/src/layouts/MobileNavDrawer.tsx` (MUI `Drawer` `variant="temporary"`, `ModalProps={{ keepMounted: true }}`) reaproveitando `NavList` com `onNavigate={onClose}`. Teste novo `MobileNavDrawer.test.tsx` cobre: itens visíveis quando aberto, itens fora da árvore de acessibilidade quando fechado (`keepMounted` some do `getByRole` via `aria-hidden`), `onClose` chamado ao clicar em item com link e em item com ação. Implementada em sub-branch `frontend/20260826-navegacao-mobile-group-shell-TASK-205`, mergeada localmente na branch da feature sem PR (fluxo ADR-003). |
| TASK-206 | Integrada na branch da feature | 2026-08-26 | Claude | `npx vitest run src/layouts/group/GroupHeader.test.tsx src/layouts/GroupShellLayout.test.tsx` — 8/8 verde; `npx tsc --noEmit` — sem erro; `npx vitest run src/layouts/` — 23/23 verde | Adicionada prop obrigatória `onMenuClick: () => void` a `GroupHeader.tsx` com `IconButton` (`MenuIcon`, `aria-label="Abrir menu de navegação"`), visível só abaixo de `md`. Teste novo `GroupHeader.test.tsx`. **Achado tangencial durante a task** (fora do escopo do plan.md, não bloqueia TASK-206/207): `SimpleShellLayout.tsx` também usa `GroupHeader` e tem o mesmo problema de sidebar sem substituto mobile — recebeu só `onMenuClick={() => {}}` (no-op) para manter o `tsc` compilando com a prop agora obrigatória; a correção completa dele virou item de backlog `032` (`docs/backlog/simpleshelllayout-sidebar-navegacao-mobile.md`), não uma task nova nesta feature. `GroupShellLayout.tsx` também recebeu `onMenuClick={() => {}}` temporário — será substituído pelo handler real na TASK-207. Implementada em sub-branch `frontend/20260826-navegacao-mobile-group-shell-TASK-206`, mergeada localmente na branch da feature sem PR. |
