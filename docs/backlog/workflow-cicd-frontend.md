# Workflow de CI/CD para o frontend

ID: 008
Origem: solicitação direta do usuário (conversa), 2026-08-18
Criado em: 2026-08-18
Prioridade: MEDIA
Status: Aberto

## Descrição

Hoje só existe `.github/workflows/deploy-backend.yml` (backend); não há nenhum workflow de CI/CD equivalente para o frontend (`frontend/`). Escopo exato (lint, build, testes automatizados, deploy) fica em aberto — a decidir na especificação, quando o item for promovido.

## Por que importa

Sem CI no frontend, problemas de lint/build/regressão só aparecem manualmente ou em produção, sem checagem automática antes do merge.

Tipo sugerido: frontend/infra
