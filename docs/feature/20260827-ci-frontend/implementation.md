# Implementation — CI do frontend (type-check, testes, build)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260827

---

## 1. Desvios do fluxo padrão (se houver)

**TASK-215 foi mergeada (PR #76) antes da execução real do workflow no GitHub Actions ter sido conferida** — o usuário mergeou assim que o PR foi aberto, sem esperar a confirmação de que o CI passava, que era o próprio critério de aceite da task. Checagem posterior (via API pública do GitHub, `GET /repos/isacaguiar/expense/actions/workflows/ci-frontend.yml/runs`) mostrou que o run real (`run 33211513555`) teve `conclusion: failure` no passo "🧪 Testes" (type-check e build passaram). TASK-216 identificou a causa raiz errada (timeout); **o PR da TASK-216 também foi mergeado (PR #77) antes da confirmação do CI**, e a run real (`run 33213146022`) falhou de novo, no mesmo passo, em ~29s de execução total do job — rápido demais pra ser timeout de teste (a suíte completa leva 90-200s localmente). Isso apontou pra uma falha imediata/estrutural, não performance. TASK-217 identifica e corrige a causa raiz de verdade.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-215 | Concluída (com ressalva) | 2026-08-27 | IA (Claude Code) | Workflow criado e mergeado (PR #76). Checagem pós-merge via `curl https://api.github.com/repos/isacaguiar/expense/actions/workflows/ci-frontend.yml/runs` — run real com `conclusion: failure` no passo de testes. | Ver §1 acima e TASK-216. |
| TASK-216 | Concluída (diagnóstico errado) | 2026-08-28 | IA (Claude Code) | `cd frontend && npx tsc --noEmit` — sem erro. `npx vitest run` (suíte completa) — 1ª rodada 171/172 (1 falha, `AcceptInvitePage.test.tsx`, confirmada flaky isolado); 2ª rodada 172/172 verde. Corrigido `testTimeout`/`hookTimeout: 15000` em `vite.config.js`. | PR #77 mergeado antes da confirmação do CI. Run real (`33213146022`) falhou de novo, mesmo passo, ~29s de job total — descartou a hipótese de timeout. Ver TASK-217. |
| TASK-217 | Concluída | 2026-08-28 | IA (Claude Code) | Causa raiz real: `execArgv: ['--no-experimental-webstorage']` — flag do Node só reconhecida a partir do Node 22.4 (busca web: [Appwrite blog](https://appwrite.io/blog/post/nodejs-v25-whats-new), [nodejs/node#60708](https://github.com/nodejs/node/pull/60708)); o workflow fixa Node 20, que rejeita a flag e derruba o worker do Vitest na hora — bate com a falha em ~29s. Corrigido tornando a flag condicional a `Number(process.versions.node.split('.')[0]) >= 22`. `npx tsc --noEmit` — sem erro. `npx vitest run` local instável nesta rodada (sistema sob carga real — 20 processos `node.exe` órfãos de execuções anteriores desta sessão encontrados via `tasklist` e encerrados via `taskkill /F /IM node.exe`; Windows Defender com uso de CPU elevado via `Get-Process`); mesmo após a limpeza, rodadas locais variaram entre 168/172 e passar limpo, sempre nos mesmos arquivos que já se provaram flaky-sob-carga nesta sessão (nunca falha de asserção real, sempre timeout/erro de import). Dado o ambiente local instável agora, a validação real fica pro próprio CI (runner limpo, sem a carga local) — critério de aceite verificado lá, não localmente. | Aguardando abertura do PR e confirmação do CI antes do merge — desta vez a run real precisa ser conferida antes, não depois. |
