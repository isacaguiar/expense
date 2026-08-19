# Plan — Resumo do Grupo (Dashboard)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20260818

---

## 1. Dia de fechamento no cadastro do grupo (specify R1, achados 2.2, 2.3)

- Migration aditiva em `ex_groups`: coluna `closing_day` (`unsignedTinyInteger`, `nullable`). `NULL` = comportamento equivalente ao de hoje (ciclo = mês calendário, ver §2). Aditiva, dentro do gate autônomo local/branch de `00-constitution.md` §5.2 — sem gate humano.
- `Group` model (`backend/app/Models/Group.php`): adiciona `closing_day` a `$fillable` e `casts` (`integer`).
- `GroupController::store`/`update` (`backend/app/Http/Controllers/GroupController.php:39-42,70`): valida `closing_day` como `nullable|integer|between:1,31` e passa a persistir o campo.
- `GroupForm.tsx`: novo `TextField` numérico "Dia de fechamento (opcional)" no formulário de criar/editar grupo, enviado no payload de `POST`/`PUT /api/groups`.

## 2. Cálculo de ciclo de fechamento (specify R2)

- Classe utilitária nova e pura (sem Eloquent), `backend/app/Support/BillingCycle.php`, com um método estático (ex. `closedCycle(?int $closingDay, Carbon $reference, int $cyclesAgo = 0): array{start: Carbon, end: Carbon}`) que:
  - Trata `closingDay = null` como "último dia do mês" — reproduz o comportamento atual de mês calendário (ciclo = dia 1 ao último dia do mês, fecha no fim do mês).
  - Para `closingDay` definido, um ciclo vai do dia seguinte ao fechamento anterior até o dia de fechamento do mês corrente, inclusive (ex.: fechamento dia 10 → ciclo de 11/dez a 10/jan).
  - Aplica o mesmo "clamp" de dia curto de mês já usado em `ExpenseController::indexByGroup:58-59` e `ExpenseManager.tsx:54-62` (achado 2.4) — dia de fechamento 31 em fevereiro vira dia 28/29.
  - `cyclesAgo` desloca o cálculo N ciclos fechados para trás (navegação, specify R3).
  - Um ciclo só é "fechado" se seu `end` for anterior à data de hoje; o ciclo que contém hoje é "em andamento" e nunca é o default.
- Testável isoladamente (unit test puro, sem HTTP/banco) — cobre `closingDay=null`, `closingDay` normal, e mês curto (fevereiro).

## 3. Endpoint novo de resumo do ciclo (specify R4, R5)

- Novo método `ExpenseController::summary($groupId, Request $request)` (mesmo controller de `indexByGroup`, para reaproveitar a checagem de membership e a lógica de projeção de despesa Fixa), registrado em `GET /groups/{groupId}/expenses/summary` (query opcional `cycles_ago`, default `0`).
- Usa `BillingCycle` (§2) com o `closing_day` do grupo para determinar `[start, end]` do ciclo pedido.
- Busca despesas com `date_payment` dentro de `[start, end]` (equivalente ao `$direct` de `indexByGroup`, mas por intervalo de datas em vez de `year`/`month`) + despesas Fixa projetadas cujo `date_payment` é anterior a `start` e cuja recorrência ainda cobre alguma data dentro do intervalo (adaptação de `$projectedFixed`, `ExpenseController.php:46-55`, para intervalo em vez de mês único).
- Para cada despesa: casa a `Quota` cujo `date_expected` cai dentro de `[start, end]` (`paid` = `quota->paid ?? false`; `value` = `quota->value_quota ?? total_value`) — mesma regra do achado 2.6/2.9, sem inventar estado para o que não existe.
- Calcula `balances`: para cada despesa do ciclo, `valorPorPessoa = value / participants_count`; cada participante ≠ pagador debita `-valorPorPessoa`, pagador credita `+valorPorPessoa`; soma por `user_id` de todos os membros do grupo (`Group::with('members')`), incluindo quem fica com saldo `0`.
- Resposta: `{ cycle: { start, end }, totals: { total, paid, pending }, expenses: [...], balances: [...] }` — um único payload para a tela inteira, evitando 3 chamadas separadas e 3 vezes a lógica de "quais despesas contam neste ciclo".
- Não altera `indexByGroup`, `ExpenseManager.tsx` nem `GroupExpenseReportController` (specify §4) — método e rota totalmente novos, isolados dos já existentes.

## 4. Tela "Resumo do Grupo" (specify R3, R4)

- Novo componente `frontend/src/pages/GroupSummary.tsx`, nova rota `/groups/:id/summary` em `App.tsx` (mesmo grupo de rotas privadas de `InternalLayout`).
- Ao carregar, chama `GET /groups/{id}/expenses/summary` sem `cycles_ago` (ciclo fechado mais recente, default do backend). Setas de navegação (mesmo padrão visual de `ExpenseManager.tsx:330-347`) incrementam/decrementam `cycles_ago`, sempre `>= 0` (nunca navega para o ciclo em andamento — specify R3).
- Cabeçalho mostra o intervalo do ciclo (`cycle.start`–`cycle.end` formatado, ex. "11 dez – 10 jan"), não um nome de mês único, já que o ciclo pode atravessar dois meses calendário.
- 3 `Card` (Total de despesas / Pago / A pagar) direto de `totals`.
- Lista "Despesas do ciclo" (`List`/`ListItem` do MUI) com pagador, participantes e `Chip` `color="success"` ("Paga") / `"warning"` ("Pendente") a partir de `paid`.
- Bloco "Saldos por pessoa" a partir de `balances`, `Chip`/texto verde (`success`) para saldo positivo ("a receber"), vermelho (`error`) para negativo ("a pagar").

## 5. Ponto de entrada e troca de grupo (specify §2.8)

- Link "Resumo" novo em `Navbar.tsx` (ao lado de "Despesas"), apontando para `/summary`; novo componente `frontend/src/pages/SummaryEntry.tsx`, mesmo padrão de `ExpensesEntry.tsx:22-50` (1 grupo → redireciona direto para `/groups/{id}/summary`; mais de um → tela de escolha; zero → mensagem); nova rota `/summary` em `App.tsx`.
- 4º `IconButton` no card de cada grupo em `Dashboard.tsx:55-65`, levando direto para `/groups/{id}/summary`.
- Dropdown de troca de grupo (mockup: "Casa dos Amigos ▾") implementado só dentro de `GroupSummary.tsx` — `Select` populado por `GET /api/groups`, navega para `/groups/{novoId}/summary` ao mudar. Não é um seletor global reaproveitável em outras telas.

## 6. Estilo visual (specify §4, "Fora de escopo")

- Usa `theme.ts` atual sem alteração (sem rebrand). Cores semânticas do MUI (`success`/`warning`/`error`) em vez de hex customizado.

## 7. Ordem de execução

Dependência real: §1 (campo `closing_day`) precisa existir antes de §2 (helper de ciclo, que lê esse campo); §2 precisa existir antes de §3 (endpoint que usa o helper); §3 precisa existir antes de §4 (tela que consome o endpoint). §5 (pontos de entrada) só depende da rota `/groups/:id/summary` existir, podendo ser feito em paralelo ao miolo de §4. §6 não é task própria — é restrição a observar nas tasks de UI de §4/§5.

Ordem em `tasks.md`: §1 → §2 → §3 → §4 → §5, backend sempre antes do frontend que o consome, mesmo critério da versão anterior deste plano.
