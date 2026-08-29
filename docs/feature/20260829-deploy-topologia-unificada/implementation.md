# Implementation — Topologia de deploy unificada (site + /app + /api)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260829

---

## 1. Desvios do fluxo padrão / pendências de infra

Feature de infra + roteamento de frontend. Verificação: parse de YAML, `php -l`, e o checklist de frontend (`tsc`/`vitest`/`build`) para a TASK-231. Sem backend PHP tocado.

**Pendências manuais no painel de hospedagem** (não automatizáveis; o deploy roda verde sem elas, mas o site não serve o esperado até estarem feitas):

1. Document root de `expense.novemax.com.br` → `/expense/www/`.
2. Document root de `expense-api.novemax.com.br` → `/expense/api/public/` (hoje aponta para algo sob `/expense/backend/`).
3. Remover manualmente `/expense/frontend/` se contiver deploy anterior do app (lixo após esta feature — o app passa a viver em `/expense/www/app/`).

O merge em `main` ativa os 3 deploys (`deploy-backend`, `deploy-site`, `deploy-frontend`) — gate humano.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| — | — | — | — | — | (nenhuma task executada ainda) |
