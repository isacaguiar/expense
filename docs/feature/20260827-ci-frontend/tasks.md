# Tasks — CI do frontend (type-check, testes, build)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260827

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-215 | Criar workflow `.github/workflows/ci-frontend.yml` | infra | plan.md §1 | nenhum | Pendente |

## Critérios de aceite

- **TASK-215**: `.github/workflows/ci-frontend.yml` existe, com `on.pull_request.branches` incluindo `dev` e `main`. Um PR de teste (ex.: a própria branch desta feature contra `dev`) dispara o workflow no GitHub Actions e ele roda com sucesso (`npm ci`, `tsc --noEmit`, `vitest run`, `npm run build`, todos verdes) — verificado observando a execução real na aba Actions do GitHub após o push, não só lendo o YAML. Introduzir uma quebra proposital e temporária (e revertê-la antes do commit final) para confirmar que o workflow também falha corretamente quando um dos passos quebra é validação aceitável, registrada em `implementation.md`.
