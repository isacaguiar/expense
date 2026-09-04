# Plan — ExpenseManager: mês e data corretos

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Projetar `IN_INSTALLMENTS` mês a mês (specify §2.1)

- `backend/app/Http/Controllers/ExpenseController.php`, dentro de `indexByGroup`:
  - `$direct` (linha 43-53) ganha `->where('expense_type', '!=', 'IN_INSTALLMENTS')` na condição já existente (hoje só filtra por `group_id`/`deleted`/ano/mês/regra de `FIXED`) — evita duplicar a 1ª parcela, que também seria capturada por essa query no mês de criação.
  - Novo conjunto `$installmentQuotas`, consultando `Quota` (não `Expense`):
    ```php
    $installmentQuotas = Quota::whereYear('date_expected', $data['year'])
        ->whereMonth('date_expected', $data['month'])
        ->whereHas('expense', function ($query) use ($groupId) {
            $query->where('group_id', $groupId)
                ->where('deleted', false)
                ->where('expense_type', 'IN_INSTALLMENTS');
        })
        ->with('expense.payers')
        ->get();
    ```
    `whereHas` em vez de eager-load simples porque o filtro (`group_id`/`deleted`/`expense_type`) precisa restringir quais `Quota` entram no resultado, não só carregar a relação depois.
  - Novo `$mapQuotaRow` (mesmo formato de linha que `$mapRow`, adaptado para `Quota`):
    ```php
    $mapQuotaRow = fn (Quota $quota) => [
        'id' => $quota->expense->id,
        'description' => $quota->expense->description,
        'date' => $quota->date_expected->toDateString(),
        'value' => $quota->value_quota,
        'payerName' => $quota->expense->payers->pluck('name')->implode(', '),
        'isFixed' => false,
    ];
    ```
  - `$expenses` (linha 67-74) ganha `->concat($installmentQuotas->map($mapQuotaRow))` antes do `sortBy('date')->values()` final.
  - Import novo: `use App\Models\Quota;` já existe no arquivo (usado em `collectCycleEntries`) — nenhuma mudança de `use` necessária.
  - Sem migration, sem mudança de contrato de resposta (mesmo formato de linha `{id, description, date, value, payerName, isFixed}` já usado pelos outros dois conjuntos).

## 2. Corrigir parse de data em `ExpenseManager.tsx` (specify §2.2)

- `frontend/src/pages/ExpenseManager.tsx`: adicionar uma função `formatDate` no escopo do módulo (mesmo padrão de `GroupSummary.tsx:58-62`, adaptado ao formato já usado aqui — sem as opções `{ day: '2-digit', month: 'short' }` de `GroupSummary`, já que `ExpenseManager` usa o formato `pt-BR` completo hoje):
  ```ts
  const formatDate = (dateStr: string): string => {
    const [year, month, day] = dateStr.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString('pt-BR');
  };
  ```
- Linha 243: trocar `new Date(exp.date).toLocaleDateString('pt-BR')` por `formatDate(exp.date)`.

## 3. Ordem de execução

Sem dependência técnica entre os dois itens (backend e frontend, arquivos e times diferentes) — mas ambos pequenos o bastante para uma task cada, na mesma feature. Ordem em `tasks.md`: backend primeiro (012, MEDIA/maior) depois frontend (013, MEDIA/menor).
