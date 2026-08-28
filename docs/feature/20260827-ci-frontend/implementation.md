# Implementation — CI do frontend (type-check, testes, build)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260827

---

## 1. Desvios do fluxo padrão (se houver)

**TASK-215 foi mergeada (PR #76) antes da execução real do workflow no GitHub Actions ter sido conferida** — o usuário mergeou assim que o PR foi aberto, sem esperar a confirmação de que o CI passava, que era o próprio critério de aceite da task. Checagem posterior (via API pública do GitHub, `GET /repos/isacaguiar/expense/actions/workflows/ci-frontend.yml/runs`) mostrou que o run real (`run 33211513555`) teve `conclusion: failure` no passo "🧪 Testes" (type-check e build passaram). TASK-216 identifica e corrige a causa raiz; a verificação real do critério de aceite fica para o PR da TASK-216.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-215 | Concluída (com ressalva) | 2026-08-27 | IA (Claude Code) | Workflow criado e mergeado (PR #76). Checagem pós-merge via `curl https://api.github.com/repos/isacaguiar/expense/actions/workflows/ci-frontend.yml/runs` — run real com `conclusion: failure` no passo de testes. | Ver §1 acima e TASK-216. |
| TASK-216 | Concluída | 2026-08-28 | IA (Claude Code) | `cd frontend && npx tsc --noEmit` — sem erro. `npx vitest run` (suíte completa) — 1ª rodada 171/172 (1 falha, `AcceptInvitePage.test.tsx`, confirmada flaky: `npx vitest run src/pages/AcceptInvitePage.test.tsx` — 3/3 verde isolado); 2ª rodada 172/172 verde. Causa raiz: `testTimeout` padrão do Vitest (5000ms) curto demais sob CPU compartilhada — mesmo padrão de flakiness já visto na suíte completa em paralelo ao longo desta sessão, sempre em arquivos que passam 100% isolados (nunca uma falha de asserção real). Corrigido com `testTimeout`/`hookTimeout: 15000` em `frontend/vite.config.js`. | Verificação real do critério de aceite (workflow verde em CI de ponta a ponta) pendente do PR desta task — aguardando abertura/confirmação antes do merge desta vez. |
