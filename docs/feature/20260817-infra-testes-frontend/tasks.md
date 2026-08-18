# Tasks — Infraestrutura de Testes no Frontend Web

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-030` — maior ID já usado no projeto antes desta feature: `TASK-029` (`docs/feature/20260817-config-url-api-frontend/tasks.md`).

Versão: 1.0 · Criado em: 20260817

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-030 | Instalar e configurar Vitest + Testing Library no frontend web (`vite.config.js`, `setupTests.ts`, `tsconfig.json`, scripts `test`/`test:watch`) | frontend | plan.md §1 | nenhum | Concluída (PR #9 mergeado em `dev`) |
| TASK-031 | Criar `RequireAuth.test.tsx` cobrindo os dois casos (sem token → login; com token → conteúdo protegido) | frontend | plan.md §2 | nenhum | Concluída (PR #8 mergeado em `dev`) |
| TASK-032 | Criar `GroupList.test.tsx` cobrindo o redirect automático em resposta 401 (mock de `axios` + `useNavigate`) | frontend | plan.md §3 | nenhum | Concluída (PR #10 mergeado em `dev`) |

## Critérios de aceite

- **TASK-030**: `npx vitest run` executa e reporta "No test files found" (não erro de configuração/resolução de módulo — confirma que Vitest, `jsdom` e `setupTests.ts` carregam corretamente mesmo sem nenhum arquivo de teste ainda); `npx tsc --noEmit` continua sem erro com `vitest/globals` em `tsconfig.json`.
- **TASK-031**: `npm test` roda `RequireAuth.test.tsx` e os dois casos passam (verde); rodar só esse arquivo isoladamente (`npx vitest run src/components/RequireAuth.test.tsx`) também passa.
- **TASK-032**: `npm test` roda `GroupList.test.tsx` e o caso de 401 passa (verde), com a asserção de que `navigate` foi chamado com `('/', { replace: true })`.
