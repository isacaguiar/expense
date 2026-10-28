# Specify — Pagamento no cadastro de despesa

> Feature: ao cadastrar uma despesa, o credor pode anexar a foto do comprovante e/ou escrever a descrição do pagamento, e a despesa já nasce paga, numa única requisição e sem estado intermediário. Na compra parcelada (modelo "compra no cartão") o pagamento vale para todas as parcelas. Pedido novo do usuário (2026-10-27), sem task prévia em `docs/sdd/03-tasks.md` e sem item equivalente no backlog (001–109 conferidos), por isso `/nova-feature`. Complementa as features `20261021-comprovante-ao-marcar-paga` e `20261022-editar-comprovante-depois-de-paga`.

Versão: 1.3 · Criado em: 20261027

**Status: v1.3, todas as decisões fechadas em 2026-10-27 (Constitution §5.2).** O usuário decidiu D1–D5 (algumas contra a recomendação da v1.0), aprovou D6–D10, D11 nasceu do brainstorm, e mandou **seguir as sugestões** para D12, D13 e D14 (v1.3). A v1.2 detalhou a prevenção de duplicidade (§2.5, R12, R23, D7, F6–F15). Nenhum código foi escrito. O `plan.md` foi iniciado a partir desta versão; `tasks.md` e `implementation.md` só têm título e data.

### Mudanças em relação à v1.0

| Tema | v1.0 | v1.1 (decisão do usuário) |
|---|---|---|
| Parcelada | pagava só a 1ª parcela | **paga todas as parcelas** (compra no cartão: paga uma vez, debitada mês a mês); valor travado depois |
| Criador ≠ credor | 422, nada criado | **registra em nome do credor e o avisa** (`paid_by` = credor) |
| Retroativa fechada | 422 se a 1ª parcela já nasce quitada | **mantém como está** (as parcelas fechadas seguem `born_paid`; as demais são pagas) |
| Competência futura | 422 | 422 quando a **ocorrência inicial** é futura; as parcelas seguintes de uma Parcelada podem ser futuras |
| Correção de erro | não tratada | **janela de correção** até o ciclo da compra encerrar (D11) |
| Conferência antes de salvar | tela de confirmação (palpite) | **sem tela**; aviso fixo na seção "Pagamento" |
| Impacto em relatórios e valores | não auditado | **auditado** (§2.13) e coberto por testes de não regressão (§3.7, §5.2) |
| Duplicidade de despesas | idempotência em linhas gerais (D7) | **em camadas** (§2.5, R12, R23, D7, D14, F6–F15): chave estável no cliente, trava síncrona, timeout, impressão digital e 409 no servidor, com os **limites declarados** |
| D12, D13, D14 | abertas | **fechadas (v1.3)**: lembrete na carência vira feature irmã (backlog 110); `grossDebts` fica como está (backlog 111); sem aviso de duplicidade nesta feature (backlog 112) |

---

## 1. Problema

Hoje o cadastro de despesa **nunca** registra pagamento. O `ExpenseForm` não tem campo de comprovante, e o servidor cria toda quota como pendente. Quem já pagou a conta (um boleto, um Pix, o aluguel, uma compra no cartão) precisa cadastrar a despesa, voltar à lista, clicar em ✓ "Marcar como paga" e só então anexar o comprovante ou escrever "pago em dinheiro". São dois passos em duas telas para um fato só.

O pedido é que o cadastro aceite, de forma opcional, a **foto do comprovante** e a **descrição do pagamento** e, havendo qualquer um dos dois, registre o pagamento (comprovante, descrição, data e responsável) **na mesma requisição**. Sem nenhum dos dois, nada muda.

**Por que a Parcelada paga todas as parcelas.** Compra parcelada costuma ser compra no cartão: o credor paga uma vez e o valor é debitado mês a mês. A compra é, portanto, **um pagamento só**, e marcar as N parcelas como pagas no cadastro espelha isso. Pagar não quita acerto (§2.7): os devedores continuam devendo a parte de cada mês.

Dois cuidados moldam a feature:

1. **Não pode haver meio-termo visível.** A interface não pode dizer "falhou" quando a despesa foi criada, nem criar duas vezes quando o usuário reenvia. A solução "chamar `store` e depois `pay`" tem exatamente essa janela (§2.3).
2. **Nenhum cálculo existente pode mudar.** A feature muda um **dado** (`paid`), não uma fórmula. O efeito sobre relatórios e valores foi auditado (§2.13) e é protegido por testes que provam a equivalência (§3.7).

Não contradiz `00-constitution.md`: a API muda de forma **aditiva** (Constitution §4 item 1), a migration só acrescenta coluna nullable (§4 item 2), e a regra de `pay()` de que o pagamento é do credor é preservada, porque o pagamento sempre é atribuído a ele (§6 item 5). Toda regra de negócio nova terá teste PHPUnit (§2 item 2) e Vitest.

### 1.1 Verificação prévia (AGENTS.md, "Quando não houver task aplicável")

| Onde | Resultado |
|---|---|
| `docs/sdd/03-tasks.md` | Nenhuma entrada sobre comprovante, cadastro com pagamento ou `ExpenseForm`. |
| `docs/feature/` (em andamento) | Nenhuma pasta relacionada. |
| `docs/backlog/` 001–109 | Nenhum item equivalente. Relacionados: **075** (pagamento por participante, **excluído** pelo pedido), 076, 078, 079, 080, 084 (concluído), 088, 090, 096, 097, 105. |
| Autorização | O pedido autoriza o escopo e a criação desta pasta. |

## 2. Achados confirmados

Conferidos lendo o código de `dev` (`03addf7904`) em 2026-10-27. Nada foi executado; onde algo é só leitura de código, está dito.

### 2.1 O cadastro cria tudo pendente, e o `paid: true` que o formulário manda é ignorado

- `ExpenseForm.tsx:105`: a quota da À Vista sai com `paid: true`. O servidor descarta o valor: "a despesa nasce PENDENTE — o cliente não decide o status inicial" (`ExpenseController.php:552-554`), e a única exceção é `born_paid`, calculada no servidor (`:560-579`, `'paid' => $bornPaid`).
- O formulário só tem Descrição, Valor, Tipo, Data, Parcelas, Pagador e Participantes (`ExpenseForm.tsx:165-247`). Não há seletor de foto nem campo de pagamento.
- O payload é JSON com `quotas` montadas no cliente (`buildInstallmentQuotas`, `ExpenseForm.tsx:101`); `POST /api/expenses` aceita `quotas` opcional desde a feature `20261008-rateio-parcelas-no-backend` (`ExpenseController.php:471-477`).

### 2.2 O que `pay()` faz, e que o cadastro teria de reproduzir

`ExpenseController::pay` (`:1067-1147`):

| Passo | Linhas | Efeito |
|---|---|---|
| Só o credor | `:1071-1073` | `auth()->id() !== user_payer_id` → 403. |
| Validação | `:1075-1081` | `comprovante` `nullable\|image\|max:5120`; `descricao` `nullable\|string\|max:500`; `cycles_ago`. |
| Quota alvo | `:1083-1090` | Escolhida por `cycles_ago` (competência vigente ou passada, nunca futura). `FIXED` materializa a ocorrência (`resolveQuotaForCycle`, `:1572-1584`). |
| Gravação | `:1092-1111` | `paid=true`, `paid_at=now()`, `paid_by=auth()->id()`, `payment_proof_path` (via `ProofStorage::store`), `payment_note` (`filled()`, de modo que a descrição `"0"` vale). |
| Selagem | `:1123` | `sealCycleIfSettled` pode congelar o ciclo. |
| Notificação | `:1127-1129` | `Notifier::expensePaid` na transição não-paga → paga. |
| WhatsApp | `:1134-1144` | Com foto, avisa os pagadores por `dispatch(...)->afterResponse()`. |

Trim e `""` → `null` vêm dos middlewares globais (`Http/Kernel.php`), então uma descrição só com espaços chega como `null`. Se isso cobre também NBSP e caracteres de largura zero **não foi verificado** (critério CA-02).

### 2.3 Duas chamadas independentes deixam estado inconsistente

Implementar "salvar a despesa e depois chamar `pay`" no cliente abre estas janelas:

| Falha | Resultado |
|---|---|
| `store` ok, `pay` falha (foto grande, rede, 5xx) | Despesa criada, sem pagamento. Se a tela diz "falhou" e o usuário reenvia, **duplica** a despesa. |
| `store` ok, `pay` ok, resposta perdida | Mesmo efeito: o usuário reenvia e duplica, agora com dois pagamentos. |
| Compensar apagando a despesa se `pay` falhar | `Notifier::expenseCreated` já avisou os participantes de uma despesa que some; a exclusão é soft delete e a compensação também pode falhar. |
| Parcelada: `pay()` só alcança a competência vigente ou passada (`cycles_ago >= 0`) | As parcelas futuras não podem ser pagas por chamadas a `pay`. |

Por isso a escolha é **uma só requisição** (D6).

### 2.4 O `store()` de hoje: transação existe, mas o desfecho "criou e respondeu erro" tem um caminho

- A criação da despesa, dos pagadores e das quotas está numa transação (`:529-582`).
- `Notifier::expenseCreated` e `expenseBornPaid` rodam depois do `commit`, **dentro** do `try` (`:585-591`). Hoje isso é seguro porque o `Notifier` engole exceções (`guard`, `Notifier.php:223-232`, só `Log::warning`).
- O `catch (\Throwable)` devolve `500` com `'details' => $e->getMessage()` (`:595-599`), problema já registrado no item **080**. Qualquer passo novo pós-`commit` que não seja protegido do mesmo modo (disparo de WhatsApp, limpeza de arquivo) viraria um `500` com a despesa já criada.

### 2.5 Não existe proteção contra duplicidade no projeto

Busca em `backend/` (fora de `vendor/`) e em `frontend/src` por `idempotency`, `Idempotency-Key` e `client_request_id`: nada. O que existe hoje:

| Camada | Hoje |
|---|---|
| Formulário | Só o `saving` (`ExpenseForm.tsx:43`, `:123`, `:250`), que é estado assíncrono. O envio usa `axios` puro (`:125-127`), sem interceptor; `api.ts` só define `baseURL`. |
| Rede | Nenhum **timeout** em `frontend/src`: uma requisição que trava deixa a tela em "Salvando…" até o usuário recarregar e tentar de novo. Nenhum retry automático. |
| Rota | `POST /expenses` (`routes/api.php:60`, `apiResource`) só tem o `throttle:api` global do grupo `api` (60 por minuto, por usuário ou IP: `Http/Kernel.php:44`, `RouteServiceProvider.php:27-29`). Ele limita volume e não detecta repetição: um reenvio passa. |
| Banco | Nenhuma chave única em `ex_expenses`; `store()` não consulta nenhuma chave natural. |

Cenários que duplicam hoje: (1) a despesa é criada e a resposta se perde (rede, aba fechada, timeout do servidor ou proxy) e o usuário reenvia; (2) clique e Enter em sequência, ou duas abas com o mesmo formulário; (3) a solução ingênua de chamar `store` e depois `pay` (§2.3). Em todos, o servidor cria uma despesa nova, porque nada diz que é a mesma tentativa. A idempotência (D7) resolve (1) e a repetição na mesma aba; **não** resolve duas abas nem recarregar a página (cada formulário tem a sua chave): são limites declarados (F7, F15, D14).

### 2.6 A ocorrência paga de cada tipo

| Tipo | Quotas criadas no `store()` | O que o pagamento no cadastro marca |
|---|---|---|
| À Vista (`IN_CASH`) | 1, `date_expected = date_payment` | A única. Nasce quitada. |
| Parcelada (`IN_INSTALLMENTS`) | N (2 a 120 se o servidor gera; N igual a `installments` se o cliente envia) | **Todas** as quotas que não nasceram `born_paid`. A ordem das quotas não importa. |
| Fixa (`FIXED`) | 1, em `date_payment` (`:511-513`) | A **inicial**. As ocorrências seguintes são projetadas mês a mês e materializadas só quando alguém as paga (`materializeFixedOccurrenceQuota`, `:1546-1562`); nascem pendentes. |

### 2.7 Pagamento não quita acerto: o que `paid` muda e o que não muda

`CycleSummaryService::compute`:

- `paid` entra em `totals.paid`/`totals.pending` (`:216-220`).
- `balances` e `settlements` ignoram `paid`; só `bornPaid` tira a dívida da conta (`:246-248`). Isso é deliberado e documentado ali ("NÃO filtrar por `$entry['paid']`"), e já tem teste: `test_balances_do_not_change_when_an_expense_is_paid_or_a_settlement_is_confirmed` (`CycleSummaryServiceTest.php:196`).
- O ciclo só conta como quitado quando `pending == 0` **e** cada par de `settlements` tem uma `SettlementConfirmation` (`isFullySettled`, `:360-398`).

Consequência: marcar as quotas como pagas no cadastro reduz o pendente, mas os devedores continuam devendo ao credor até confirmarem o acerto. O código já faz o que o pedido quer ("não quitar automaticamente os acertos entre membros"); a feature só precisa não criar `SettlementConfirmation` nem mexer em `compute()`.

### 2.8 A trava de edição passa a ser desejada, e há um beco sem saída

Regras que já existem em `ExpenseController`:

- `update()`: Parcelada com **qualquer** quota paga recusa a edição inteira (`:210-212`); despesa não-Fixa com quota paga recusa mudar valor ou tipo (`:214-216`).
- `destroy()`: recusa despesa com quota paga (`:374-376`).
- `unpay()`: só alcança `cycles_ago >= 0` (`:1173-1178`), então **parcela de competência futura não pode ser desfeita**.

O pedido quer o valor travado depois do pagamento (compra no cartão), então a trava de `update()` é desejada. O problema é a **correção**: uma compra paga por inteiro com um dado errado não pode ser editada, excluída, nem totalmente desfeita (as parcelas futuras ficam pagas). A saída é a janela de correção (D11, R19).

### 2.9 Quem cadastra pode não ser o credor

`store()` valida `user_payer_id` como membro do grupo (`:457`) e grava `user_creator_id = auth()->id()` (`:540`); o `user_creator_id` do corpo é ignorado. O formulário deixa escolher qualquer membro como Pagador (`ExpenseForm.tsx:212-227`). Criador e credor são pessoas diferentes em uso comum (`01-specify.md` §4, item 1).

`pay()` só aceita o credor. Há precedente de "quota nasce paga em nome do credor": a parcela retroativa (`born_paid`, item 038), resolvido **avisando** o credor (`Notifier::expenseBornPaid`). A decisão do usuário (D4) segue esse caminho: o pagamento é do credor e ele é avisado.

### 2.10 Upload: o limite herdado e uma fragilidade conhecida

- `ProofStorage::store` grava em `comprovantes/<grupo>/<uuid>.<ext>` no disco privado e **aborta com 500** se a gravação falhar, antes de qualquer escrita no banco (`ProofStorage.php:38-52`).
- A regra `image` do Laravel aceita SVG, e o download o serve inline sem `nosniff` (item **097**). Reutilizar a mesma validação não agrava, mas leva essa superfície ao cadastro (RK9).
- `ProofStorage::delete` existe e já é usado para compensar falha (`updatePaymentProof`, `:1298-1305`).
- O limite efetivo de corpo do servidor (`post_max_size`/`upload_max_filesize`) em produção não foi consultado (RK8).

### 2.11 Um mesmo comprovante em várias quotas quebra a remoção do arquivo

Com a D2, uma compra de N parcelas terá **um arquivo** referenciado por N quotas. Hoje o arquivo é apagado pelo caminho, sem checar quem mais o usa:

- `unpay()`: `ProofStorage::delete($quota->payment_proof_path)` (`:1188`).
- `pay()` repetido com foto nova: apaga o caminho anterior (`:1117-1121`).
- `updatePaymentProof()`: apaga o caminho anterior depois do commit (`:1318-1322`).

Desfazer ou trocar o comprovante de **uma** parcela apagaria o arquivo das outras. Os três pontos precisam de uma checagem de referência (R18). O download (`ProofDownloadController.php:46`) lê o caminho por quota, então vários caminhos iguais são servidos normalmente.

### 2.12 Como o fechamento do ciclo funciona hoje

Nada roda em horário nenhum:

- **Por data:** calculado a cada leitura. `BillingCycle::statusOf` devolve `closed` quando hoje ≥ fim do ciclo + 5 dias (`GRACE_DAYS`); entre o fim do ciclo e essa data é a **carência** (`open`, `in_grace`). Ninguém é avisado nessa virada.
- **Manual:** `close()` (`:809-872`) grava o snapshot com `closed_manually_at` e dispara `Notifier::cycleClosed` para os outros membros. `reopen()` (`:882-915`) desfaz enquanto o ciclo não selou.
- **Selagem:** `sealCycleIfSettled` (`:1473-1519`) roda nas ações (`pay`, `confirmSettlement`, `close`) e de forma preguiçosa na leitura de `summary()` (`:686-699`); `Notifier::cycleSettled` dispara só na transição (`wasSealed`).
- **Infra:** `Console/Kernel::schedule` está vazio e não existe `app/Console/Commands`; as ADR-006 e ADR-008 registram que a hospedagem não roda `schedule:run` de forma confiável.
- **Aviso passivo que já existe:** `CycleClosingAlert` mostra, na carência, que o ciclo segue editável até a data de corte (Home e Despesas).

É a base da D12.

### 2.13 Auditoria de impacto em relatórios e valores

Busca por `paid`, `paid_at`, `paid_by`, `born_paid`/`bornPaid` em `backend/app` e `frontend/src`. A feature não altera nenhuma fórmula; altera o dado `paid` (e `paid_at`, `paid_by`, comprovante e nota). Resultado por consumidor:

| Consumidor | Onde | Lê `paid`? | Com a compra paga no cadastro | Cobertura hoje |
|---|---|---|---|---|
| `balances`, `settlements`, `valuePerPerson` | `CycleSummaryService.php:164-314` | **Não** (só `bornPaid`) | Idênticos | `CycleSummaryServiceTest.php:196` |
| `totals.total` | `:217` | Não | Idêntico | `ExpenseControllerSummaryTest` |
| `totals.paid`, `totals.pending` | `:218-219` | Sim | Mudam só pelo valor das quotas pagas **de cada ciclo** | `ExpenseControllerSummaryTest` |
| `isFullySettled`, selagem | `:360-398`; `ExpenseController.php:1473` | Sim (`pending`) | Igual a `pay()`: o ciclo só sela com todos os acertos confirmados | `ExpenseControllerCycleIsFullySettledTest` |
| `focus()`, `pendingCycles` (`pending_expenses_count`, `awaiting_seal`) | `:418-472`, `:537-594` (`:587`, `:589`) | Sim | Igual a `pay()` | `FocusCycleTest`, `ExpenseControllerPendingCyclesTest` |
| `ClosingBreakdown`, `closingReport` | `ClosingBreakdown.php:41-70` | **Não** (só `bornPaid`) | Idênticos | `ClosingBreakdownTest`, `ExpenseControllerClosingReportTest` |
| `cycleHistory`, snapshots | `ExpenseController.php:926+`, `:1473-1519` | Congelam `paid`, `paymentNote`, `paymentProofUrl` | Mesmas chaves; só esses valores diferem | `ExpenseControllerCycleHistoryTest`, `...CycleFreezeTest` |
| **`grossDebts`** (Dashboard, árvore credor→devedores) | `ExpenseController.php:983-1023` (`:987`) | **Sim: pula a quota paga** | A dívida bruta de uma compra paga **some desse painel**, enquanto `settlements` ainda a cobra. A divergência já existe com `pay()` por parcela; passa a ser frequente (D13) | `ExpenseControllerGrossDebtsTest` |
| `PersonalOverviewService` (`/api/me/overview`) | `:108-118`, `:168-212` | Sim em `my_items[].paid`, `can_pay`, `can_unpay`, `pending_items_count`; `owe`/`receive` vêm de `settlements` | `owe`/`receive` idênticos; itens ficam "Paga" | `PersonalOverviewServiceTest`, `OverviewControllerTest` |
| Relatórios anuais (`GroupExpenseReportController`) | arquivo inteiro | **Não** (nem lê `Quota`) | Idênticos | `GroupExpenseReportControllerTest` |
| `indexByGroup`, `getMonthlyExpenses` | `ExpenseController.php:26-110`, `:637-656` | Não | Idênticos | `...IndexByGroupTest`, `...GetMonthlyExpensesTest` |
| **`paid_at`, `paid_by`** | todo `backend/app` | **Nenhum cálculo os lê**; só são gravados e zerados (`store`, `pay`, `unpay`) e expostos na quota | Gravar `paid_at = now()` nas parcelas futuras **não desloca valor** para o mês do cadastro | nenhuma (teste novo, NR-03) |
| Frontend | `CycleExpenseList`, `MyItemsList`, `ExpenseManager`, `Payments`, `CycleTotalsCards`, `PendingCyclesList`, `utils/expenseFilters.ts:70-71`, `ExpenseManager.tsx:313`, `ExpenseView.tsx:91` | Sim | Chip "Paga", filtro de status, "% pago", "R$ pagos de R$ total", `canDelete` e trava de edição acompanham o `paid` do servidor | testes dos componentes |

Conclusões:

1. **A feature não precisa tocar nenhum cálculo.** O que ignora `paid` fica bit a bit igual; o que lê `paid` muda exatamente como mudaria se o credor marcasse cada parcela com ✓.
2. **Não há risco de contar a compra duas vezes nem no mês do cadastro**, porque nenhum cálculo usa `paid_at`: cada quota entra no ciclo da sua `date_expected` (`CycleSummaryService.php:77-99`).
3. **O único ponto de atenção é `grossDebts`**, que difere de `settlements` quanto a `paid`. Não é regressão (existe hoje), mas a Parcelada paga no cadastro o torna visível em toda compra no cartão. Vira a D13.
4. A prova disso é a suíte de equivalência da §3.7, não esta leitura.

## 3. Requisitos

### 3.1 O que a feature entrega

1. **Backend:** `POST /api/expenses` aceita, de forma opcional, `comprovante` (arquivo), `descricao_pagamento` (texto) e uma chave de idempotência. Havendo foto ou descrição não vazia, cria a despesa **e** registra o pagamento numa única transação (À Vista: a quota; Parcelada: todas as quotas não `born_paid`; Fixa: a inicial).
2. **Correção:** "Desfazer pagamento da compra", que desfaz de uma vez as quotas pagas, dentro da janela de correção (D11).
3. **Arquivo compartilhado:** o comprovante pode ser referenciado por várias quotas e só sai do disco quando nenhuma o usa mais.
4. **Aviso ao credor** quando outra pessoa registra o pagamento em nome dele.
5. **Frontend:** o `ExpenseForm` ganha a seção "Pagamento (opcional)" e a ação de desfazer a compra.
6. **Robustez:** falhas de upload e de banco não deixam despesa nem arquivo órfão; reenvio não duplica.
7. **Prova de que nada mudou nas contas:** suíte de não regressão (§3.7).
8. **Documentação:** `MANUAL.md` e `MANUAL.pdf`.

### 3.2 Regras adotadas

Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Foto e descrição do pagamento são **opcionais e independentes**. Sem nenhum dos dois (ausentes, vazios ou só espaços), o comportamento é o de hoje, byte a byte: despesa pendente, mesmas notificações, mesma resposta. |
| R2 | "Pagamento pedido" = há arquivo de foto **ou** descrição do pagamento não vazia depois do `trim`. Foto presente e inválida dá **422 mesmo havendo descrição válida**: a foto nunca é descartada em silêncio para pagar só com o texto. |
| R3 | A descrição do pagamento é um campo **distinto** da descrição da despesa: outro nome na API (`descricao_pagamento`, não `description`), outra coluna (`ex_quotas.payment_note`, não `ex_expenses.description`), outro rótulo na tela. Máximo de 500 caracteres. Espaços, tabs, quebras de linha e NBSP não formam uma descrição válida; `"0"` forma (mesma regra `filled()` de `pay()`). |
| R4 | Cada quota paga recebe: `paid=true`, `paid_at=now()` (momento do cadastro, o mesmo em todas as quotas da compra; não há campo de data do pagamento), `paid_by` = **o credor** (`user_payer_id`), `payment_proof_path` e `payment_note`. `born_paid` fica `false`. **`born_paid` nunca é usado para representar um pagamento comum.** |
| R5 | O pagamento é **do credor**. Quando quem cadastra é outra pessoa, o pagamento é registrado em nome do credor e ele é **avisado** (R21); o criador continua como `user_creator_id`. Quem não é membro do grupo recebe o 404/403 de hoje, sem upload gravado. |
| R6 | Competência: se a **ocorrência inicial** (a única, a 1ª parcela ou a inicial da Fixa) cai em competência **futura**, o pedido de pagamento dá 422 e nada é criado. As parcelas seguintes de uma Parcelada podem cair em ciclos futuros e ficam pagas (D2). A checagem de competência fechada que `store()` já faz continua valendo e roda antes de qualquer escrita. Casos no §3.4.1. |
| R7 | **A compra é um ato só.** À Vista e Fixa: uma quota. Parcelada: todas as quotas que não nasceram `born_paid`, na mesma transação e com os mesmos `paid_at`, `paid_by`, comprovante e descrição. |
| R8 | As quotas a pagar são identificadas pelas **quotas recém-criadas**, não por `cycles_ago` relativo a "agora" (item 090). |
| R9 | A tela diz, antes de salvar: (a) qual ocorrência ou quais parcelas serão marcadas como pagas ("serão marcadas como pagas as N parcelas"); (b) em nome de quem; (c) o **aviso fixo**: "depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado". **Não há tela de confirmação** antes de salvar. |
| R10 | **Atomicidade.** Despesa, pagadores, quotas, pagamento e arquivo: tudo ou nada. Falha em qualquer ponto antes da confirmação não deixa despesa, quota nem arquivo gravados. |
| R11 | Passos pós-confirmação (notificações, WhatsApp, limpeza) nunca transformam a resposta em erro: a despesa já existe. Num **reenvio idempotente** esses passos **não** rodam de novo: não grava arquivo, não cria quotas, não notifica. |
| R12 | **Idempotência.** Reenvio com a mesma chave (`client_request_id`) do mesmo criador não cria uma segunda despesa nem um segundo pagamento (D7). Mesma chave com dados diferentes é recusada com 409; a chave de uma despesa apagada também. A proteção só vale para quem manda a chave; sem chave, o comportamento é o de hoje. |
| R13 | Contrato **aditivo** (Constitution §4 item 1): campos novos opcionais; o JSON de hoje continua válido sem alteração. Migration só com coluna nullable (§4 item 2). |
| R14 | **Nenhum cálculo existente muda.** Não se cria `SettlementConfirmation`; `compute()`, `grossDebts`, `ClosingBreakdown`, `PersonalOverviewService` e os relatórios não são alterados. Muda só o dado `paid` (e `paid_at`, `paid_by`, comprovante, nota), com o efeito já descrito na §2.13. |
| R15 | Mesmos limites de arquivo de `pay()`: `image`, até 5120 KB, `ProofStorage::store` em disco privado por grupo, URL assinada de 30 minutos (ADR-005), sem mudança. |
| R16 | O seletor de foto não usa `capture` (bugfix `20261027-comprovante-iphone-so-camera`): no iPhone o usuário precisa poder escolher foto da galeria ou arquivo. |
| R17 | O que o frontend valida é só UX (Constitution §1 item 1): o servidor é sempre a autoridade das regras de competência, autorização, ocorrência e janela de correção. |
| R18 | **Arquivo compartilhado.** Todas as quotas da compra apontam para o mesmo caminho. O arquivo só é apagado do disco quando **nenhuma outra quota** o referencia, em `unpay()`, `pay()` repetido, `updatePaymentProof()` e no desfazer da compra, sempre depois de o banco ter sido atualizado. |
| R19 | **Janela de correção.** "Desfazer pagamento da compra" desfaz de uma vez todas as quotas pagas e não `born_paid` da despesa (apaga `paid`, `paid_at`, `paid_by`, comprovante e nota). Só o credor. Só é aceito enquanto **nenhuma** dessas quotas estiver em ciclo **encerrado**: `closed` por data, `closed_manually` ativo ou selado. É atômico. Fora da janela dá 422 e nada muda. Depois do desfazer, edição e exclusão voltam a valer pelas regras existentes. |
| R20 | **Valor travado.** Com quota paga, a Parcelada não é editável (`update`, `:210-212`) e nenhuma despesa paga é excluída (`destroy`, `:374-376`). São regras existentes e não mudam. |
| R21 | **Avisos, um conjunto por cadastro**, nunca um por parcela: `expenseCreated` como hoje; `expensePaid` uma vez, com o valor total da compra; WhatsApp de comprovante uma vez, quando há foto; e, se o criador não é o credor, **um aviso novo ao credor** ("fulano registrou o pagamento de X em seu nome"), só in-app. Nesse caso (criador ≠ credor) o `expensePaid` aos demais devedores leva o **nome de quem registrou** (o criador, que não recebe esse aviso) e o WhatsApp do comprovante **não sai**: o texto dele afirma que o credor pagou (ajuste da TASK-545, achado N1 da revisão de segurança; `plan.md` P10). |
| R22 | O ✓ "Marcar como paga" e o "Desfazer pagamento" **por parcela** continuam como hoje, inclusive numa compra paga no cadastro. A compra pode ficar "parcial" se alguém usá-los; isso é comportamento existente, fora desta feature. |
| R23 | **Chave no cliente.** O formulário gera a chave (`crypto.randomUUID()`) ao montar e a guarda em `useRef` durante toda a sua vida: todo reenvio usa a **mesma** chave, mesmo depois de o usuário editar campos; no sucesso a tela sai. O envio tem **trava síncrona** (`useRef`, porque `saving` é estado assíncrono) e **timeout explícito** (valor no plan, com folga para foto de até 5 MB em rede móvel). Em erro de rede ou timeout, a mensagem manda conferir a lista antes de tentar de novo e diz que reenviar não duplica. |

### 3.3 Contrato da API (proposta, aditiva)

`POST /api/expenses`, mesma rota e mesmos campos de hoje, mais:

| Campo | Tipo | Obrigatório | Observação |
|---|---|---|---|
| `comprovante` | arquivo | não | Mesma regra de `pay()`. Exige `multipart/form-data`. |
| `descricao_pagamento` | string ≤ 500 | não | Funciona também em JSON. |
| `client_request_id` | uuid | não para a API, mas **o web sempre envia** | Chave de idempotência, única por (`user_creator_id`, chave). O cabeçalho `Idempotency-Key` é a alternativa; CORS já aceita qualquer cabeçalho (`config/cors.php`, `allowed_headers: ['*']`). O plan escolhe. Sem a chave não há proteção contra duplicidade (R12). |

Sem esses campos, nada muda. Com foto, o cliente envia `multipart` (`payers[]`, `quotas[i][...]` como campos de formulário); sem foto, continua JSON. A resposta mantém `message` e `expense_id` e ganha campos aditivos: `payment_registered` e, no reenvio idempotente, a indicação de que a despesa já existia (status 2xx, 200 ou 201, o plan decide, com o mesmo `expense_id`). **409** com mensagem própria em dois casos: mesma chave com dados diferentes e chave de despesa apagada. A impressão digital do corpo (D7) é interna e **não** vai na API.

**Desfazer a compra:** parâmetro novo (aditivo) em `unpay` ou rota nova; o plan decide. O servidor informa, junto da despesa, se o desfazer ainda é possível (campo aditivo), para a tela não duplicar a regra (R17).

Os nomes acima são proposta de trabalho; a escolha final é do plan.

### 3.4 Decisões

Resolução registrada em 2026-10-27. Todas as decisões estão fechadas; D12, D13 e D14 foram fechadas na v1.3, seguindo a sugestão.

| # | Decisão | Opções | Resolução | Por quê |
|---|---|---|---|---|
| D1 | **À Vista** | A) paga a ocorrência única, que nasce quitada<br>B) não oferecer | **A** (usuário) | Uma despesa, uma quota, sem ambiguidade. |
| D2 | **Parcelada** | A) só a 1ª parcela (v1.0)<br>B) **todas** as parcelas, pelo credor<br>C) não oferecer | **B** (usuário, contra a recomendação da v1.0) | Compra parcelada costuma ser compra no cartão: um pagamento só, debitado mês a mês. O valor não pode ser alterado depois. Custos tratados: o beco sem saída (D11), o arquivo compartilhado (R18) e o aviso único (R21). |
| D3 | **Fixa** | A) só a ocorrência **inicial**<br>B) não oferecer | **A** (usuário) | A Fixa não tem fim; as seguintes nascem pendentes. |
| D4 | **Criador ≠ credor** | A) **registra em nome do credor e o avisa**<br>B) recusar com 422 (v1.0)<br>C) registrar e deixar pendente de confirmação do credor | **A** (usuário) | O pagamento é do credor, não de quem cadastra. Salvaguardas: `paid_by` é o credor; o criador segue visível como criador; o credor recebe aviso (R21) e pode desfazer pelo ✓/"Desfazer" ou pelo desfazer da compra. Mesmo precedente da parcela retroativa (item 038). |
| D5 | **Competências futuras e retroativas** | A) segue as regras existentes, 422 antes de criar quando a ocorrência inicial é futura; retroativa fechada mantém o comportamento de hoje<br>B) outras | **A** (usuário) | Casos no §3.4.1. Nenhum caso usa `born_paid` para pagamento comum. |
| D6 | **Quantas chamadas** | A) **uma** requisição `POST /api/expenses`, tudo numa transação<br>B) `store` e depois `pay`<br>C) rota nova | **A** (aprovada) | B tem as janelas do §2.3 e `pay` não alcança parcela futura. C duplica validação. A é aditiva. |
| D7 | **Idempotência** | A) colunas `client_request_id` e `request_fingerprint` em `ex_expenses` (nullable, aditivas), índice único por (`user_creator_id`, `client_request_id`); mesma chave e mesmos dados devolve a despesa existente; dados diferentes ou despesa apagada dão 409<br>B) trava de cache com TTL<br>C) só desabilitar o botão | **A** (aprovada; detalhada na v1.2) | C não cobre resposta perdida nem timeout. B expira e depende do driver de cache. A sobrevive a reinício e a concorrência. **Ordem no servidor:** validar → localizar a chave → só então gravar o arquivo → transação. **Chave existente:** impressão digital igual, devolve a mesma despesa (2xx) sem gravar arquivo, criar quotas nem notificar; diferente, 409; despesa `deleted`, 409 (nunca recria). **Corrida:** a violação de unicidade faz rollback, remove o arquivo da perdedora, relê e devolve a existente. A impressão digital (sha-256) é calculada sobre os campos **normalizados** (grupo, data, descrição, tipo, parcelas, valor, credor, participantes ordenados, quotas, descrição do pagamento) e sobre o **hash do conteúdo da foto** (RK21). |
| D8 | **Selagem do ciclo no cadastro** | A) **não selar** no cadastro<br>B) selar como `pay()` | **A** (aprovada) | O ciclo alvo é aberto, e `store()` não consulta nem desfaz selagem (RK6). O estado "todo quitado, ainda sem selagem" já existe e é tratado (`awaiting_seal`). |
| D9 | **Notificações** | A) reaproveitar as de hoje, em um conjunto por cadastro (R21)<br>B) só `expenseCreated`<br>C) tipo novo "criada e paga" | **A** (aprovada), mais o aviso ao credor da D4 | Sem template novo na Meta. |
| D10 | **`details` no 500 (item 080)** | A) o plan reescreve o bloco e fecha o 080<br>B) deixa o 080 aberto | **A** (aprovada) | O trecho é reescrito de qualquer modo (R10, R11). |
| D11 | **Janela de correção** | A) desfazer a compra enquanto nenhuma quota paga estiver em ciclo **encerrado**; depois, nada<br>B) liberar a exclusão da despesa<br>C) só avisar na tela | **A** (usuário: "ciclo encerrado"; sem alteração depois dele) | O ciclo encerrado é histórico: acertos já foram confirmados em cima dele. Janela = o ciclo da 1ª parcela mais 5 dias de carência. `closed_manually` e selado contam como encerrado (salvaguarda). Quotas `born_paid` não contam. |
| D12 | **Lembrete de revisão no início da carência** | A) feature irmã pequena, no padrão de hoje: na 1ª leitura de `summary()` durante a carência, se não houver marcador de envio do ciclo, `Notifier` a todos os membros e grava o marcador (como `cycleSettled`), sem cron<br>B) agendador (cron do cPanel via SSH)<br>C) incluir nesta feature<br>D) sem lembrete (fica o aviso fixo e o `CycleClosingAlert`) | **A** (usuário, "seguir a sugestão"): feature irmã, fora desta; backlog **110** | Serve a todos os grupos e não só a esta feature. Dispara na 1ª abertura do app na carência, não no instante exato. Precisa de marcador, tipo novo de notificação e gancho só em `summary()` (o `view()` do Painel é deliberadamente sem efeito colateral). |
| D13 | **`grossDebts` e `paid`** | A) manter como está (pula quota paga), com teste que o fixa e item de backlog para a divergência com `settlements`<br>B) alinhar `grossDebts` a `settlements` (ignorar `paid`) | **A** (usuário, "seguir a sugestão"): `grossDebts` fica como está, fixado por teste (NR-07); divergência no backlog **111** | B muda um cálculo que funciona, contra o pedido de não impactar. A divergência já existe com `pay()` por parcela; passa a aparecer em toda compra no cartão. O Dashboard mostraria "sem devedores" para a compra enquanto "Valores a pagar" a cobra. |
| D14 | **Aviso de possível duplicidade por chave natural** (mesmo grupo, descrição, valor, data e credor, mesmo criador, em poucos minutos) | A) fora desta feature, como candidato a backlog<br>B) aviso no formulário, com confirmação "cadastrar mesmo assim"<br>C) bloqueio no servidor | **A** (usuário, "seguir a sugestão"): fora desta feature; backlog **112** | A idempotência já resolve o reenvio. B e C atacam o caso "recarreguei ou abri em outra aba e cadastrei de novo", mas geram falso positivo em gastos legítimos repetidos (dois cafés no mesmo dia) e exigem endpoint de checagem ou um 409 com confirmação. C bloquearia uso legítimo. |

#### 3.4.1 Casos de competência (D5)

`BillingCycle::statusFor` devolve `closed` quando hoje ≥ fronteira + 5 dias, `future` quando o início do ciclo é depois de hoje, e `open` no resto (inclui a carência).

| Caso | Resultado com pagamento pedido |
|---|---|
| Competência vigente | Cria e paga. |
| Em carência (`open`, fronteira passou há menos de 5 dias) | Cria e paga. |
| À Vista ou Fixa em competência fechada (por data ou fechamento manual) | **422**, a mesma recusa que `store()` já dá (`rejectIfCompetenceClosed`, `:499-504`). |
| Ocorrência inicial em competência **futura** (qualquer tipo) | **422**, nada criado. A mesma despesa sem pagamento continua aceita. |
| Parcelada com a 1ª parcela no ciclo vigente ou em carência e as demais futuras | Cria e paga **todas**, inclusive as futuras. |
| Parcelada retroativa (parcelas em ciclo fechado e ao menos uma aberta) | As fechadas seguem `born_paid`, sem arquivo nem nota e com o aviso `expenseBornPaid` de hoje. As demais são pagas. |
| Parcelada inteira em competências fechadas | **422** de hoje. |

### 3.5 Falhas e consistência

Qualquer falha que **recuse** o pagamento acontece **antes** de qualquer escrita, de modo que "despesa criada sem o pagamento pedido" nunca é um resultado possível.

| # | Quando | Resultado | Banco e disco |
|---|---|---|---|
| F1 | Foto não é imagem ou passa de 5120 KB | 422, mensagem do campo `comprovante` | Nada criado. |
| F2 | Corpo maior que o limite do PHP/servidor | 413 com mensagem clara | Nada criado. |
| F3 | Falha ao gravar o arquivo (disco cheio, permissão) | Erro, mensagem genérica | Nada criado (`ProofStorage` aborta antes do banco). |
| F4 | Falha no banco depois do arquivo gravado | 500 genérico, **sem `details`** | Rollback e arquivo removido. Nada criado. |
| F5 | Falha em notificação, WhatsApp ou limpeza depois da confirmação | Resposta de sucesso | Despesa e pagamento persistidos; só `Log::warning`. |
| F6 | Sem resposta (timeout, rede) e o usuário reenvia **com a mesma chave** | Se a primeira chegou, devolve o mesmo `expense_id` (2xx) sem gravar arquivo, criar quotas nem notificar; se não chegou, cria normalmente | Uma só despesa. |
| F7 | Duplo clique ou Enter repetido **na mesma aba** | A trava síncrona deixa passar uma só requisição; se uma segunda chegar ao servidor, é um replay (F6) | Um só conjunto de quotas pagas, um só arquivo. **Duas abas** têm chaves diferentes e valem como dois cadastros: limite declarado (D14). |
| F8 | Mesma chave, dados diferentes (inclui foto diferente) | 409: "esta despesa já foi cadastrada com outros dados; confira a lista" | Nada novo, nada sobrescrito. |
| F9 | Regra recusa o pagamento (R6, competência) | 422 com o motivo | Nada criado. |
| F10 | Duas requisições com a mesma chave ao mesmo tempo | Ambas 2xx com o mesmo id, nenhum 500 | Uma só despesa e um só arquivo; o arquivo da perdedora é removido. |
| F11 | Desfazer a compra falha no meio | Erro, nada muda | Transação com lock de linha nas quotas; o arquivo só é apagado depois do commit e só sem outra referência. |
| F12 | Desfazer a compra enquanto outra ação (✓, `unpay`, edição de comprovante) atua nas mesmas quotas | A que chega depois relê o estado e responde conforme ele | Sem arquivo apagado de quota que ainda o usa. |
| F13 | Aviso ao credor falha | Resposta de sucesso | `Notifier` é best-effort (`guard`). |
| F14 | Reenvio com a chave de uma despesa que já foi apagada | 409 | Nada é recriado. |
| F15 | O usuário **recarrega a página** depois de uma resposta perdida | O formulário novo tem outra chave, então um novo cadastro pode duplicar a despesa que chegou. **Limite declarado** | A mensagem de erro de rede manda conferir a lista antes de tentar de novo (R23). Persistir a chave em `sessionStorage` é melhoria possível (§6). |

Depois de qualquer falha, o formulário mantém todos os campos, inclusive a foto escolhida e a chave, e o botão volta a habilitar.

### 3.6 Interface

- Seção "Pagamento (opcional)" no `ExpenseForm`, antes dos botões, com o seletor de foto e o campo de descrição do pagamento. O plan deve **extrair** esses campos de `PaymentProofDialog` em vez de duplicá-los, para a regra "foto ou descrição não vazia" (`canConfirmPayment`) e o limite de 500 (`NOTE_MAX_LENGTH`) terem uma só fonte.
- Rótulos que não se confundem: "Descrição" (da despesa) e "Descrição do pagamento (opcional)", com contador de 500.
- Texto que muda com o tipo e a data (R9): À Vista, "será marcada como paga: esta despesa"; Parcelada, "serão marcadas como pagas as N parcelas (compra paga pelo credor, por exemplo no cartão); o valor não poderá ser alterado"; Fixa, "apenas a ocorrência de mês/ano; as próximas continuam pendentes".
- Aviso fixo (R9): "depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado".
- Pagador diferente do usuário logado: a seção continua habilitada e diz "o pagamento será registrado em nome de {Pagador}, que será avisado".
- Na tela de Despesas e no detalhe, a ação **"Desfazer pagamento da compra"** aparece para o credor só quando o servidor diz que a janela está aberta e a despesa é Parcelada com quotas pagas.
- Salvar fica desabilitado enquanto envia. `trackEvent('expense_created')` continua sendo medido uma vez, só no sucesso.
- Mensagens do servidor aparecem no alerta da tela (nunca `window.alert`), com texto próprio para 413, 409 e timeout (R23).

### 3.7 Estratégia de testes de não regressão

Como a feature muda um dado e não uma fórmula (R14), o teste certo é de **equivalência de estado**: o cadastro com pagamento tem que produzir as mesmas saídas que o cadastro sem pagamento seguido de `paid=true` posto nas mesmas quotas. Onde a saída ignora `paid`, é igual à do cadastro sem pagamento.

1. **Ordem.** Os testes de caracterização são escritos **antes** do código da feature (primeiras tasks) e ficam **verdes no código atual**, com `paid` posto por factory. Depois da feature, os mesmos casos rodam também pelo `POST /api/expenses`. Os testes existentes de `backend/tests` não são editados.
2. **Consumidores cobertos:** todos os da tabela da §2.13.
3. **Formas de grupo:** À Vista; Parcelada de 2, de 3 (com resto de centavo, ex. 100,00 → 33,33 / 33,33 / 33,34) e de 12; Fixa no mês inicial e no seguinte; Parcelada retroativa com `born_paid`; credor que participa e que não participa da divisão; 2 e 3 membros; dívidas em sentido contrário (netting).
4. **Varredura por ciclo:** com `Carbon::setTestNow` em cada um dos N ciclos da Parcelada.
5. **Soma:** a soma de `totals.paid` ao longo de todos os ciclos é igual ao `total_value` ao centavo, e no mês do cadastro `totals.paid` é só a quota daquele mês.
6. **Independência de `paid_at`/`paid_by`:** mudar esses campos não altera nenhuma saída.
7. O `focus()` ganha esta forma no teste que compara com a varredura completa (`CycleSummaryServiceTest.php:516`).

## 4. Riscos

| # | Risco | Impacto | Tratamento |
|---|---|---|---|
| RK1 | Janela "criada, mas a tela diz que falhou" com duas chamadas | Despesa duplicada, pagamento órfão | D6-A, R10, R12, F6/F7 |
| RK2 | Passo novo pós-`commit` dentro do `try` do `store()` | `500` com despesa criada (§2.4) | R11, F5, CA-19; o plan tira esses passos do `try` |
| RK3 | Chave de idempotência: reuso após edição, dados diferentes ou despesa apagada | Devolver a despesa velha como se fosse a nova | D7-A: 409 por impressão digital diferente e por despesa `deleted`; a mensagem manda conferir a lista; CA-22 e CA-33 |
| RK4 | Pagamento registrado em nome de outra pessoa (D4) | Prova e frase atribuídas ao credor sem ele ter agido | Aviso ao credor (R21), `paid_by` = credor, criador visível, desfazer disponível ao credor; matriz de testes com os três papéis (credor, criador não-credor, não membro) |
| RK5 | Compra paga por inteiro com dado errado (beco sem saída, §2.8) | Erro só corrigível por banco | Janela de correção (D11, R19); aviso fixo (R9); lembrete (D12) |
| RK6 | `store()` não consulta nem desfaz selagem de ciclo aberto | Despesa nova pendente em ciclo já selado pode não aparecer em `summary()` (lido no código, **não reproduzido**) | D8-A não sela; verificação como task de investigação; candidato a backlog (§6) |
| RK7 | Duas notificações próximas para o mesmo ato | Ruído | D9-A assumido; reversível |
| RK8 | Multipart com `payers[]` e `quotas[i][...]`; limites do servidor | Tipos viram string, `max_input_vars`, `post_max_size`, 413 | CA-27; consultar `post_max_size`/`upload_max_filesize` de produção antes do plan fechar |
| RK9 | Cadastro herda a validação `image` (aceita SVG, item 097) | Mais um ponto de upload | Mesma validação de `pay()` (R15), sem agravar; endurecimento fica no 097 (restringir `pay()` seria mudança de contrato, Constitution §4 item 1) |
| RK10 | Queda do processo entre gravar o arquivo e confirmar a transação | Arquivo órfão no disco privado | Mesma exposição de `pay()`; gravar o arquivo o mais tarde possível, depois das validações; compensação em falha normal (F4) |
| RK11 | **Arquivo compartilhado entre N quotas** | Desfazer ou trocar o comprovante de uma parcela apaga o arquivo das outras (§2.11) | R18 com checagem de referência nos três pontos de remoção e no desfazer em lote; CA-28 |
| RK12 | Virada do ciclo ou fuso perto da fronteira | 422 inesperado na ocorrência inicial | Servidor é a autoridade (R17); a mensagem diz o motivo |
| RK13 | Descrição só com caracteres invisíveis (U+200B) que a tela conta como texto e o servidor descarta | Usuário acha que pagou; a despesa fica pendente em silêncio | CA-02 e `payment_registered` na resposta; a tela avisa se pediu e não foi registrado |
| RK14 | Transação com até 120 quotas, mais o desfazer em lote com lock | Transação mais longa que hoje; contenção | Arquivo gravado fora da transação; cenário raro; teste com 120 parcelas |
| RK15 | **`grossDebts` ignora quota paga, `settlements` não** (§2.13) | O Dashboard mostra "sem devedores" na compra, e Pagamentos a cobra | D13; teste que fixa o comportamento (NR-07); candidato a backlog |
| RK16 | Janela de correção curta (ciclo da 1ª parcela + 5 dias) | O erro percebido depois não tem correção no app | Aviso fixo (R9); lembrete na carência (D12) |
| RK17 | ✓/"Desfazer" por parcela deixam a compra "parcial" (R22) | Compra no cartão com parte paga e parte pendente | Comportamento existente, preservado; fora de escopo |
| RK18 | Parcelas futuras já nascem "Paga" | Quem lê "Paga" pode entender que os devedores já repassaram | Semântica existente: `paid` é do credor com o fornecedor; acerto segue em `settlements` (§2.7); texto da tela e `MANUAL.md` explicam |
| RK19 | Desfazer a compra concorrendo com ✓, `unpay` ou edição de comprovante | Estado misto, arquivo apagado de quota que o usa | F11/F12: lock de linha, releitura do estado, remoção só depois do commit e sem referência |
| RK20 | Duas abas, recarregar a página depois de resposta perdida, e clientes que não mandam a chave | Duplicidade que a chave não cobre | Limites declarados (R12, F7, F15); o web sempre envia a chave; mensagem de rede (R23); D14 como camada extra possível |
| RK21 | Impressão digital instável | 409 falso: o mesmo cadastro reenviado com `payers` em outra ordem, ou valor `"10"` no lugar de `10.00`, seria visto como dados diferentes | Normalizar antes do hash (ordenar `payers`, valores em centavos, datas `Y-m-d`, textos aparados); CA-34 |

## 5. Critérios de aceite

Cada item vira teste (PHPUnit para `CA-` e `NR-`, Vitest para `CA-F`). "Nada criado" significa: nenhuma linha em `ex_expenses`, `ex_expenses_payers`, `ex_quotas` e nenhum arquivo em `comprovantes/`.

### 5.1 Backend

| # | Critério |
|---|---|
| CA-01 | Sem foto e sem descrição do pagamento, o cadastro é o de hoje: 201, todas as quotas com `paid=false` e `paid_at`, `paid_by`, `payment_proof_path`, `payment_note` nulos; nenhum aviso `expense_paid`. Os 35 testes de `ExpenseControllerStoreTest` passam sem alteração. |
| CA-02 | Descrição só com espaços, tabs, quebra de linha ou NBSP, sem foto, equivale a CA-01 (não é erro) e `payment_registered=false`. Caso de U+200B documentado com o resultado medido. |
| CA-03 | À Vista + descrição válida, criador = credor: 201; a quota nasce `paid=true`, `paid_by` = credor, `paid_at≈now()`, `payment_note` igual à descrição já aparada, `payment_proof_path` nulo, `born_paid=false`; `ex_expenses.description` não muda e é diferente de `payment_note`. |
| CA-04 | À Vista + foto válida: arquivo em `comprovantes/<grupo>/` no disco privado, `payment_proof_path` gravado, URL assinada devolvida em `summary`, `payment_note` nulo. |
| CA-05 | À Vista + foto + descrição: os dois gravados. |
| CA-06 | A descrição `"0"` conta como válida (paridade com `pay()`). |
| CA-07 | Parcelada de 3 parcelas + pagamento: as 3 quotas ficam pagas, com o mesmo `paid_at`, `paid_by` = credor, o mesmo `payment_proof_path` e o mesmo `payment_note`; nenhuma `born_paid`. `update` e `destroy` da despesa passam a ser recusados pelas regras existentes. |
| CA-08 | Parcelada de 12 parcelas com `quotas` do cliente fora de ordem e com `number` repetido: todas as quotas não `born_paid` ficam pagas. |
| CA-09 | Fixa + pagamento: só a quota inicial fica paga; a ocorrência do mês seguinte aparece `paid=false` no `summary`. |
| CA-10 | Criador ≠ credor + pagamento: 201; `paid_by` = credor, `user_creator_id` = criador; o credor recebe um aviso novo; criador = credor não gera esse aviso; quem não é membro do grupo recebe o 404/403 de hoje, sem upload gravado. |
| CA-11 | Criador ≠ credor sem dados de pagamento: 201 como hoje, sem aviso novo. |
| CA-12 | Ocorrência inicial em competência futura + pagamento: 422, nada criado. A mesma despesa sem pagamento: 201 como hoje. |
| CA-13 | Parcelada retroativa + pagamento: as parcelas fechadas seguem `born_paid`, sem arquivo nem nota e com o aviso `expenseBornPaid` de hoje; as demais ficam pagas, com arquivo e nota. |
| CA-14 | À Vista ou Fixa em competência fechada + pagamento: 422 com a mensagem de hoje, antes de qualquer escrita. |
| CA-15 | À Vista no ciclo em carência + pagamento: 201 e quota paga. |
| CA-16 | Foto inválida (não imagem; mais de 5120 KB) + descrição válida: 422, nada criado; a descrição não paga sozinha. |
| CA-17 | Falha simulada ao gravar o arquivo: erro, nada criado. |
| CA-18 | Falha simulada no banco depois do arquivo gravado: rollback, arquivo removido do disco, nada criado, resposta 500 sem `details`. |
| CA-19 | Exceção simulada em passo pós-confirmação (notificação, WhatsApp): resposta 2xx, despesa e pagamento persistidos. |
| CA-20 | Duas chamadas em sequência com a mesma chave e o mesmo corpo: uma despesa, um conjunto de quotas pagas, um arquivo; a 2ª devolve o mesmo `expense_id`. |
| CA-21 | Duas chamadas simultâneas com a mesma chave, inclusive com foto: uma despesa, **um só arquivo no disco**; ambas 2xx com o mesmo id; nenhuma 500. |
| CA-22 | Mesma chave, corpo diferente: 409 e nada alterado. Chave igual de outro usuário não colide. |
| CA-23 | Sem chave, o comportamento é o de hoje (cada chamada cria). |
| CA-24 | Nenhuma `SettlementConfirmation` é criada; os acertos seguem pendentes até o devedor confirmar (detalhe de valores em NR-01). |
| CA-25 | Avisos de um cadastro: `expensePaid` uma vez com o valor total da compra (Parcelada), `WhatsAppNotifier::expenseProofPaid` uma vez e só com foto, depois da resposta; `expenseCreated` como hoje. |
| CA-26 | O ciclo não é selado pelo cadastro, mesmo que tudo fique quitado (D8-A). |
| CA-27 | O mesmo cadastro enviado em JSON e em multipart produz o mesmo estado (despesa, pagadores, quotas). |
| CA-28 | **Arquivo compartilhado:** `unpay` ou troca de comprovante de **uma** parcela não apaga o arquivo enquanto outra quota o referencia, e a URL assinada das outras continua baixando; ao sair a última referência, o arquivo é removido. |
| CA-29 | **Desfazer a compra:** aceito para o credor enquanto nenhuma quota paga (não `born_paid`) estiver em ciclo `closed`, `closed_manually` ou selado; zera `paid`, `paid_at`, `paid_by`, comprovante e nota de todas; apaga o arquivo só depois do commit; libera `update` e `destroy`. Recusado (422) com qualquer quota em ciclo encerrado; 403 para quem não é o credor; atômico (falha no meio não muda nada). |
| CA-30 | `unpay` e `payment-proof` por parcela seguem funcionando numa quota paga no cadastro (regressão do fluxo existente). |
| CA-31 | `vendor/bin/pint` limpo e `phpunit` verde, com a migration aplicada em banco local. |
| CA-32 | Num reenvio idempotente não se grava arquivo, não se criam despesa nem quotas e não há notificação nem WhatsApp novos. |
| CA-33 | 409 para dados diferentes (inclui foto diferente com o resto igual) e para a chave de uma despesa apagada; em nenhum dos dois casos algo é criado ou alterado. |
| CA-34 | A impressão digital não muda com a ordem de `payers`, com `"10"` × `10.00` em `total_value`/`value_quota` nem com espaços nas pontas dos textos; muda com qualquer dado que altere o sentido (valor, data, credor, participante, foto). |
| CA-35 | A requisição que perde a corrida (violação de unicidade simulada só no `INSERT`) remove o próprio arquivo: nenhum órfão em `comprovantes/`. |

### 5.2 Não regressão de cálculos e relatórios

| # | Critério |
|---|---|
| NR-01 | Para cada forma de grupo da §3.7, `compute()` dá `balances`, `settlements`, `valuePerPerson` e `totals.total` **idênticos** entre a compra com pagamento e a sem; `totals.paid` e `totals.pending` diferem só pelo valor das quotas pagas do ciclo. |
| NR-02 | Varredura por ciclo da Parcelada (N = 3 com resto, N = 12): o valor de cada ciclo é o `value_quota` dele; a soma de `totals.paid` ao longo dos ciclos é o `total_value` ao centavo; no mês do cadastro `totals.paid` é só a quota daquele mês. |
| NR-03 | Mudar `paid_at` e `paid_by` das quotas não altera nenhuma saída de `summary`, `me/overview`, `closing-report`, `pending-cycles`, `gross-debts`, `cycles` nem dos relatórios. |
| NR-04 | `isFullySettled`, selagem, `focus()` e `pendingCycles` (`pending_expenses_count`, `awaiting_seal`) com a compra paga no cadastro dão a mesma saída do estado equivalente por factory; o ciclo só sela com todos os acertos confirmados. |
| NR-05 | `ClosingBreakdown` e `closing-report` idênticos aos da compra sem pagamento. |
| NR-06 | `cycleHistory` e snapshots: as mesmas chaves; só `paid`, `paymentNote` e `paymentProofUrl` diferem; snapshot antigo sem `paymentNote` continua lido. |
| NR-07 | `grossDebts` fixado conforme a D13 (hoje: a compra paga não aparece; ao desfazer a compra, reaparece). |
| NR-08 | `/api/me/overview`: `owe`, `receive`, `to_pay` e `to_receive` idênticos; `my_items[].paid`, `can_pay`, `can_unpay` e `pending_items_count` seguem `paid`; o teste de reconciliação `owe × settlements` do Painel continua verde. |
| NR-09 | Relatórios anuais (`GroupExpenseReportController`), `getMonthlyExpenses` e `indexByGroup` idênticos. |
| NR-10 | Os testes existentes de `backend/tests` passam sem edição, e os de caracterização são escritos antes da feature e ficam verdes no código atual. |

### 5.3 Frontend

| # | Critério |
|---|---|
| CA-F1 | Sem preencher a seção, o payload é o JSON de hoje e os testes atuais de `ExpenseForm` passam sem alteração (inclui "À Vista" com `quotas[0].paid`). |
| CA-F2 | Só descrição do pagamento: JSON com `descricao_pagamento` aparada, sem `multipart`. |
| CA-F3 | Com foto: `multipart` com `comprovante`, os campos do formulário, `payers[]` e `quotas[i][...]`. |
| CA-F4 | Descrição só com espaços: o campo não é enviado. |
| CA-F5 | Rótulos distintos ("Descrição" × "Descrição do pagamento (opcional)") e contador até 500. |
| CA-F6 | O texto da seção muda com o tipo e a data (À Vista, "N parcelas", Fixa) e traz o aviso fixo sobre o fechamento do ciclo; **não há tela de confirmação**. |
| CA-F7 | Pagador ≠ usuário logado: seção habilitada, com "registrado em nome de {Pagador}, que será avisado"; o pagamento é enviado. |
| CA-F8 | Falha (422, 413, 409, 500, rede, timeout): alerta na tela (nunca `window.alert`), campos e foto mantidos, botão reabilitado, `expense_created` não medido. |
| CA-F9 | Duplo clique em Salvar gera uma única requisição (trava síncrona, não só `saving`); no sucesso, navega para a lista. |
| CA-F10 | Foto fora do limite ou que não é imagem: aviso antes de enviar (UX); o servidor segue sendo a autoridade. |
| CA-F11 | Nenhum `capture` no `<input type="file">` (R16), e a seção é usável em 360 px. |
| CA-F12 | "Desfazer pagamento da compra" só aparece para o credor, com o servidor indicando a janela aberta; depois do desfazer, o chip volta a "Pendente" e a edição é liberada. |
| CA-F13 | Com fixture de compra paga: `CycleTotalsCards`, `PendingCyclesList`, filtro de status e chips mostram os valores esperados (NR-12 de apresentação). |
| CA-F14 | `tsc --noEmit` e Vitest verdes. |
| CA-F15 | A chave é a mesma em reenvios depois de falha de rede ou timeout, mesmo depois de o usuário editar campos; só muda num formulário novo. |
| CA-F16 | A requisição tem timeout explícito; ao estourar, o alerta manda conferir a lista antes de tentar de novo e diz que reenviar não duplica; campos e foto ficam mantidos. |
| CA-F17 | O corpo enviado inclui `client_request_id`, tanto em JSON quanto em multipart. |

### 5.4 Documentação

| # | Critério |
|---|---|
| CA-D1 | `MANUAL.md` e `MANUAL.pdf` descrevem o pagamento no cadastro, o que é marcado como pago em cada tipo, o aviso ao credor e a janela de correção. |
| CA-D2 | `implementation.md` registra cada comando e resultado, inclusive a medição de CA-02 (U+200B), o limite de upload de produção (RK8) e a execução da suíte de equivalência antes e depois da feature. |

## 6. Fora de escopo desta feature

- **Pagamento individual por participante (item 075)** e a **quitação antecipada (076)**. A feature paga pelo credor, não a parte de cada devedor.
- **Quitar acertos entre membros**: nada de `SettlementConfirmation`, nada de mudar `compute()` ou a selagem por acerto (R14).
- **Mudar qualquer cálculo existente**, inclusive alinhar `grossDebts` a `settlements` (D13, se a resposta for B vira feature própria).
- **Lembrete de revisão na carência** (D12): feature irmã, se aprovada.
- **Aviso ou bloqueio de possível duplicidade por chave natural** (D14) e **persistir a chave em `sessionStorage`** para sobreviver a um recarregamento: ficam fora; a v1.2 declara os limites (F7, F15).
- **Escolher a data do pagamento**: `paid_at` é o momento do cadastro.
- **Pagamento ao editar** a despesa (`ExpenseView`): a edição continua como está.
- **Endurecer o upload** (SVG, `nosniff`, extensão, item 097) e a **expiração do link** em ciclo selado (096).
- **Mudar o contrato de `pay()`**, `unpay()` (além do parâmetro aditivo do desfazer em lote) ou `payment-proof`.
- **Descrição do pagamento em notificações** (in-app e WhatsApp): templates da Meta não mudam.
- **App Flutter** (`app/` ainda não existe): ele consumirá o contrato aditivo desta feature.
- **Métrica nova**: `expense_created` não muda; não há evento "criada e paga".

### Itens de backlog gerados por esta feature

Criados em 2026-10-27, depois de o usuário fechar D12–D14:

1. **110** — Lembrete de revisão no início da carência (D12), no padrão preguiçoso de `cycleSettled`.
2. **111** — `grossDebts` ignora quota paga e diverge de `settlements` (D13).
3. **112** — Aviso de possível duplicidade por chave natural e chave persistida em `sessionStorage` (D14, F15).

### Candidatos a backlog (não criados)

1. **`store()` e ciclo já selado** (RK6): ler o código sugere que uma despesa nova num ciclo selado, ainda aberto, não desfaz a selagem. Precisa de um teste que reproduza antes de virar item; entra como task de investigação desta feature.
2. **Idempotência nas demais ações financeiras** (`pay`, `confirmSettlement`), se o uso da chave se mostrar útil.
