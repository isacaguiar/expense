# Tasks — CI do frontend (type-check, testes, build)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260827

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-215 | Criar workflow `.github/workflows/ci-frontend.yml` | infra | plan.md §1 | nenhum | Concluída (com ressalva — ver TASK-216) |
| TASK-216 | Corrigir timeout de teste do Vitest, causa da falha real da TASK-215 em CI | infra | plan.md §1 | nenhum | Pendente |

## Critérios de aceite

- **TASK-215**: `.github/workflows/ci-frontend.yml` existe, com `on.pull_request.branches` incluindo `dev` e `main`. Um PR de teste (ex.: a própria branch desta feature contra `dev`) dispara o workflow no GitHub Actions e ele roda com sucesso (`npm ci`, `tsc --noEmit`, `vitest run`, `npm run build`, todos verdes) — verificado observando a execução real na aba Actions do GitHub após o push, não só lendo o YAML. Introduzir uma quebra proposital e temporária (e revertê-la antes do commit final) para confirmar que o workflow também falha corretamente quando um dos passos quebra é validação aceitável, registrada em `implementation.md`. **Ressalva registrada em `implementation.md`**: o PR desta task foi mergeado pelo usuário antes da execução real no GitHub Actions ser conferida — a checagem posterior mostrou que o passo "🧪 Testes" falhou de fato (`tsc`/build passaram). Ver TASK-216.
- **TASK-216**: identifica e corrige a causa raiz da falha acima (timeout padrão de 5000ms do Vitest, curto demais sob CPU compartilhada — mesmo padrão de flakiness já observado localmente na suíte completa em paralelo, sempre em arquivos que passam 100% isolados). Corrigido via `testTimeout`/`hookTimeout: 15000` em `frontend/vite.config.js`. Validado localmente: `npx tsc --noEmit` sem erro; `npx vitest run` (suíte completa) 172/172 verde em rodada limpa, após uma rodada anterior com 171/172 (1 falha, confirmada flaky ao rodar isolada: 3/3 verde). Critério de aceite real: um novo PR contra `dev` dispara o workflow `CI Frontend` de novo e ele passa **verde de ponta a ponta, incluindo o passo de testes**, verificado na aba Actions do GitHub antes do merge desta vez (não depois).
