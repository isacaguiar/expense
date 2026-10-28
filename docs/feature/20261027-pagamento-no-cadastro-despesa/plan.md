# Plan — Pagamento no cadastro de despesa

> Traduz `specify.md` (v1.3) em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261027

**Status: rascunho para revisão do usuário.** `tasks.md` só depois da aprovação deste plano (`docs/sdd/README.md`, gates entre etapas). Nenhum código foi escrito.

---

## 0. Restrições globais e decisões do plan

Valem para todas as seções abaixo (valores copiados do specify).

- **Contrato aditivo** (Constitution §4 item 1): campos novos opcionais; o JSON de hoje segue válido. **Migration só aditiva** (§4 item 2), nullable.
- **Nomes de API:** `comprovante` (arquivo, `image`, até 5120 KB), `descricao_pagamento` (string até 500), `client_request_id` (uuid). Resposta: `message`, `expense_id`, `payment_registered`, e `already_existed` no replay.
- **`born_paid` nunca representa pagamento comum.** `paid_by` é o credor (`user_payer_id`). `paid_at` é o `now()` do cadastro, o mesmo em todas as quotas da compra.
- **Nenhum cálculo muda** (R14): não se edita `CycleSummaryService::compute`, `grossDebts`, `ClosingBreakdown`, `PersonalOverviewService` nem os relatórios.
- **Testes:** PHPUnit para toda regra nova (Constitution §2 item 2), TypeScript estrito. `vendor/bin/pint --test`, `phpunit` e `tsc --noEmit` limpos antes do PR.
- **Fluxo:** branch `feature/20261027-pagamento-no-cadastro-despesa` a partir de `dev` (ADR-003); commits locais; **sem push nem PR antes da validação local e do aval escrito do usuário**. O merge em `main` roda `migrate --force` no deploy (ADR-008): é gate humano.

Decisões que o plan tomou (o specify deixou em aberto; o usuário pode trocar):

| # | Decisão do plan | Por quê |
|---|---|---|
| P1 | **Rota nova** `POST /api/expenses/{expenseId}/undo-purchase` para desfazer a compra, em vez de parâmetro em `unpay` | `unpay` fica intocado (contrato e `cycles_ago` não se misturam); a regra de janela fica explícita |
| P2 | O desfazer em lote vale **só para `IN_INSTALLMENTS`**; outros tipos dão 422 apontando o "Desfazer pagamento" da ocorrência | Em `FIXED` as quotas pagas de meses diferentes não são "uma compra"; em À Vista o `unpay` já resolve |
| P3 | A chave vai **no corpo** (`client_request_id`), não em cabeçalho | Funciona igual em JSON e em multipart, sem depender de CORS |
| P4 | Replay devolve **200** com `already_existed: true`; criação devolve 201 | O cliente distingue sem ler o corpo |
| P5 | `canUndoPurchase` entra em `summary()` **só quando verdadeiro** (ausente = falso) | Não quebra teste que compara o JSON exato nem o snapshot antigo |
| P6 | Timeout do cadastro: **60 s** (`EXPENSE_SAVE_TIMEOUT_MS`) | Foto de 5 MB em rede móvel; seguro porque o reenvio é idempotente |
| P7 | A contagem de referências do arquivo olha `ex_quotas.payment_proof_path` **e** `ex_settlement_confirmations.proof_path` | Barato e impede apagar um arquivo de outro fluxo por engano |
| P8 | `Notifier::expensePaid` ganha parâmetro opcional de valor; o texto da notificação não muda | O valor total da compra vai no `data`, sem template novo |
| P9 | A chave de idempotência vale para **todo** `POST /expenses`, com ou sem pagamento | A proteção contra reenvio não depende de haver pagamento |
| P10 | No aviso `expense_paid`, `actorName` continua sendo o credor | O pagamento é dele (R4/R5); o aviso ao credor (`expense_paid_on_behalf`) diz quem registrou |

## 1. Testes de caracterização primeiro (specify §2.13, §3.7, NR-01 a NR-10)

**Primeira entrega, antes de qualquer código da feature**, e verde no código atual.

- **Arquivos:** `backend/tests/Feature/PurchasePaymentNonRegressionTest.php` (novo) e o trait `backend/tests/Concerns/BuildsPurchaseScenarios.php` (novo, ao lado de `FailsToWriteProofs`).
- **Trait, interface:** `makeGroup(int $members, ?int $closingDay = null): array{group: Group, users: User[]}`; `makePurchase(Group $g, User $creditor, array $participants, string $type, float $total, string $start, int $n = 1): Expense` (cria despesa e quotas pelo mesmo rateio de `InstallmentSchedule`); `markPaid(Expense $e, ?string $proofPath = null, ?string $note = null): void` (põe `paid=true`, `paid_at`, `paid_by = credor` nas quotas não `born_paid`). É a "forma equivalente por factory" do specify.
- **Cada teste** monta duas compras idênticas (uma com `markPaid`, outra sem) e compara a saída:
  - `test_compute_balances_settlements_and_value_per_person_are_identical` (NR-01);
  - `test_totals_paid_and_pending_differ_only_by_the_paid_quotas_of_each_cycle` e `test_installment_paid_totals_sum_to_total_value_across_all_cycles` com `Carbon::setTestNow` em cada ciclo, N = 3 (100,00 → 33,33 / 33,33 / 33,34) e N = 12 (NR-02);
  - `test_changing_paid_at_and_paid_by_changes_no_output` sobre `summary`, `me/overview`, `closing-report`, `pending-cycles`, `gross-debts`, `cycles` (NR-03);
  - `test_settlement_and_sealing_follow_the_equivalent_state` e `test_focus_gives_the_same_answer_for_a_fully_paid_purchase`, novo, que compara `focus()` com a varredura completa sem editar o teste de `CycleSummaryServiceTest.php:516` (NR-04);
  - `test_closing_breakdown_is_identical` (NR-05); `test_cycle_history_and_snapshot_keep_the_same_keys` (NR-06);
  - `test_gross_debts_skips_a_paid_quota_and_lists_it_again_when_unpaid` (NR-07, fixa o comportamento da D13);
  - `test_overview_owe_and_receive_are_identical_and_items_follow_paid` (NR-08);
  - `test_annual_reports_monthly_expenses_and_index_are_identical` (NR-09).
- **Formas** (§3.7): À Vista; Parcelada de 2, 3 e 12; Fixa no mês inicial e no seguinte; Parcelada retroativa com `born_paid`; credor que participa e que não participa; 2 e 3 membros; dívidas em sentido contrário.
- **Verificação:** `vendor/bin/phpunit --filter PurchasePaymentNonRegressionTest` verde no código atual, e a suíte inteira sem edição de teste existente (NR-10). Depois da §4, os mesmos cenários ganham a variante "cadastrado pelo `POST /api/expenses`" (task final).

## 2. Idempotência: migration, impressão digital e modelo (specify §2.5, R12, D7)

- **Migration** `backend/database/migrations/2026_10_27_000000_add_client_request_id_to_ex_expenses_table.php`: `client_request_id` `char(36)` nullable, `request_fingerprint` `char(64)` nullable, `unique(['user_creator_id', 'client_request_id'])` (o MySQL aceita vários `NULL`). `down()` remove o índice e as colunas. Estilo igual a `2026_10_21_000000_add_payment_note_to_ex_quotas_table.php`.
- **Model:** `Expense::$fillable` ganha as duas colunas.
- **Impressão digital:** classe pura `backend/app/Support/ExpenseRequestFingerprint.php`, no estilo de `BillingCycle` (array entra, string sai).
  - `public static function make(array $input, ?string $proofSha256): string`.
  - Normaliza antes do hash: `group_id` e `user_payer_id` como inteiros; `date_payment` e `date_expected` em `Y-m-d`; `description` e `descricao_pagamento` aparados (vazio vira `null`); `total_value` e `value_quota` em centavos inteiros; `payers` ordenados; `quotas` ordenadas por (`date_expected`, `number`); o resultado é `sha256` do JSON.
  - `$proofSha256` é o `hash_file('sha256', ...)` do conteúdo da foto, ou `null`.
- **Testes:** `backend/tests/Unit/ExpenseRequestFingerprintTest.php`: `test_it_ignores_payers_order`, `test_it_treats_string_10_and_float_10_00_alike`, `test_it_ignores_edge_whitespace_in_texts`, `test_it_changes_with_value_date_payer_participant_or_photo` (CA-34).

## 3. Arquivo compartilhado entre quotas (specify §2.11, R18)

- **`ProofStorage::deleteIfUnreferenced(?string $path): void`** em `backend/app/Support/ProofStorage.php`: não faz nada se o caminho é vazio ou se alguma linha de `ex_quotas.payment_proof_path` ou `ex_settlement_confirmations.proof_path` o referencia (P7); senão chama `delete`. `delete` continua existindo para o arquivo recém-gravado que ninguém referencia ainda.
- **Ordem corrigida nos três pontos de `ExpenseController`**: o arquivo só é removido **depois** de o banco deixar de apontar para ele.
  - `unpay()` (`:1188`, hoje apaga antes do `update`): passa a atualizar e depois chamar `deleteIfUnreferenced($previousPath)`;
  - `pay()` repetido (`:1117-1121`) e `updatePaymentProof()` (`:1318-1322`): trocam `ProofStorage::delete` por `deleteIfUnreferenced`.
- **Testes** (`backend/tests/Feature/ProofStorageReferencesTest.php`, novo, com todos os casos; `ExpenseControllerPayTest`, `ExpenseControllerPaymentProofTest` e `ProofStorageTest` ficam sem edição): `test_unpay_of_one_quota_keeps_a_file_another_quota_uses`, `test_the_last_reference_removes_the_file`, `test_replacing_the_proof_of_one_quota_keeps_the_old_file_for_the_others`, `test_a_settlement_confirmation_reference_keeps_the_file` (CA-28). Os testes existentes dessas rotas passam sem edição (`git diff` vazio nesses arquivos).

## 4. `store()`: pagamento, atomicidade e replay (specify R1 a R12, R21, F1 a F15)

Arquivo: `backend/app/Http/Controllers/ExpenseController.php::store` (`:429-600`). Regra de negócio nova fica em `backend/app/Services/ExpensePaymentService.php` (novo; Constitution §1 item 3).

**Interface de `ExpensePaymentService`:**
- `paidAttributes(int $creditorId, ?string $proofPath, ?string $note): array` devolve `paid`, `paid_at`, `paid_by`, `payment_proof_path`, `payment_note`, `born_paid=false`.
- `earliestOccurrence(string $type, string $datePayment, array $quotas): Carbon` (à vista e fixa: `date_payment`; parcelada: menor `date_expected`).
- `isFuture(Group $g, Carbon $date): bool` (`BillingCycle::statusFor(...) === 'future'`).

**Nova ordem de `store()`:**

1. Validação existente (grupo, membro) e a do corpo, mais `comprovante` `nullable|image|max:5120`, `descricao_pagamento` `nullable|string|max:500`, `client_request_id` `nullable|uuid`. Foto inválida dá 422 aqui, antes de tudo (R2, CA-16).
2. `paymentRequested = hasFile('comprovante') || filled(descricao_pagamento)` (R1/R2, `filled`, de modo que `"0"` vale). Se a medição de CA-02 mostrar que o `TrimStrings` do Laravel não remove NBSP nem U+200B, o controller normaliza `descricao_pagamento` removendo espaços Unicode (`\p{Z}`) e `\x{200B}` das pontas antes do `filled`; o resultado da medição vai ao `implementation.md`.
3. **Replay:** se há `client_request_id`, calcula a impressão digital (§2) e busca `Expense` por (`user_creator_id`, chave).
   - Achou, `deleted` → **409** (F14).
   - Achou, impressão digital diferente → **409** (F8).
   - Achou, igual → **200** com `already_existed: true`, sem gravar arquivo, sem notificar (R11, CA-32).
4. Regras de hoje (competência fechada, retroativa, `born_paid`, soma de quotas) sem mudança.
5. Se `paymentRequested` e a ocorrência inicial é futura → **422**, nada criado (R6, CA-12).
6. Grava a foto **depois** das validações, com `ProofStorage::store` (falha aborta antes do banco, F3).
7. `DB::transaction`: cria despesa (com `client_request_id` e `request_fingerprint`), pagadores e quotas. Cada quota não `born_paid` nasce com `paidAttributes(...)` quando `paymentRequested`; sem pagamento, nasce como hoje. **Não há segundo `UPDATE`**: o pagamento é parte da criação (R7, R10). `FIXED` cria uma quota, que é a inicial.
8. Falha: `QueryException` de chave duplicada na chave de idempotência dá rollback, remove o **próprio** arquivo (`ProofStorage::delete`), relê a existente e responde como o replay (F10, CA-35). Qualquer outra exceção dá rollback, remove o arquivo e responde `500` **sem `details`**, com `Log::error` (D10, F4, CA-18).
9. **Fora do `try`**, depois do commit, cada passo em `try/catch` próprio com `Log::warning` (R11, F5): `Notifier::expenseCreated`; `Notifier::expenseBornPaid` se houver; se pagou, `Notifier::expensePaid($expense, $primeiraQuotaPaga, $valorTotalPago)` uma vez (R21, P8); se o criador não é o credor, `Notifier::expensePaidOnBehalf`; se há foto, `dispatch(...)->afterResponse()` com `WhatsAppNotifier::expenseProofPaid($primeiraQuotaPaga)` uma vez (a quota é relida pelo id dentro do `dispatch`, como `pay()` faz em `:1137-1143`). **Não sela o ciclo** (D8, CA-26).
10. Resposta `201 {message, expense_id, payment_registered}`.

- **Notifier** (`backend/app/Support/Notifier.php`): `expensePaid(Expense, Quota, float|string|null $amount = null)` (o `data.amount` usa `$amount ?? $quota->value_quota`); novo `expensePaidOnBehalf(Expense $expense, int $quotasCount): void`, tipo `expense_paid_on_behalf`, destinatário `user_payer_id`, `data`: `actorName` (criador), `groupId`, `groupName`, `expenseId`, `expenseDescription`, `quotasCount`; só in-app (D4, R21).
- **Testes** (`backend/tests/Feature/ExpenseControllerStorePaymentTest.php` e `ExpenseControllerStoreIdempotencyTest.php`, novos; `NotifierTriggersTest` ganha os casos de aviso): um teste por critério CA-01 a CA-27 e CA-32 a CA-35, nomeados pelo critério (por exemplo `test_in_installments_pays_every_non_born_paid_quota_with_the_same_file_and_note` para CA-07, `test_installment_quotas_from_the_client_out_of_order_with_repeated_number_are_all_paid` para CA-08, `test_a_failed_database_write_after_the_file_removes_the_file_and_hides_details` para CA-18). Reaproveita `FailsToWriteProofs` (CA-17) e `Carbon::setTestNow`. O arquivo `ExpenseControllerStoreTest` não é editado (CA-01).

## 5. Desfazer a compra e janela de correção (specify R19, R20, D11, F11, F12)

- **Rota:** `POST /api/expenses/{expenseId}/undo-purchase` em `backend/routes/api.php`, junto de `pay`/`unpay`/`payment-proof` (`:62-64`); método `ExpenseController::undoPurchase($expenseId)`.
- **`ExpensePaymentService`:**
  - `undoPurchase(Expense $expense): array` (devolve os caminhos de arquivo liberados) roda numa `DB::transaction` com `lockForUpdate` nas quotas. Recusas com 422 antes de gravar: tipo diferente de `IN_INSTALLMENTS` (P2); nenhuma quota paga e não `born_paid`; janela fechada. Se passa, zera `paid`, `paid_at`, `paid_by`, `payment_proof_path`, `payment_note` de todas as elegíveis.
  - `windowOpen(Expense $expense, Collection $eligibleQuotas): bool`: cada quota está **fora** de ciclo encerrado quando `BillingCycle::statusFor(closing_day, date_expected, now) !== 'closed'` **e** o `GroupCycleSnapshot` do ciclo dela (uma consulta `whereIn cycle_start`) não é `isManuallyClosedAndActive()` nem `isSealed()`.
  - `undoableExpenseIds(Group $g, array $expenseIds): array` é a versão em lote para o `summary()` (uma consulta de quotas elegíveis e uma de snapshots).
- **Controller:** só o credor (`abort(403, ...)`, como `pay`); `findExpenseForMember` para o 404. Depois do commit, `ProofStorage::deleteIfUnreferenced` em cada caminho distinto liberado (R18, F11). Resposta `200 {message, quotas_cleared}`.
- **`summary()`** (`:658-718`): depois de montar `$summary`, para as entradas `IN_INSTALLMENTS`, pagas, em que `userPayerId` é o usuário autenticado, acrescenta `canUndoPurchase: true` quando o id está em `undoableExpenseIds` (P5). A decoração é só na resposta; o snapshot e `compute()` ficam intactos (R14).
- **Testes** (`backend/tests/Feature/ExpenseControllerUndoPurchaseTest.php`, novo; `ExpenseControllerSummaryTest` ganha o caso do campo): `test_the_creditor_undoes_every_paid_installment_inside_the_window`, `test_it_clears_proof_note_paid_at_and_paid_by_and_unlocks_update_and_destroy`, `test_it_is_refused_when_any_paid_quota_is_in_a_closed_by_date_cycle`, `..._in_a_manually_closed_cycle`, `..._in_a_sealed_cycle`, `test_born_paid_quotas_do_not_count_for_the_window`, `test_a_non_creditor_gets_403_and_a_non_member_404`, `test_a_failure_in_the_middle_changes_nothing`, `test_the_file_is_removed_only_after_commit_and_only_when_unreferenced`, `test_other_types_get_422`, `test_summary_flags_can_undo_purchase_only_for_the_creditor_inside_the_window` (CA-29, CA-30).

## 6. Frontend: campos de pagamento, chave e cadastro (specify §3.6, R9, R16, R23, CA-F1 a CA-F11, CA-F15 a CA-F17)

- **Extração:** novo `frontend/src/components/PaymentProofFields.tsx` com o seletor de foto (sem `capture`, R16) e o campo de descrição de `PaymentProofDialog.tsx:199-223`. Props: `file`, `onFileChange`, `note`, `onNoteChange`, `photoLabel`, `noteLabel`, `inputKey`, `disabled`. `PaymentProofDialog` passa a usá-lo; `canConfirmPayment` e `NOTE_MAX_LENGTH` continuam exportados dali (uma só fonte) e `PaymentProofDialog.test.tsx` passa sem edição.
- **Utilitários puros** em `frontend/src/utils/`, cada um com teste:
  - `requestKey.ts`: `newRequestKey(): string` usa `crypto.randomUUID` quando existe e, em contexto não seguro (acesso por IP em HTTP), um v4 montado com `crypto.getRandomValues`;
  - `expenseRequest.ts`: `buildExpenseBody(payload, proof: File | null): FormData | object` (JSON sem foto; `FormData` com `payers[]`, `quotas[i][campo]` e `comprovante` com foto; sempre inclui `client_request_id`) e `EXPENSE_SAVE_TIMEOUT_MS = 60_000`;
  - `paymentOccurrence.ts`: `describePaymentOccurrence({ expenseType, installments, date, payerName, isSelf }): { summary: string; warning: string }` com os textos da §3.6 (À Vista, "serão marcadas como pagas as N parcelas", Fixa), o aviso fixo do fechamento do ciclo e "registrado em nome de {Pagador}, que será avisado".
- **`ExpenseForm.tsx`:**
  - estado `proofFile`, `paymentNote`; `requestKeyRef = useRef(newRequestKey())` e `submittingRef = useRef(false)` (a trava síncrona, R23);
  - seção "Pagamento (opcional)" antes dos botões, sempre habilitada (D4); rótulos "Descrição" × "Descrição do pagamento (opcional)";
  - `handleSave` monta o corpo com `buildExpenseBody`, usa `axios.post(..., { timeout })` e a mesma chave em todo reenvio; **não há tela de confirmação**;
  - erros: 409, 413, `ECONNABORTED` e erro sem `response` têm texto próprio (conferir a lista antes de reenviar; reenviar não duplica); campos, foto e chave ficam mantidos; `trackEvent('expense_created')` só no sucesso e uma vez.
- **Testes** (`ExpenseForm.test.tsx` ganha casos; o cadastro sem pagamento continua passando sem edição): `sends the same JSON as before when the section is empty` (CA-F1), `sends descricao_pagamento in JSON when only the note is filled` (CA-F2), `sends multipart with comprovante, payers[] and quotas[i][...] when there is a photo` (CA-F3), `does not send a whitespace-only note` (CA-F4), labels and counter (CA-F5), `shows the occurrence text per type and the fixed warning, with no confirmation step` (CA-F6), `enables the section for another payer and says it is in their name` (CA-F7), `keeps fields, photo and key after 422, 413, 409, 500, network error and timeout` (CA-F8), `a double click sends one request` e `the key is the same after editing and resending` (CA-F9, CA-F15), `timeout message asks to check the list` (CA-F16), `body always carries client_request_id` (CA-F17), `no capture on the file input` (CA-F11).

## 7. Frontend: desfazer a compra e aviso ao credor (specify R19, R21, CA-F12, CA-F13)

- **Tipo:** `SummaryExpense.canUndoPurchase?: boolean` em `frontend/src/hooks/useGroupCycle.ts`.
- **`usePaymentActions.ts`:** `handleUndoPurchase(expenseId): void` (`POST .../undo-purchase`, `reload()` no sucesso), `undoPurchaseSuccess` e `dismissUndoPurchaseSuccess`, no padrão de `handleUnpay`; `canUndoPurchase(exp)` devolve `exp.canUndoPurchase === true`.
- **`ExpenseManager.tsx`:** ação "Desfazer pagamento da compra" ao lado de "Desfazer pagamento" (`:451-462`), com diálogo de confirmação no padrão de `setDeleteExpenseId`, só quando `canUndoPurchase(exp)`; some depois do desfazer porque a lista recarrega.
- **`notificationText.ts`:** caso `expense_paid_on_behalf`: `` `${actor} registrou o pagamento de "${desc}" em seu nome` `` (no estilo de `expense_born_paid`).
- **Testes:** `usePaymentActions.test.tsx` (`handleUndoPurchase` chama a rota e recarrega), `ExpenseManager.test.tsx` (`shows the undo-purchase action only when the server says so`, `hides it for other users`), `notificationText.test.ts`, e um caso de componente com fixture de compra paga para `CycleTotalsCards`, `PendingCyclesList`, filtro de status e chips (CA-F13).

## 8. Documentação, investigação e fechamento

- **`MANUAL.md` e `MANUAL.pdf`** (CA-D1): pagamento no cadastro por tipo, aviso ao credor, janela de correção e o aviso de fechamento. O PDF é gerado como nas features anteriores.
- **Investigação do RK6** (`store()` × ciclo selado): teste que reproduz ou descarta "despesa nova em ciclo selado ainda aberto não aparece em `summary()`"; o resultado vai ao `implementation.md` e, se reproduzir, vira item de backlog (não corrigir aqui).
- **Variantes via API** da suíte do §1 e `implementation.md` com cada comando e resultado (CA-D2, inclusive a medição de CA-02 com U+200B e o limite de upload de produção, RK8).
- **Checklist pré-PR:** agent `pr-readiness-checker`; revisão de segurança do `ExpenseController` e da rota nova pelo agent `security-reviewer` (autorização do pagamento em nome do credor, upload, 409).

## 9. Revisão de pontos de falha que os testes do specify não pegam sozinhos

Cada linha vira um teste no bloco que cita.

| Condição | O que se espera | Bloco |
|---|---|---|
| Parcelada de 120 parcelas com foto de 5 MB | Uma transação só, todas as quotas pagas, tempo sem estouro | §4 |
| `payers` com um só elemento e `quotas[0][number]` como string `"1"` em multipart | Mesmo estado que o JSON equivalente | §4 (CA-27) |
| Mesma foto reenviada com outro nome de arquivo | Replay (o hash é do conteúdo) | §2 e §4 |
| Acesso ao formulário por IP em HTTP, sem `crypto.randomUUID` | A chave é gerada e o envio funciona | §6 |
| Foto escolhida e depois removida no formulário | O envio volta a ser JSON, sem `comprovante` | §6 |

## 10. Ordem de execução

Há dependência técnica; `tasks.md` segue esta ordem.

1. **§1** primeiro e verde no código atual (a rede de segurança).
2. **§2** (migration, impressão digital, modelo) e **§3** (`deleteIfUnreferenced` e a ordem dos três pontos de remoção) não dependem um do outro; ambos antes de §4 e §5.
3. **§4** (`store`, `ExpensePaymentService`, `Notifier`) depende de §2; **§5** (desfazer) depende de §3 e do serviço criado em §4.
4. **§6** e **§7** (frontend) dependem do contrato de §4 e §5 e podem ser feitos em paralelo entre si.
5. **§8** por último: variantes via API, investigação do RK6, manual, `pr-readiness-checker`, `security-reviewer` e a validação local antes do PR (stack isolada, `validacao-local-stack-isolada`).

Pontos de gate humano: migration em produção (merge em `main`), merge do PR em `dev`, push e PR só com o aval escrito do usuário depois da validação local.
