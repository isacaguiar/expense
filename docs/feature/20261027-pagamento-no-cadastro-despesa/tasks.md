# Tasks — Pagamento no cadastro de despesa

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261027

**Status: aprovado pelo usuário em 2026-10-27 ("Sim, siga as sugestões"); em execução.** Andamento no `implementation.md`.

IDs a partir de TASK-512: o maior em qualquer branch do repositório e em `docs/` é TASK-511 (feature `20261026-organizacao-despesas-pagamentos`), conferido em 2026-10-27 com `grep` nos documentos e nas mensagens de commit de todas as branches. Reconfirmar a numeração se outra feature reservar IDs antes do merge.

Escopo: `backend/`, `frontend/` e documentação. A ordem põe os **testes de caracterização primeiro** (TASK-512 a 515), verdes no código atual, antes de qualquer código da feature (specify §3.7).

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-512 | Criar o trait `BuildsPurchaseScenarios` e os testes de caracterização de `compute()` e dos totais por ciclo (NR-01, NR-02) | backend | plan.md §1 | nenhum | Concluída (2026-10-27) |
| TASK-513 | Testar que `paid_at` e `paid_by` não alteram nenhuma saída e que relatórios anuais, mensal e `indexByGroup` ignoram o pagamento (NR-03, NR-09) | backend | plan.md §1 | nenhum | Concluída (2026-10-27) |
| TASK-514 | Testar quitação, selagem, `focus`, `pendingCycles`, `ClosingBreakdown` e histórico de ciclos com a compra paga (NR-04, NR-05, NR-06) | backend | plan.md §1 | nenhum | Concluída (2026-10-27) |
| TASK-515 | Fixar o comportamento de `grossDebts` e do Painel (`me/overview`) com a compra paga (NR-07, NR-08) | backend | plan.md §1 | nenhum | Concluída (2026-10-27) |
| TASK-516 | Criar a migration de `client_request_id` e `request_fingerprint` em `ex_expenses` e liberar as colunas no model | backend | plan.md §2 | antes do deploy/migration em produção | Concluída (2026-10-27) |
| TASK-517 | Criar `ExpenseRequestFingerprint` com testes unitários (CA-34) | backend | plan.md §2 | nenhum | Concluída (2026-10-27) |
| TASK-518 | Criar `ProofStorage::deleteIfUnreferenced` com testes (CA-28, parte do método) | backend | plan.md §3 | nenhum | Concluída (2026-10-27) |
| TASK-519 | Usar `deleteIfUnreferenced` em `unpay`, `pay` repetido e `updatePaymentProof`, atualizando o banco antes de apagar o arquivo (CA-28) | backend | plan.md §3 | nenhum | Concluída (2026-10-27) |
| TASK-520 | Criar `ExpensePaymentService` com `paidAttributes`, `earliestOccurrence` e `isFuture` | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-521 | Estender `Notifier::expensePaid` com valor opcional e criar `Notifier::expensePaidOnBehalf` | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-522 | Registrar o pagamento no `store()` de À Vista e Fixa, com os campos novos e a recusa de ocorrência inicial futura (CA-01 a CA-06, CA-09, CA-12, CA-14 a CA-16, CA-27) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-523 | Pagar no `store()` todas as parcelas não `born_paid` da Parcelada, inclusive a retroativa (CA-07, CA-08, CA-13) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-524 | Registrar o pagamento em nome do credor quando o criador é outro (CA-10, CA-11) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-525 | Tornar o `store()` atômico: arquivo antes da transação, rollback com remoção do arquivo, `500` sem `details` e passos pós-commit protegidos (CA-17 a CA-19) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-526 | Implementar a idempotência do `store()`: replay, `409`, corrida e `already_existed` (CA-20 a CA-23, CA-32, CA-33, CA-35) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-527 | Disparar no `store()` os avisos de um cadastro (`expensePaid` uma vez, aviso ao credor, WhatsApp uma vez) e garantir que o ciclo não é selado (CA-24 a CA-26) | backend | plan.md §4 | nenhum | Concluída (2026-10-27) |
| TASK-528 | Criar `POST /api/expenses/{id}/undo-purchase` com a janela de correção (CA-29, CA-30) | backend | plan.md §5 | nenhum | Concluída (2026-10-27) |
| TASK-529 | Acrescentar `canUndoPurchase` ao `summary()` quando verdadeiro | backend | plan.md §5 | nenhum | Concluída (2026-10-27) |
| TASK-530 | Repetir a suíte de não regressão com as compras cadastradas pelo `POST /api/expenses` e rodar a suíte completa (NR-10) | backend | plan.md §1, §8 | nenhum | Concluída (2026-10-27) |
| TASK-531 | Investigar com teste se `store()` em ciclo já selado esconde a despesa nova (RK6) e registrar o resultado | backend | plan.md §8 | nenhum | Concluída (2026-10-27) |
| TASK-532 | Rodar o agent `security-reviewer` sobre `ExpenseController`, o serviço, a rota nova e o upload, e tratar os achados | backend | plan.md §8 | nenhum | Concluída (2026-10-28) |
| TASK-533 | Criar os utilitários `requestKey.ts` e `expenseRequest.ts` com testes | frontend | plan.md §6 | nenhum | Concluída (2026-10-28) |
| TASK-534 | Criar o utilitário `paymentOccurrence.ts` com testes | frontend | plan.md §6 | nenhum | Concluída (2026-10-28) |
| TASK-535 | Extrair `PaymentProofFields` de `PaymentProofDialog` sem alterar os testes existentes | frontend | plan.md §6 | nenhum | Pendente |
| TASK-536 | Acrescentar a seção "Pagamento (opcional)" ao `ExpenseForm` com envio em JSON ou multipart (CA-F1 a CA-F7, CA-F10, CA-F11) | frontend | plan.md §6 | nenhum | Pendente |
| TASK-537 | Dar ao `ExpenseForm` chave estável, trava síncrona, timeout e mensagens de erro (CA-F8, CA-F9, CA-F15 a CA-F17) | frontend | plan.md §6 | nenhum | Pendente |
| TASK-538 | Acrescentar `canUndoPurchase` ao tipo e `handleUndoPurchase` ao `usePaymentActions` | frontend | plan.md §7 | nenhum | Pendente |
| TASK-539 | Acrescentar a ação "Desfazer pagamento da compra" ao `ExpenseManager` (CA-F12) | frontend | plan.md §7 | nenhum | Pendente |
| TASK-540 | Acrescentar o texto de `expense_paid_on_behalf` em `notificationText.ts` | frontend | plan.md §7 | nenhum | Pendente |
| TASK-541 | Testar a apresentação de totais, filtros e chips com fixture de compra paga (CA-F13) | frontend | plan.md §7 | nenhum | Pendente |
| TASK-542 | Registrar os limites de upload (`post_max_size`, `upload_max_filesize`) do servidor de produção (RK8) | infra | plan.md §8 | depende de informação do usuário sobre o host | Pendente |
| TASK-543 | Atualizar `MANUAL.md` e `MANUAL.pdf` (CA-D1) | doc | plan.md §8 | nenhum | Pendente |
| TASK-544 | Validar a feature localmente na stack isolada, rodar o `pr-readiness-checker` e registrar o resultado | backend | plan.md §8, §10 | antes do merge (o usuário valida localmente antes do push e do PR) | Pendente |
| TASK-545 | Atribuir os avisos de um cadastro com pagamento a quem cadastrou, e não ao credor, quando são pessoas diferentes; sem WhatsApp do comprovante nesse caso (achado N1 da TASK-532) | backend | plan.md §4 | nenhum | Pendente |
| TASK-546 | Exigir uma única quota na data informada para À Vista e Fixa, e o teto de 120 quotas, quando há pagamento no cadastro (achados N2 e N3 da TASK-532) | backend | plan.md §4 | nenhum | Pendente |
| TASK-547 | Converter para inteiro os ids do `store()` recebidos em multipart e esconder `client_request_id` e `request_fingerprint` da API (achados N5 e N4 da TASK-532) | backend | plan.md §4 | nenhum | Concluída (2026-10-28) |

## Dependências e ordem

Ordem de execução: **512 → 513 → 514 → 515 → 516 → 517 → 518 → 519 → 520 → 521 → 522 → 523 → 524 → 525 → 526 → 527 → 528 → 529 → 530 → 531 → 532 → 533 → 534 → 535 → 536 → 537 → 538 → 539 → 540 → 541 → 542 → 543 → 544** (plan.md §10). Critério: primeiro a rede de segurança (512–515), depois as peças de base do backend (516–521), depois o `store()` em camadas (522–527), o desfazer (528–529) e a verificação do backend (530–532); só então o frontend e, por último, manual e validação.

| Task | Depende de | Por quê |
|---|---|---|
| TASK-512 | — | Cria o trait e a primeira rede de segurança |
| TASK-513 | 512 | Reaproveita o trait |
| TASK-514 | 512 | Reaproveita o trait |
| TASK-515 | 512 | Reaproveita o trait |
| TASK-516 | — | Base da idempotência |
| TASK-517 | — | Classe pura, independente |
| TASK-518 | — | Método novo, independente |
| TASK-519 | 518 | Usa o método |
| TASK-520 | — | Serviço novo, independente |
| TASK-521 | — | `Notifier` independente |
| TASK-522 | 512 a 515, 520 | Só altera `store()` com a rede de segurança verde; usa `paidAttributes` e `isFuture` |
| TASK-523 | 522 | Estende o mesmo trecho do `store()` |
| TASK-524 | 522 | Estende o mesmo trecho do `store()` |
| TASK-525 | 523, 524 | Reestrutura o `store()` já com as regras de pagamento |
| TASK-526 | 516, 517, 525 | Usa as colunas e a impressão digital dentro do `store()` reestruturado |
| TASK-527 | 521, 525 | Usa o `Notifier` novo e os passos pós-commit protegidos |
| TASK-528 | 519, 520, 523 | Usa `deleteIfUnreferenced` e o serviço; testa sobre compras cadastradas pelo `store()` |
| TASK-529 | 528 | Usa `undoableExpenseIds` do serviço |
| TASK-530 | 512 a 515, 527, 529 | Roda as mesmas suítes pelo `POST /api/expenses` |
| TASK-531 | 526 | Usa o `store()` final |
| TASK-532 | 530 | Revisa o backend completo |
| TASK-533 | — | Utilitários puros |
| TASK-534 | — | Utilitário puro |
| TASK-535 | — | Refatoração protegida por testes existentes |
| TASK-536 | 533, 534, 535 | Usa os utilitários e os campos extraídos |
| TASK-537 | 533, 536 | Estende o mesmo componente e usa a chave |
| TASK-538 | 529 | Segue o contrato de `canUndoPurchase` e da rota |
| TASK-539 | 538 | Usa o hook |
| TASK-540 | 521 | Segue o tipo de notificação criado |
| TASK-541 | 539 | Fixtures com a ação já integrada |
| TASK-542 | — | Informação do usuário; não bloqueia código, bloqueia o PR |
| TASK-543 | todas as de código | Escrita por último para citar o que de fato foi entregue |
| TASK-545 | 527, 532 | Muda os avisos montados na TASK-527, a partir do achado da revisão |
| TASK-546 | 522, 523, 532 | Estreita o `store()` das TASK-522 e 523, a partir do achado da revisão |
| TASK-547 | 526, 532 | Corrige tipos no `store()` e o `$hidden` do model, a partir do achado da revisão |
| TASK-544 | todas | Roda sobre a branch da feature com tudo integrado |

**Tasks derivadas da revisão de segurança (TASK-532).** As TASK-545, 546 e 547 nasceram do relatório do agent `security-reviewer` (achados de severidade média ou alta viram task antes do PR; `implementation.md` tem o relatório e o tratamento de cada achado). Executam logo depois da TASK-534, antes de o frontend continuar, e **todas precisam estar concluídas antes da TASK-544**. A ordem real de execução ficou: 531 → 532 → 547 → 546 → 545 → 533 → 534 → 535 em diante.

Não há paralelismo útil com um executor só: 522 a 527 editam o mesmo método, e 536 e 537 o mesmo componente.

Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: a branch da feature `feature/20261027-pagamento-no-cadastro-despesa` **ainda não existe**; é criada a partir de `dev` atualizada (`git fetch origin` antes), e a TASK-512 roda direto nela. As demais tasks nascem em `feature/20261027-pagamento-no-cadastro-despesa-TASK-0xx`, integradas na branch da feature com `git merge --no-ff`, sem PR. O working tree já tem documentos novos não commitados (esta pasta e os itens de backlog 110 a 112); o commit deles e de cada task só acontece com autorização do usuário. No fim, **um único PR** contra `dev`, depois da TASK-544. Os itens de backlog 110 a 112 não pertencem ao código da feature: proposta de levá-los num PR de documentação próprio, como foi o #245.

Cada task, antes de integrar: backend, `cd backend && ./vendor/bin/pint --test` e `php artisan test` (ou o `--filter` indicado); frontend, `cd frontend && npx tsc --noEmit` e `npx vitest run <arquivos tocados>`, com comando e resultado reais no `implementation.md` (`04-implementation.md` §1 item 3). A suíte completa do frontend roda com `--maxWorkers=1` quando a memória estiver baixa. Nenhum critério abaixo repete isso por extenso, exceto onde o critério o torna específico. Todo critério com teste novo começa **vermelho** e só passa depois da implementação, exceto os de caracterização (512 a 515), que nascem verdes porque descrevem o código atual.

## Critérios de aceite

- **TASK-512**:
  - `cd backend && php artisan test --filter PurchasePaymentNonRegressionTest` verde **no código atual**.
  - O trait `backend/tests/Concerns/BuildsPurchaseScenarios.php` expõe `makeGroup(int $members, ?int $closingDay = null)`, `makePurchase(Group, User $creditor, array $participants, string $type, float $total, string $start, int $n = 1)` e `markPaid(Expense, ?string $proofPath = null, ?string $note = null)` (plan.md §1).
  - `test_compute_balances_settlements_and_value_per_person_are_identical` compara a compra com `markPaid` e a sem, para: À Vista; Parcelada de 2, 3 e 12; Fixa no mês inicial e no seguinte; Parcelada retroativa com `born_paid`; credor que participa e que não participa; 2 e 3 membros; dívidas em sentido contrário.
  - `test_totals_paid_and_pending_differ_only_by_the_paid_quotas_of_each_cycle` e `test_installment_paid_totals_sum_to_total_value_across_all_cycles` usam `Carbon::setTestNow` em cada ciclo; N = 3 com 100,00 (33,33 / 33,33 / 33,34) e N = 12; a soma de `totals.paid` fecha no `total_value` ao centavo, e no mês do cadastro `totals.paid` é só a quota daquele mês.
  - `git diff --stat -- backend/app` vazio; nenhum teste existente editado.
- **TASK-513**:
  - `test_changing_paid_at_and_paid_by_changes_no_output` prova que mudar esses campos não altera `summary`, `me/overview`, `closing-report`, `pending-cycles`, `gross-debts` nem `cycles` (comparação de JSON inteiro).
  - `test_annual_reports_monthly_expenses_and_index_are_identical` cobre `GroupExpenseReportController`, `getMonthlyExpenses` e `indexByGroup`, comparando a compra com `markPaid` e a sem.
  - Verde no código atual; `backend/app` sem diff.
- **TASK-514**:
  - `test_settlement_and_sealing_follow_the_equivalent_state`: com a compra paga, o ciclo só sela com todos os acertos confirmados; `pending_expenses_count` e `awaiting_seal` coincidem com o estado equivalente por factory.
  - `test_focus_gives_the_same_answer_for_a_fully_paid_purchase`: `focus()` devolve o mesmo que a varredura completa (`isFullySettled` ciclo a ciclo). Teste **novo**, no arquivo novo; o de `CycleSummaryServiceTest.php:516` não é editado (ajuste ao plan.md §1, para manter NR-10).
  - `test_closing_breakdown_and_closing_report_are_identical`; `test_cycle_history_and_snapshot_keep_the_same_keys` (só `paid`, `paymentNote` e `paymentProofUrl` diferem; um snapshot antigo sem `paymentNote` continua lido).
  - Verde no código atual; `backend/app` sem diff.
- **TASK-515**:
  - `test_gross_debts_skips_a_paid_quota_and_lists_it_again_when_unpaid` fixa a D13: a quota paga não aparece na árvore; ao zerar `paid`, volta.
  - `test_overview_owe_and_receive_are_identical_and_items_follow_paid`: `owe`, `receive`, `to_pay`, `to_receive` idênticos; `my_items[].paid`, `can_pay`, `can_unpay` e `pending_items_count` seguem `paid`; a reconciliação `owe × settlements` do Painel continua verde.
  - Verde no código atual; `backend/app` sem diff.
- **TASK-516**:
  - O arquivo `backend/database/migrations/2026_10_27_000000_add_client_request_id_to_ex_expenses_table.php` cria `client_request_id` `char(36)` nullable, `request_fingerprint` `char(64)` nullable e `unique(['user_creator_id', 'client_request_id'])`; `Expense::$fillable` ganha as duas colunas.
  - `ExpenseClientRequestIdTest` (novo): duas despesas do mesmo criador com chave `NULL` convivem; o par (criador, chave) repetido lança `QueryException` com código 1062; a mesma chave com criadores diferentes convive.
  - `php artisan migrate` e `php artisan migrate:rollback --step=1` funcionam em banco **local** e a migration é aditiva (`04-implementation.md` §1 item 3). Em produção só roda no deploy do merge em `main`: gate humano.
- **TASK-517**:
  - `cd backend && php artisan test --filter ExpenseRequestFingerprintTest` verde, com `test_it_ignores_payers_order`, `test_it_treats_string_10_and_float_10_00_alike`, `test_it_ignores_edge_whitespace_in_texts`, `test_it_changes_with_value_date_payer_participant_or_photo`.
  - `ExpenseRequestFingerprint::make(array $input, ?string $proofSha256): string` devolve 64 caracteres hexadecimais; a classe não consulta banco nem HTTP.
- **TASK-518**:
  - `ProofStorageReferencesTest` (novo): `test_it_deletes_an_unreferenced_file`, `test_it_keeps_a_file_a_quota_references`, `test_it_keeps_a_file_a_settlement_confirmation_references`, `test_it_ignores_null_and_empty_paths`.
  - `ProofStorage::delete` não muda e `ProofStorageTest` passa sem edição.
- **TASK-519**:
  - Casos novos em `ProofStorageReferencesTest` (ajuste ao plan.md §3: arquivo novo, para não editar testes existentes): `test_unpay_of_one_quota_keeps_a_file_another_quota_uses`, `test_the_last_reference_removes_the_file`, `test_replacing_the_proof_of_one_quota_keeps_the_old_file_for_the_others` (cobre `pay()` repetido e `payment-proof`).
  - Nos três pontos o `update` do banco acontece **antes** da remoção do arquivo (`unpay`, hoje, apaga antes: `ExpenseController.php:1188`).
  - `ExpenseControllerPayTest`, `ExpenseControllerPaymentProofTest` e `ProofStorageTest` ficam sem diff e verdes.
- **TASK-520**:
  - `ExpensePaymentServiceTest` (novo): `paidAttributes(int $creditorId, ?string $proofPath, ?string $note)` devolve exatamente `paid=true`, `paid_at` ≈ agora, `paid_by` = credor, `payment_proof_path`, `payment_note` e `born_paid=false`.
  - `earliestOccurrence(string $type, string $datePayment, array $quotas)` devolve `date_payment` para À Vista e Fixa e a menor `date_expected` para Parcelada, inclusive com `quotas` fora de ordem e `number` repetido.
  - `isFuture(Group, Carbon)` com `Carbon::setTestNow` e `closing_day` nulo e 15: ciclo vigente, em carência, fechado e futuro dão `false`, `false`, `false` e `true`.
- **TASK-521**:
  - `NotifierTriggersTest` existente passa **sem edição**.
  - Testes novos em arquivo novo: `expensePaid` com `$amount` grava `data.amount` com o valor passado e, sem ele, com `value_quota`; `expensePaidOnBehalf(Expense, int $quotasCount)` grava o tipo `expense_paid_on_behalf` só para o credor, com `actorName` do criador e `quotasCount`; não grava nada quando criador e credor são a mesma pessoa.
- **TASK-522**:
  - `ExpenseControllerStorePaymentTest` (novo) cobre, com o nome do critério: CA-01 (sem pagamento = hoje; os 35 testes de `ExpenseControllerStoreTest` passam sem edição), CA-02 (descrição só com espaços, tabs, quebra de linha e NBSP; a medição de U+200B vai ao `implementation.md` e, se `TrimStrings` não a remove, o controller normaliza), CA-03 a CA-06 (À Vista com descrição, com foto, com as duas, descrição `"0"`), CA-09 (Fixa: só a quota inicial), CA-12 (ocorrência inicial futura: 422 e nada criado; sem pagamento: 201), CA-14, CA-15, CA-16 (foto inválida com descrição válida: 422, nada criado) e CA-27 (JSON e multipart dão o mesmo estado).
  - Campos novos validados: `comprovante` `nullable|image|max:5120`, `descricao_pagamento` `nullable|string|max:500`, `client_request_id` `nullable|uuid`.
  - "Nada criado" é verificado nas três tabelas e em `comprovantes/`.
- **TASK-523**:
  - Casos novos em `ExpenseControllerStorePaymentTest`: CA-07 (Parcelada de 3: as 3 quotas pagas, mesmo `paid_at`, `paid_by` = credor, mesmo arquivo e mesma nota, nenhuma `born_paid`; `update` e `destroy` recusados pelas regras existentes), CA-08 (12 parcelas com `quotas` do cliente fora de ordem e `number` repetido: todas pagas), CA-13 (retroativa: as fechadas ficam `born_paid`, sem arquivo nem nota e com o aviso `expenseBornPaid` de hoje; as demais pagas), e uma Parcelada de 120 parcelas com foto que cria tudo numa transação.
- **TASK-524**:
  - Casos novos: criador ≠ credor com pagamento dá 201, `paid_by` = credor e `user_creator_id` = criador (CA-10, parte de estado); quem não é membro do grupo recebe o 404/403 de hoje, sem arquivo gravado; criador ≠ credor **sem** pagamento é o cadastro de hoje (CA-11).
- **TASK-525**:
  - Casos novos: falha simulada ao gravar o arquivo (`FailsToWriteProofs`) dá erro e nada criado (CA-17); falha forçada no banco depois do arquivo gravado dá rollback, arquivo removido e `500` **sem `details`**, com `Log::error` (CA-18); exceção simulada em passo pós-commit (notificação, WhatsApp) mantém 2xx com despesa e pagamento persistidos (CA-19).
  - O `try` do `store()` não contém mais passos pós-commit (revisão do diff); cada um tem `try/catch` próprio com `Log::warning`.
- **TASK-526**:
  - `ExpenseControllerStoreIdempotencyTest` (novo): CA-20 (duas chamadas em sequência: uma despesa, o mesmo `expense_id`, `already_existed: true`, status 200), CA-21 (chamadas simultâneas com foto: uma despesa e **um só arquivo**, ambas 2xx, nenhuma 500), CA-22 (corpo diferente, inclusive foto diferente: 409; chave igual de outro usuário não colide), CA-23 (sem chave cada chamada cria), CA-32 (o replay não grava arquivo, não cria quota nem notifica), CA-33 (409 para a chave de uma despesa apagada), CA-35 (a requisição que perde a corrida, com violação de unicidade simulada só no `INSERT`, remove o próprio arquivo).
  - A ordem é: validar, localizar a chave, só então gravar o arquivo e abrir a transação; o replay vem antes das regras de competência (uma competência que fechou depois não impede o replay).
- **TASK-527**:
  - Casos novos (e em `NotifierTriggersTest`, que não é editado: arquivo novo): `expensePaid` dispara **uma vez** por cadastro, com o valor total da compra na Parcelada; o aviso `expense_paid_on_behalf` só quando criador ≠ credor; `WhatsAppNotifier::expenseProofPaid` uma vez, só com foto e depois da resposta (CA-25); `GroupCycleSnapshot` não ganha `settled_at` mesmo com tudo quitado (CA-26); `settlements` e `balances` do ciclo idênticos aos da compra sem pagamento e nenhuma `SettlementConfirmation` criada (CA-24).
- **TASK-528**:
  - `ExpenseControllerUndoPurchaseTest` (novo): `test_the_creditor_undoes_every_paid_installment_inside_the_window`; `test_it_clears_proof_note_paid_at_and_paid_by_and_unlocks_update_and_destroy`; recusa 422 com qualquer quota paga em ciclo fechado por data, em fechamento manual ativo e em ciclo selado; `test_born_paid_quotas_do_not_count_for_the_window`; 403 para quem não é o credor e 404 para quem não é membro; `test_a_failure_in_the_middle_changes_nothing`; `test_the_file_is_removed_only_after_commit_and_only_when_unreferenced`; 422 para outros tipos que não `IN_INSTALLMENTS`; 422 quando não há pagamento a desfazer.
  - CA-30: `unpay` e `payment-proof` por parcela seguem funcionando numa quota paga no cadastro.
  - A rota fica em `routes/api.php` junto de `pay` e `unpay`, dentro de `jwt.auth`.
- **TASK-529**:
  - `ExpenseControllerSummaryTest` ganha, por acréscimo (nenhum teste existente é editado), `test_summary_flags_can_undo_purchase_only_for_the_creditor_inside_the_window`: a chave `canUndoPurchase: true` aparece só para o credor autenticado, só em `IN_INSTALLMENTS` paga e só com a janela aberta; **ausente** nos demais casos; um ciclo selado serve o snapshot sem a chave nas quotas fechadas; o snapshot e `compute()` ficam intactos.
  - Os testes existentes de `summary` passam sem edição.
- **TASK-530**:
  - As suítes de 512 a 515 ganham a variante "cadastrada pelo `POST /api/expenses`" e passam com os mesmos resultados da variante por factory.
  - `php artisan test` completo verde e `git diff --stat -- backend/tests` mostra que nenhum teste anterior à feature foi editado, só arquivos novos e as inclusões listadas nestes critérios; comando e total de testes no `implementation.md`.
- **TASK-531**:
  - Um teste que cadastra uma despesa pendente num ciclo já selado e ainda aberto e verifica se `summary()` a mostra. O resultado (reproduziu ou não) vai ao `implementation.md`; se reproduziu, o achado vira item de backlog proposto ao usuário, **sem corrigir aqui**.
- **TASK-532**:
  - O agent `security-reviewer` roda sobre `ExpenseController`, `ExpensePaymentService`, a rota `undo-purchase` e o upload; o resultado, achado a achado, e o tratamento ficam no `implementation.md`. Achados de severidade média ou alta viram task nova antes do PR; os demais, backlog proposto ao usuário.
- **TASK-533**:
  - `cd frontend && npx vitest run src/utils/requestKey.test.ts src/utils/expenseRequest.test.ts` verde: `newRequestKey()` devolve um UUID v4 com `crypto.randomUUID` e também sem ele (contexto não seguro simulado), sem repetir em chamadas seguidas; `buildExpenseBody` devolve JSON sem foto e `FormData` com foto, com `payers[]`, `quotas[i][campo]`, `comprovante` e `client_request_id` nos dois casos; `payers` com um só elemento e `number` como string seguem o mesmo contrato; `EXPENSE_SAVE_TIMEOUT_MS` vale 60000.
- **TASK-534**:
  - `cd frontend && npx vitest run src/utils/paymentOccurrence.test.ts` verde: textos para À Vista, Parcelada ("serão marcadas como pagas as N parcelas") e Fixa; o aviso fixo do fechamento do ciclo sempre presente; "registrado em nome de {Pagador}, que será avisado" só quando o pagador não é o usuário.
- **TASK-535**:
  - `PaymentProofDialog.test.tsx` passa **sem edição** (`git diff` vazio nesse arquivo); `canConfirmPayment` e `NOTE_MAX_LENGTH` continuam exportados de `PaymentProofDialog.tsx`.
  - `PaymentProofFields.test.tsx` (novo): renderiza o seletor de foto **sem** `capture` e o campo de descrição com contador até 500; propaga `onFileChange` e `onNoteChange`; respeita `disabled`.
- **TASK-536**:
  - `ExpenseForm.test.tsx` ganha: `sends the same JSON as before when the section is empty` (CA-F1, e os testes atuais passam sem edição), `sends descricao_pagamento in JSON when only the note is filled` (CA-F2), `sends multipart with comprovante, payers[] and quotas[i][...] when there is a photo` (CA-F3), `does not send a whitespace-only note` (CA-F4), rótulos distintos e contador (CA-F5), `shows the occurrence text per type and the fixed warning, with no confirmation step` (CA-F6), `enables the section for another payer and says it is in their name` (CA-F7), foto fora do limite ou que não é imagem avisa antes de enviar (CA-F10) e `no capture on the file input` (CA-F11); a seção é usável em 360 px.
  - Foto escolhida e depois removida volta a enviar JSON, sem `comprovante`.
- **TASK-537**:
  - `ExpenseForm.test.tsx` ganha: `keeps fields, photo and key after 422, 413, 409, 500, network error and timeout` (CA-F8, `trackEvent` não chamado), `a double click sends one request` (CA-F9, trava síncrona), `the key is the same after editing and resending` (CA-F15), `timeout message asks to check the list` (CA-F16) e `body always carries client_request_id` em JSON e em multipart (CA-F17).
  - A requisição usa `timeout: EXPENSE_SAVE_TIMEOUT_MS`; a chave é gerada uma vez por `useRef`.
- **TASK-538**:
  - `SummaryExpense.canUndoPurchase?: boolean` em `useGroupCycle.ts`; `usePaymentActions.test.tsx` ganha `handleUndoPurchase calls the route and reloads` e `canUndoPurchase is true only when the server says so`; `undoPurchaseSuccess` e `dismissUndoPurchaseSuccess` seguem o padrão de `handleUnpay`. Os testes existentes do hook passam sem edição.
- **TASK-539**:
  - `ExpenseManager.test.tsx` ganha `shows the undo-purchase action only when the server says so` e `hides it for other users`; a ação abre um diálogo de confirmação e só então chama a rota (padrão do `deleteExpenseId`); depois do desfazer a lista recarrega e o chip volta a "Pendente" (CA-F12). Os testes existentes passam sem edição.
- **TASK-540**:
  - `notificationText.test.ts` (novo ou existente com caso novo): `expense_paid_on_behalf` devolve `` `${actorName} registrou o pagamento de "${expenseDescription}" em seu nome` ``; os demais tipos e o `default` não mudam.
- **TASK-541**:
  - Testes de `CycleTotalsCards`, `PendingCyclesList`, filtro de status e chips com fixture de compra paga: o "% pago" e "R$ pagos de R$ total" refletem `totals.paid`; o filtro "Pagas" inclui a compra e "Pendentes" não; o chip diz "Paga" (CA-F13).
- **TASK-542**:
  - O limite efetivo de `post_max_size` e `upload_max_filesize` do host de produção fica registrado no `implementation.md` com a fonte (informada pelo usuário ou lida do painel do host). Se for menor que 5 MB mais o corpo do formulário, vira ajuste do host ou texto da mensagem 413; sem essa informação o PR segue, mas a pendência fica declarada na descrição.
- **TASK-543**:
  - `MANUAL.md` descreve o pagamento no cadastro por tipo (À Vista, Parcelada paga inteira, Fixa só a inicial), o aviso ao credor, o desfazer da compra e a janela de correção, e o aviso de que depois do fechamento do ciclo o pagamento não pode ser alterado; `MANUAL.pdf` é regenerado como nas features anteriores. `git diff --stat -- docs/ MANUAL.md MANUAL.pdf` lista só esses arquivos e a pasta da feature.
- **TASK-544**:
  - Roteiro executado na stack isolada (`validacao-local-stack-isolada`): cadastrar À Vista com foto e com descrição; Parcelada de 3 e de 12 parcelas e ver o chip "Paga" em cada ciclo ao navegar; Fixa e a ocorrência do mês seguinte pendente; cadastro por um membro que não é o credor e o aviso que o credor recebe; recusa de data futura; reenvio com a mesma chave (duplo clique e rede lenta simulada) sem duplicar; desfazer a compra dentro da janela e a recusa com um ciclo encerrado; trocar e desfazer o comprovante de uma parcela sem perder o arquivo das outras; 375 px sem scroll horizontal; iPhone: sem `capture`. Resultado de cada item no `implementation.md`, com o que foi observado.
  - `php artisan test`, `vendor/bin/pint --test`, `npx tsc --noEmit` e `npx vitest run --maxWorkers=1` verdes na branch da feature integrada.
  - O agent `pr-readiness-checker` rodado antes do PR, com o resultado no `implementation.md`.
  - Stack isolada encerrada ao final (processos, banco de validação e `backend/.env.testing` removidos; arquivos de comprovante do seed apagados só pelos caminhos do banco descartável).
  - A aplicação fica aberta e logada no Chrome do usuário, na tela certa, antes de limpar a stack. O usuário confirma **por escrito** que validou; sem essa confirmação **não há push nem PR**.
- **TASK-545**:
  - Testes novos em `ExpenseControllerStoreNoticesTest.php` (acréscimo ao arquivo da feature): com criador ≠ credor e foto, os devedores recebem `expense_paid` com `actorName` = **o criador**, o criador **não** recebe `expense_paid` e o WhatsApp do comprovante **não sai** (`Http::assertNothingSent`); o credor continua recebendo `expense_paid_on_behalf`. Com criador = credor nada muda (o `actorName` é o credor e o WhatsApp sai uma vez).
  - `Notifier::expensePaid` ganha o parâmetro opcional `?User $actor = null` (sem ele, comportamento de hoje); `NotifierTriggersTest` e `NotifierPaymentOnCreateTest` passam sem edição.
- **TASK-546**:
  - Com pagamento pedido, À Vista ou Fixa com `quotas` do cliente que não sejam exatamente uma, na data de `date_payment`, dão 422 e nada é criado; com uma só quota na data, 201. Com pagamento pedido, mais de 120 `quotas` dão 422 e nada é criado; 120 passam. Sem pagamento pedido, o contrato de hoje não muda (nenhum teste existente editado).
  - `earliestOccurrence` continua sendo a menor `date_expected`.
- **TASK-547**:
  - Teste com o corpo todo em **strings**, como o multipart real, e foto, criador = credor: nenhum `expense_paid_on_behalf` e nenhum `expense_paid` para o próprio credor (hoje o `store()` grava `user_payer_id` como string e as comparações estritas dos avisos falham).
  - `GET /api/expenses/{id}` e a resposta do `PUT` não trazem `client_request_id` nem `request_fingerprint`; o replay segue funcionando (lê os atributos direto).
  - Feito com `$casts` `integer` para `group_id`, `user_creator_id` e `user_payer_id` e `$hidden` com as duas colunas no `Expense` (em vez de `(int)` no `store()`: a conversão vale para todo caminho que grave o model, e o `Notifier` lê pelo atributo). Teste de unidade do model em `tests/Unit/ExpenseModelIdentifiersTest.php`, porque reler a despesa do banco sempre devolve inteiro e não prova o cast.
