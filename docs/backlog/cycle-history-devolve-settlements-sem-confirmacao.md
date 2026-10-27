# `cycleHistory` devolve `settlements` do snapshot sem os campos de confirmação

ID: 107
Origem: docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/specify.md §2.9 (ponto pré-existente, fora do escopo daquela feature)
Criado em: 2026-10-27
Prioridade: BAIXA
Status: Aberto

## Descrição
`GET /api/groups/{groupId}/expenses/cycles` (`ExpenseController::cycleHistory`, `backend/app/Http/Controllers/ExpenseController.php:951`) devolve `'settlements' => $snapshot->settlements` cru. Os demais caminhos que devolvem `settlements` (`summary()`, `close()`, `reopen()`, `viewAt`) passam por `withConfirmations` e trazem `confirmedAt`, `confirmedProofUrl` e `confirmedNote`; o histórico não traz. Um mesmo acerto, no mesmo ciclo selado, tem formas diferentes conforme o endpoint.

Hoje a tela de Relatórios (`useGroupCycleHistory` e `GroupReports`) não lê `settlements`, então não há efeito visível. O problema é latente: o app Flutter (ADR-010) ou qualquer tela nova que mostre o histórico por esse endpoint receberá acertos sem confirmação, e a feature `20261026-organizacao-despesas-pagamentos` mostrou que "pago" depende de `confirmedAt`.

Correção provável: passar `settlements` por `withConfirmations` também aqui (mudança só aditiva, Constitution §4.1), tratando a coleção paginada sem uma consulta por ciclo (agrupar as confirmações por `cycle_start` numa só consulta), para não acrescentar até 10 consultas por página.

## Por que importa
Inconsistência de contrato entre endpoints do mesmo dado, que só costuma aparecer quando alguém constrói uma tela em cima do histórico. Barato de corrigir antes disso e sem efeito para o web de hoje.

Tipo sugerido: backend
