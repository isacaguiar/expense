# Implementation — Detalhe da despesa: tipo, parcela e valores por pagador

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260904

---

## 1. Desvios do fluxo padrão

**TASK-003 não produz código.** É um script SQL executado manualmente pelo usuário no banco de produção (gate da Constitution §5.2) — não entra no PR como mudança de comportamento, só como documento. Mesmo desvio já registrado na feature `20260904-parcela-retroativa-contabilizacao` (TASK-002).

Branch da feature: `feature/20260904-detalhe-despesa-tipo-parcela-valores`, criada a partir de `dev` em 2026-09-04 (após o merge do PR #151). TASK-001 vai direto nela; TASK-002 em sub-branch `frontend/20260904-detalhe-despesa-tipo-parcela-valores-TASK-002`, mergeada localmente (`ADR-003`).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-09-04 | IA (Claude) | Ver detalhamento abaixo | Campos aditivos; `isFixed` mantido |
| TASK-002 | Concluída | 2026-09-05 | IA (Claude) | Ver detalhamento abaixo | Achado extra: snapshot antigo sem `valuePerPerson` |

### TASK-001 — detalhamento

Arquivo alterado: `backend/app/Http/Controllers/ExpenseController.php`.

1. **`collectCycleEntries()`** — as três origens de entry passam a propagar `'quotaNumber'`:
   `$direct` e `$fixedCandidates` usam `$quota->number ?? null` (ocorrência FIXED ainda projetada
   não tem Quota); o laço de `$installmentQuotas` usa `$quota->number`, que é o dado que interessa.
2. **`computeCycleSummary()`** — o item de `expenses` ganha `expenseType`, `installmentNumber`,
   `installmentsTotal` e `totalValue`, logo abaixo de `isFixed`, que **permanece** (filtro/ícone de
   `ExpenseManager`, `Payments` e testes existentes dependem dele).

Testes novos em `backend/tests/Feature/ExpenseControllerSummaryTest.php`:
- `test_installments_expense_exposes_type_installment_number_and_total_value_per_cycle` — parcelada
  de 3 parcelas consultada em 3 ciclos consecutivos (`cycles_ago` 2/1/0) devolve `installmentNumber`
  1, 2 e 3, com `value` continuando a ser o valor da parcela (100) e `totalValue` o da despesa (300).
- `test_in_cash_and_fixed_expenses_expose_their_type_without_installment_semantics` — `IN_CASH`
  devolve `expenseType: 'IN_CASH'`/`isFixed: false`; ocorrência `FIXED` projetada devolve
  `expenseType: 'FIXED'` com `installmentNumber: null`.

| Comando | Resultado |
|---|---|
| `php artisan test --filter='test_installments_expense_exposes_type_installment_number_and_total_value_per_cycle\|test_in_cash_and_fixed_expenses_expose_their_type_without_installment_semantics'` (RED, antes do código) | 2 failed (2 assertions) — `Undefined array key "expenseType"` |
| `php artisan test --filter=ExpenseControllerSummaryTest` (GREEN) | 25 passed (177 assertions) |
| `./vendor/bin/pint app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerSummaryTest.php` | PASS, 2 files |
| `php artisan test` (suíte completa) | 330 passed (1065 assertions) — sem regressão |

Ajuste feito durante o RED→GREEN: os asserts de valor monetário passaram de `assertSame(300.0, ...)`
para `assertEqualsWithDelta(300, ..., 0.01)` — `json_encode` serializa float sem casa decimal como
inteiro (`300.0` → `300`), mesmo comportamento que `value`/`valuePerPerson` já tinham.

### TASK-002 — detalhamento

Branch: `frontend/20260904-detalhe-despesa-tipo-parcela-valores-TASK-002`, a partir da branch da feature.

1. **`frontend/src/hooks/useGroupCycle.ts`** — novo `SummaryExpenseType` e 4 campos **opcionais**
   em `SummaryExpense` (`expenseType`, `installmentNumber`, `installmentsTotal`, `totalValue`),
   com o mesmo comentário/justificativa de `payerAvatarUrl?`/`participantDetails?`: ciclo selado é
   servido do snapshot congelado e não os terá.
2. **`frontend/src/pages/ExpenseManager.tsx`**:
   - `detailTypeLabel()` — função pura no topo do arquivo: `FIXED`→`Fixa`, `IN_CASH`→`À Vista`,
     `IN_INSTALLMENTS`→`Parcelada n/N` (ou só `Parcelada` se faltar algum dos números), com
     fallback para `isFixed ? 'Fixa' : 'Variável'` quando `expenseType` vier `undefined`.
   - `renderDetailPayers()` — uma linha por pagador (`UserAvatar` + nome + `valuePerPerson`),
     lendo `participantDetails` com fallback para `participants`; o credor (`id === userPayerId`)
     ganha a marcação `(credor)`.
   - Modal: chip usa `detailTypeLabel`; parcelada ganha a linha
     `Total da despesa: R$ X em Nx`; a linha do credor ganha `Pagou R$ {value}`; a seção
     "Pagadores" deixa de ser `participants.join(', ')`.
   - O `renderTypeIcon` da listagem **não muda** (continua Fixa/Variável) — escopo é só o modal.

Testes em `frontend/src/pages/ExpenseManager.test.tsx` (novo describe "modal de detalhes: tipo,
parcela e valor por pagador"): parcelada mostra `Parcelada 3/6` + total + valor por pagador +
`(credor)`; `IN_CASH` mostra `À Vista` sem linha de total; payload sem os campos novos cai no
rótulo antigo e não quebra.

| Comando | Resultado |
|---|---|
| `npx vitest run src/pages/ExpenseManager.test.tsx` (1ª execução) | 1 failed, 39 passed — ver achado abaixo |
| `npx vitest run src/pages/ExpenseManager.test.tsx` (após correção) | 40 passed |
| `npx tsc --noEmit` | sem erro |
| `npx vitest run` (suíte completa) | 37 arquivos, 239 passed |

**Achado durante a execução** (corrigido nesta task, não virou backlog por ser regressão
introduzida pela própria mudança): o teste pré-existente
`shows description, type, status, value, date, credor and pagadores...` usa um fixture **sem**
`valuePerPerson`, e a lista nova quebrava com `Cannot read properties of undefined`. Como
`summary()` serve snapshot congelado de ciclos selados — e snapshot antigo pode ter sido gravado
antes de `valuePerPerson` existir —, a correção foi guardar o campo em `renderDetailPayers()`
(mostra o nome sem valor em vez de quebrar), e não "consertar o fixture". O teste antigo passou a
valer como regressão desse caso; o assert de `'Isac, Maria'` foi trocado pelos nomes em linhas
separadas, que é o comportamento novo pedido.
