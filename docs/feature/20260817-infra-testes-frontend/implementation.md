# Implementation — Infraestrutura de Testes no Frontend Web

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260817

---

## 1. Desvios do fluxo padrão (se houver)

Primeira feature a seguir o fluxo `branch → dev → main` por completo (`00-constitution.md` §5.1) — branches nomeadas `<tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx`, a partir de `dev` atualizada, com PR contra `dev`.

TASK-031 e TASK-032 dependem da infra da TASK-030 (`plan.md` §4), que ainda não tinha mergeado em `dev` quando começaram — em vez de esperar o merge, suas branches nasceram empilhadas sobre a branch da TASK-030 (não direto de `dev`). Cada PR permanece individual e revisável; depois que TASK-030 mergear em `dev`, os PRs de TASK-031/032 podem trocar a base pra `dev` (rebase) antes do merge deles.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-030 | PR aberto | 2026-08-18 | Claude (IA) | `npm install -D vitest jsdom @testing-library/react @testing-library/jest-dom @testing-library/user-event` — ok (vitest 4.1.10). `npx tsc --noEmit` — sem erros. `npx vitest run` — `No test files found, exiting with code 1` (esperado, confirma que a config carrega sem erro de resolução de módulo/plugin; nenhum arquivo de teste ainda existe, isso vem nas TASK-031/032). Correção adicional, achada ao escrever o primeiro teste real na TASK-031: Node 22+ expõe `localStorage` global nativo que colide com o polyfill do jsdom (`SecurityError: Cannot initialize local storage without a --localstorage-file path`) — resolvido com `test.execArgv: ['--no-experimental-webstorage']` em `vite.config.js`. | Branch `frontend/20260817-infra-testes-frontend-TASK-030`, a partir de `dev` atualizada. |
| TASK-031 | PR aberto | 2026-08-18 | Claude (IA) | `npm test` — `RequireAuth.test.tsx`, 2/2 passou. `npx vitest run src/components/RequireAuth.test.tsx` isolado — 2/2 passou. `npx tsc --noEmit` — sem erros. | Branch `frontend/20260817-infra-testes-frontend-TASK-031`, empilhada sobre a branch da TASK-030 (ver §1). |
