# Implementation — Aviso de ciclo fechado nomeia quem falta acertar

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261123

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui; a TASK-670 inclui o ajuste dos testes que afirmavam o texto antigo, como o plan.md §2 previu.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-670 | Concluída | 2026-11-23 | Claude | RED: testes reescritos para as linhas novas, `npx vitest run --maxWorkers=1 src/components/CycleClosingAlert.test.tsx` = 9 falhas, 5 passam. GREEN: `CycleClosingAlert.tsx` com `pendingSettlementLines` (uma linha por acerto sem `confirmedAt`, nomes de devedor e credor por `balances`, "Desconhecido" de reserva) e `<ul>` dentro do `Alert` `warning`; `npx tsc --noEmit` limpo; `CycleClosingAlert.test.tsx` + `ExpenseManager.test.tsx` = 2 arquivos, 127 testes passam. Mutantes mortos (arquivo restaurado e conferido com `cmp`): credor com `from_user_id` (9 falhas), deduplicar por devedor (1 falha), `confirmedAt` invertido (10 falhas). | Casos novos: devedor com dois credores (duas linhas), credor ausente em `balances`, ordem de `settlements` diferente da de `balances`. Suíte cheia no HEAD: `npx tsc --noEmit` limpo e `npx vitest run --maxWorkers=1` = 110 arquivos, 1375 testes passam. |
| TASK-671 | Concluída | 2026-11-23 | Claude | `docs/sdd/05-context-frontend.md` v1.7 → v1.8 (aviso de ciclo fechado "devedor → credor" por acerto pendente, junto às regras de apresentação); `docs/sdd/01-specify.md` v1.10 → v1.11 (bullet "Aviso de ciclo fechado nomeia quem falta acertar" em §3.3). `git diff --stat` lista só esses dois arquivos de `docs/sdd`. | O caminho da feature nos dois docs é `docs/feature/20261123-...`; troca para `concluidas/202611/` no arquivamento (ADR-009). |
| TASK-672 | Concluída | 2026-11-23 | Claude | Stack de validação: `up_test_db.py` (MySQL 3307 + `.env.testing`), `php artisan migrate:fresh --env=testing`, massa `seed672.php` (grupo 1 com Ana, Beto e Carla; ciclo de outubro, fechado por data; Ana e Carla pagam 300 cada, rateado nos três: Beto deve 100 a Ana e 100 a Carla), `php artisan serve --env=testing --port=8001` e `vite --port 3001`. Login de Ana pela API = 200. Conferido no painel embutido, lendo o DOM: **Home** e **Despesas** mostram, num só alerta amarelo, "O ciclo fechou e ainda falta acertar:" e as linhas `Beto Aviso ainda não pagou o acerto a Ana Aviso.` e `Beto Aviso ainda não pagou o acerto a Carla Aviso.` (captura de Despesas conferida). Beto confirmou o acerto a Ana (`POST /api/groups/1/settlements/confirm`, 200): a Home passou a mostrar só a linha da Carla. Ele confirmou o acerto a Carla (200): o alerta sumiu (0 alertas). | Para a validação dele, as duas confirmações foram apagadas do banco de teste (voltou a duas linhas) e os caminhos dos comprovantes ficaram anotados para a limpeza. Validação local dele pendente. |
