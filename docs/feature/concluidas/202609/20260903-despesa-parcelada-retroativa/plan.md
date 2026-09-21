# Plan — Despesa parcelada retroativa

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260903

Toda mudança de código é em `backend/app/Http/Controllers/ExpenseController.php`,
método `store()`. Referências `:NNN` são linhas do estado atual desse arquivo
(feature branch, já com o merge de `20260903-notificacoes-in-app`).

---

## 0. Decisões transversais

### 0.1 "Ciclo fechado" aqui é só por data

Para esta feature, uma parcela está "em ciclo fechado" quando
`BillingCycle::statusFor($group->closing_day, $dateExpected, Carbon::now()) === 'closed'`
— ou seja, passada a janela de carência de 5 dias (`closesAt`). **Não** se consulta
`GroupCycleSnapshot::isManuallyClosedAndActive()` para Parcelada:

- fechamento manual só existe na competência **corrente** (`close()` opera sempre
  em `cyclesAgo = 0`); uma Parcelada que começa agora tem a 1ª parcela nesse mês —
  não é o caso "retroativo" que a feature trata;
- fechamento manual é reversível (`reopen()`), então travar cadastro por causa
  dele seria mais rígido do que o necessário para um compromisso parcelado em
  andamento.

Consequência consciente: passa a ser possível cadastrar uma Parcelada cuja 1ª
parcela cai numa competência corrente **fechada manualmente** (hoje `store()`
recusa). Nenhum teste existente quebra — `test_rejects_expense_with_date_payment_in_a_manually_closed_cycle`
usa `IN_CASH` (o `payloadFor()` sem override). `IN_CASH`/`FIXED` seguem passando
por `rejectIfCompetenceClosed()` inteiro (data **e** snapshot manual).

### 0.2 Sem helper novo / sem migration

`BillingCycle::statusFor()` já é público e faz exatamente a classificação
necessária. `ex_quotas` já tem `paid` / `paid_at` / `paid_by` no `$fillable` do
model (`backend/app/Models/Quota.php:15-24`) e gravados por `pay()` — a criação em
`store()` só passa a preenchê-los. Nada de coluna nova.

---

## 1. specify §2.1 + §2.3 — `store()` aceita Parcelada retroativa; recusa 100% no passado

Hoje (`:348-355`) um único bloco recusa qualquer `expense_type` cujo
`date_payment` caia em competência fechada:

```php
if ($response = $this->rejectIfCompetenceClosed($group, Carbon::parse($request->date_payment))) {
    return $response;
}
```

Passa a ser um ramo por tipo:

```php
if ($request->expense_type === 'IN_INSTALLMENTS') {
    // Parcelada retroativa: a trava não olha o date_payment (mês de início).
    // O que importa é existir ao menos uma parcela num ciclo ainda aberto —
    // senão a despesa só tocaria competências fechadas. Ver specify §2.1/§2.3.
    $allQuotasClosed = collect($request->quotas)->every(
        fn ($q) => BillingCycle::statusFor(
            $group->closing_day,
            Carbon::parse($q['date_expected']),
            Carbon::now()
        ) === 'closed'
    );

    if ($allQuotasClosed) {
        return response()->json([
            'error' => 'Esta despesa parcelada está inteira em competências já fechadas. Para registrá-la, ao menos a última parcela precisa cair num ciclo ainda aberto.',
        ], 422);
    }
} elseif ($response = $this->rejectIfCompetenceClosed($group, Carbon::parse($request->date_payment))) {
    return $response;
}
```

- **Posição:** no mesmo ponto de hoje (`:353`), depois do `$request->validate()`
  que já garante `quotas.*.date_expected` presente e `date`, e **antes** das
  validações de composição da Parcelada (`:367-378`, quantidade == installments,
  soma == total). Não depende delas.
- **`every` sobre `$request->quotas`:** a lista sempre existe (validação
  `quotas => required|array|min:1`). Uma parcela `future` conta como "não fechada"
  → basta uma parcela `future` ou `open` para o cadastro ser aceito.
- **Por que não estender `rejectIfCompetenceClosed()`:** aquele helper é
  "competência de uma data única"; Parcelada é multi-competência por natureza.
  Espalhar `expense_type` dentro dele confundiria os outros 4 chamadores
  (`update`, `destroy`, `pay` legado, `stopRecurrence`). O ramo fica em `store()`.
- **Por que não um parâmetro `force`/flag no payload:** o cliente não escolhe —
  a regra é do servidor e determinística pela data das parcelas.

## 2. specify §2.2 + §2.5 — parcelas de ciclo fechado nascem quitadas, sem notificação

Laço de criação das quotas (`:400-409`) passa a decidir o `paid` inicial por
parcela, só para `IN_INSTALLMENTS`:

```php
foreach ($request->quotas as $quotaData) {
    // IN_INSTALLMENTS retroativa: parcela cujo vencimento cai num ciclo já
    // fechado (por data) nasce quitada — nem o credor espera receber, nem os
    // devedores devem por ela. specify §2.2. Demais tipos e parcelas de ciclo
    // aberto/futuro nascem PENDENTE (o cliente nunca decide o status).
    $bornPaid = $request->expense_type === 'IN_INSTALLMENTS'
        && BillingCycle::statusFor(
            $group->closing_day,
            Carbon::parse($quotaData['date_expected']),
            Carbon::now()
        ) === 'closed';

    $expense->quotas()->create([
        'date_expected' => $quotaData['date_expected'],
        'number' => $quotaData['number'],
        'paid' => $bornPaid,
        'paid_at' => $bornPaid ? now() : null,
        'paid_by' => $bornPaid ? $request->user_payer_id : null,
        'value_quota' => $quotaData['value_quota'],
    ]);
}
```

- **`paid_by = $request->user_payer_id`** (o credor) — mesmo campo que `pay()`
  grava. O `user_payer_id` já foi validado como membro do grupo (`:339`).
- **`IN_CASH` / `FIXED`:** o `&&` curto-circuita → `paid = false`, `paid_at`/`paid_by`
  `null`, exatamente como hoje. Invariantes de
  `test_new_expense_quota_starts_as_pending_even_if_client_sends_paid_true` e
  `test_fixed_expense_quota_starts_as_pending...` intactas.
- **§2.5 (sem notificação):** o laço **não** chama `Notifier::expensePaid`. Hoje
  `store()` só chama `Notifier::expenseCreated($expense)` (`:414`) — isso
  permanece, uma vez, para a despesa. Nada a adicionar; a decisão é *não* somar um
  disparo de pagamento para parcela retroativa.
- **Selagem:** `store()` **não** chama `sealCycleIfSettled` — uma parcela isolada
  não quita um ciclo com outras pendências, e ciclo já selado não é tocado
  (specify §3).
- **Transação:** tudo dentro do `DB::beginTransaction()` atual (`:380`) — sem
  mudança de escopo transacional.

### Efeito por estado do ciclo (sem código novo — vem de graça do modelo atual)

| Ciclo passado da parcela | O que acontece | Fonte |
|---|---|---|
| **selado** (`settled_at`) | `summary()` serve o snapshot congelado → parcela invisível ali; `focusCycle()` pula ciclo selado | `ExpenseController::summary()` / `focusCycle()` |
| **`closed` não selado** | `computeCycleSummary()` recalcula ao vivo → parcela entra como linha `paid` e no acerto daquele mês (limitação aceita, specify §2.2) | `computeCycleSummary()` itera todas as entries |
| **menor ciclo aberto / carência / futuro** | parcela `paid = false` → conta como pendência normal a partir dali | — |

## 3. specify §2.4 — mensagem acionável

Coberta em §1: o 422 do caso "100% no passado" tem texto próprio dizendo que ao
menos a última parcela precisa cair num ciclo aberto. A mensagem genérica de
`rejectIfCompetenceClosed()` (`:303`) **não muda** — continua servindo
`IN_CASH`/`FIXED`.

## 4. Testes — `backend/tests/Feature/ExpenseControllerStoreTest.php`

Relógio fixo por teste com `Carbon::setTestNow()` (o `setUp()` já fixa
`2026-08-15`; casos novos sobrescrevem). Grupo de teste tem `closing_day = null`
→ ciclo = mês calendário, `closesAt` = dia 5 do mês seguinte.

1. **`test_installments_expense_starting_in_a_closed_cycle_is_created_with_past_quotas_paid`**
   — `setTestNow('2026-09-20')`. Parcelada 6x, `date_payment '2026-06-05'`, quotas
   `2026-06-05 … 2026-11-05`. Espera **201**; quotas de jun/jul/ago com
   `paid = 1`, `paid_by = <credor>`, `paid_at` não nulo; quotas de set (aberto) /
   out / nov (futuro) com `paid = 0`.
2. **`test_installments_expense_entirely_in_closed_cycles_is_rejected`** —
   `setTestNow('2026-09-20')`. Parcelada 3x, quotas `2026-05-10`, `2026-06-10`,
   `2026-07-10` (todas `closed`). Espera **422** com a mensagem de §3 e
   `assertDatabaseMissing('ex_expenses', ['group_id' => $group->id])`.
3. **`test_in_cash_expense_in_a_closed_cycle_is_still_rejected`** — garantir que
   `test_rejects_expense_with_date_payment_in_an_automatically_closed_cycle` (já
   existe, `IN_CASH`) e `..._fixed_expense_...` continuam **422**. Sem novo teste
   se os existentes já cobrem; só rodar e confirmar verdes.
4. **`test_installments_expense_quotas_in_open_cycles_start_as_pending_even_if_client_sends_paid_true`**
   — renomeia/ajusta o docblock do atual
   `test_installments_expense_quotas_start_as_pending_even_if_client_sends_paid_true`
   (cenário jan-ago/2026 = ciclos abertos; segue **verde**), deixando claro que a
   regra "cliente não decide `paid`" continua — o servidor é que passa a marcar
   parcela de ciclo **fechado**.
5. **`test_summary_pending_starts_at_the_first_open_cycle_for_a_retroactive_installments_expense`**
   (pode ficar em `ExpenseControllerSummaryTest.php`) — cria a Parcelada retroativa
   do caso 1, sela jun/jul via `GroupCycleSnapshot` com `settled_at`, chama
   `summary()` do ciclo corrente → `totals.pending` == valor só da parcela de
   setembro (as retroativas não contam).

## 5. Ordem de execução

Sem dependência interna — é **uma** mudança de backend em `store()` (o ramo de
guarda §1 e o `paid` inicial §2 são o mesmo método, mesmo diff). Código e testes
PHPUnit andam juntos na mesma task (Constitution §2.2 — regra de fluxo financeiro
nova exige teste).

Antes do PR: `./vendor/bin/pint` limpo, `php artisan test` verde, agent
`security-reviewer` (mexe em `ExpenseController` / dado financeiro) e
`pr-readiness-checker`. Gate humano: revisão do PR antes do merge em `dev`
(Constitution §5.2).
