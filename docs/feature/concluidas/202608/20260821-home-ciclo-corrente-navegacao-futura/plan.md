# Plan — Home: ciclo corrente, navegação futura, status do ciclo e fechamento congelado

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. `BillingCycle` passa a calcular o ciclo que contém a referência (specify §2.1)

- `backend/app/Support/BillingCycle.php`: renomear `closedCycle()` para `cycleFor()` (nome antigo descreve o comportamento revertido). Mesma checagem de borda já existente (`referenceStart > currentBoundary`), mas invertendo para qual mês ela aponta: se já passou do fechamento deste mês, o ciclo corrente (`cyclesAgo=0`) é o **próximo** mês-âncora (ainda aberto); senão, é este mês-âncora mesmo (`containingMonth`).
- `targetMonth = containingMonth->copy()->subMonths($cyclesAgo)` — `Carbon::subMonths` aceita negativo (= `addMonths`), então `cyclesAgo` negativo anda pro futuro sem lógica extra. `boundaryFor()` (clamp de dia curto de mês) não muda.
- Retorno passa de `array{start, end}` para `array{start, end, status}`, com `status = $end->lt($referenceStart) ? 'closed' : ($start->gt($referenceStart) ? 'future' : 'open')`.
- Validação de que a navegação "para trás" não quebra: para `cyclesAgo=0` na semântica nova, o intervalo é idêntico ao que `cyclesAgo=1` produzia na semântica antiga — tudo que hoje é acessível continua acessível, só desloca um índice.
- Único chamador em produção (`ExpenseController::summary`) e o teste (`BillingCycleTest`) são ajustados na mesma mudança.

## 2. Corrigir `collectCycleEntries` para projetar parcelas por `date_expected` (specify §2.4)

- `backend/app/Http/Controllers/ExpenseController.php`, método `collectCycleEntries`: a query `$direct` (hoje `Expense::whereBetween('date_payment', ...)`) passa a excluir `IN_INSTALLMENTS` (`where('expense_type', '!=', 'IN_INSTALLMENTS')` somado à condição de Fixa já existente).
- Nova consulta paralela para `IN_INSTALLMENTS`: `Quota::whereBetween('date_expected', [$start, $end])->whereHas('expense', fn ($q) => $q->where('group_id', $groupId)->where('deleted', false))->with('expense.payer', 'expense.payers')`, mapeando cada quota para uma entrada `{expense, date: quota.date_expected, value: quota.value_quota, paid: quota.paid}`.
- Mesmo padrão já usado em `indexByGroup` desde `docs/feature/concluidas/202608/20260821-expense-manager-mes-e-data-corretos/` — reaproveitar a lógica, não reinventar.

## 3. Foto do ciclo fechado — tabela `ex_group_cycle_snapshots` (specify §2.5)

- Migration nova: `ex_group_cycle_snapshots` — `id`, `group_id` (FK `ex_groups`, cascade), `cycle_start` (`date`), `cycle_end` (`date`), `totals` (`json`), `expenses` (`json`), `balances` (`json`), timestamps, `unique(group_id, cycle_start)`.
- Model novo `GroupCycleSnapshot` (`backend/app/Models/GroupCycleSnapshot.php`): `$fillable` = todas as colunas acima exceto `id`/timestamps; `$casts` = `['totals' => 'array', 'expenses' => 'array', 'balances' => 'array', 'cycle_start' => 'date', 'cycle_end' => 'date']`.
- `ExpenseController::summary`: depois de obter `$cycle = BillingCycle::cycleFor(...)`:
  - Se `$cycle['status'] !== 'closed'`: fluxo atual, 100% ao vivo (`collectCycleEntries` + cálculo de `totals`/`expenses`/`balances`), nada persiste.
  - Se `$cycle['status'] === 'closed'`: `$snapshot = GroupCycleSnapshot::where('group_id', $groupId)->where('cycle_start', $start->toDateString())->first()`. Se existir, montar a resposta a partir de `$snapshot->totals/expenses/balances` diretamente. Se não existir, computar exatamente como no fluxo ao vivo, e então:
    ```php
    try {
        GroupCycleSnapshot::create([...]);
    } catch (\Illuminate\Database\QueryException $e) {
        // violação do unique(group_id, cycle_start): outra requisição já criou;
        // reconsultar e usar o que já foi persistido
        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)->where('cycle_start', $start->toDateString())->firstOrFail();
    }
    ```
  - A resposta JSON final é idêntica em formato ao que a API já devolve hoje (`cycle`, `totals`, `expenses`, `balances`) — clientes existentes não quebram, só ganham `cycle.status`.
- Extrair o bloco de cálculo de `totals`/`expenses`/`balances` (hoje inline em `summary`, linhas ~300-353) para um método privado (ex. `computeCycleSummary($groupId, $group, $start, $end): array`), reaproveitado tanto no caminho "ao vivo" (open/future) quanto na primeira materialização de um ciclo fechado — evita duplicar a lógica de cálculo entre os dois caminhos.

## 4. Bloqueio de edição/exclusão de despesa em ciclo fechado (specify §2.6)

- `ExpenseController::update($request, $id)`: antes de `$expense->update(...)`, se `$expense->expense_type !== 'FIXED'`, calcular `BillingCycle::cycleFor($group->closing_day, Carbon::now(), ...)` para a data `$expense->date_payment` (calcular `cyclesAgo` implícito não é necessário — basta um novo método auxiliar, ex. `BillingCycle::statusFor(?int $closingDay, Carbon $date, Carbon $reference): string`, que devolve só o `status` de um ciclo dado por uma data qualquer, sem precisar de `cyclesAgo`). Se `status === 'closed'`, retornar `response()->json(['error' => 'Não é possível alterar uma despesa de um ciclo já fechado.'], 422)` antes de qualquer mutação.
- `ExpenseController::destroy($id)`: mesma checagem, mesma exceção para `FIXED` (sem bloqueio).
- `ExpenseController::stopRecurrence($expenseId, $request)`: depois da validação existente (`cutoff->lt($creationMonth)`), calcular `BillingCycle::statusFor($group->closing_day, $cutoff, Carbon::now())`; se `closed`, retornar `422` antes de `$expense->update(['fixed_recurrence_ends_at' => $cutoff])`.
- Novo método auxiliar em `BillingCycle` (`statusFor`) reaproveita a mesma lógica de `boundaryFor`/comparação de `cycleFor`, sem duplicar código — internamente pode chamar `cycleFor` com um `cyclesAgo` calculado a partir da diferença de meses entre `$date` e a referência, ou (mais simples) receber `$date` como a própria referência e sempre pedir `cyclesAgo=0`, já que `cycleFor` já aceita qualquer data de referência.

## 5. `frontend/src/pages/GroupSummary.tsx` (specify §2.2, §2.3)

- Tipo `SummaryCycle` ganha `status: 'closed' | 'open' | 'future'`.
- Botão "próximo" (linha ~134-140): remover `Math.max(prev - 1, 0)` e `disabled={cyclesAgo === 0}` → `setCyclesAgo(prev => prev - 1)`, simétrico ao botão "anterior".
- Novo `Chip` no cabeçalho (ao lado do intervalo de datas, dentro do mesmo `Box` das setas de navegação): mapear `status` → `{label, color}`: `closed` → `{'Ciclo fechado', 'default'}`; `open` → `{'Ciclo em andamento', 'info'}`; `future` → `{'Ciclo futuro', 'default'}` (com `variant="outlined"` para diferenciar visualmente de `closed`). Cores escolhidas para não colidir com o `Chip` "Paga"/"Pendente" já existente (`success`/`warning`).

## 6. Ordem de execução

Dependência real: 1 é pré-requisito de tudo (status do ciclo é usado em 3, 4 e 5). 2 é independente de 1, mas precisa estar pronto antes de 3 (a foto da primeira leitura de um ciclo fechado precisa computar corretamente parcelas de `IN_INSTALLMENTS`). 3 depende de 1 e 2. 4 depende de 1 (precisa do método `statusFor`). 5 (frontend) depende de 1 e 3 para ser testável fim-a-fim contra a API real, mas pode ser codado em paralelo. Critério de ordenação em `tasks.md`: dependência técnica primeiro, depois back-to-front dentro de cada grupo independente.
