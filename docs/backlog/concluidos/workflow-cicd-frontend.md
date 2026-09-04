# Workflow de CI/CD para o frontend

ID: 008
Origem: solicitação direta do usuário (conversa), 2026-08-18
Criado em: 2026-08-18
Prioridade: MEDIA
Status: Promovido para TASK-215

## Descrição

Hoje só existe `.github/workflows/deploy-backend.yml` (backend); não há nenhum workflow de CI/CD equivalente para o frontend (`frontend/`). Escopo exato (lint, build, testes automatizados, deploy) fica em aberto — a decidir na especificação, quando o item for promovido.

## Por que importa

Sem CI no frontend, problemas de lint/build/regressão só aparecem manualmente ou em produção, sem checagem automática antes do merge.

Tipo sugerido: frontend/infra

## Resolução

Concluído em: 2026-08-28
Feature: docs/feature/concluidas/202608/20260827-ci-frontend/
Tasks: TASK-215, TASK-216, TASK-217
PRs: https://github.com/isacaguiar/expense/pull/76, https://github.com/isacaguiar/expense/pull/77, https://github.com/isacaguiar/expense/pull/78

Nota: a entrega exigiu 3 tasks em vez de 1 — as duas primeiras (TASK-215, TASK-216) foram mergeadas antes da execução real do workflow em CI ser conferida, e ambas falharam de fato (diagnósticos errados: achou que era o YAML, depois achou que era timeout). TASK-217 corrigiu a causa raiz real (flag `--experimental-webstorage` incompatível com o Node 20 do runner) e foi a primeira confirmada verde em CI antes do merge.
