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
| TASK-003 | Executada | 2026-09-05 | Isac (usuário) | Ver detalhamento abaixo | Executada em produção via phpMyAdmin; conferência no app pendente |

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

Achado não-bloqueante registrado no backlog em vez de virar escopo desta task:
`docs/backlog/expense-view-tipo-e-pagadores.md` (ID 039) — a página `ExpenseView` tem os mesmos
dois problemas (rótulo e pagadores), mas não tem noção de competência, então "qual parcela" ali
exige uma decisão de produto que o usuário não tomou.

**Verificação em browser não concluída**: `frontend-web` (3000) e `backend-api` (8000) já estavam
no ar (servidores do próprio usuário), o Browser pane abriu em `http://localhost:3000`, mas a
aplicação exige login e a IA não digita credenciais. A verificação visual do modal fica pendente
de o usuário autenticar a aba. A cobertura automatizada dos três cenários (parcelada, à vista,
payload sem os campos novos) está nos testes de `ExpenseManager.test.tsx`.

### Checklist de integração na branch da feature (04-implementation.md §1 item 5)

| Comando | Resultado |
|---|---|
| `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerSummaryTest.php` | PASS, 2 files |
| `php artisan test` | 330 passed (1065 assertions) |
| `npx tsc --noEmit` | sem erro |
| `npx vitest run` | 37 arquivos, 239 passed |

`./vendor/bin/pint --test` sem escopo continua apontando débito de estilo em migrations de
2025 — pré-existente, fora do diff desta feature, não bloqueante (mesma situação registrada em
`20260904-parcela-retroativa-contabilizacao`).

`pr-readiness-checker`: **verde**, com os números reproduzidos de forma independente (330 passed /
1065 assertions no backend, 239 passed no vitest, `tsc` limpo, pint escopado PASS) e CI do GitHub
verde no PR. Confirmou que não há migration no diff (`git diff origin/dev...branch --
backend/database/migrations/` vazio), que não há segredo novo (3 hits do varredor, todos falsos
positivos: dois `withToken($this->tokenFor(...))` de teste e um comentário do `.sql`) e que os 8
arquivos que o `pint --test` sem escopo reprova estão todos fora do diff. Única lacuna de critério
de aceite: a verificação visual do modal (screenshot), pendente de login — os 3 cenários já estão
cobertos por teste automatizado, então é confirmação, não descoberta.

`security-reviewer`: **nenhum achado**. Confirmou que `summary()` mantém
`authorizeGroupMembership()` antes de qualquer leitura, que as três origens de entry são escopadas
por `group_id` (logo nenhum campo novo vaza dado de outro grupo) e que o diff é 100% leitura (sem
mass assignment novo). Levantou uma imprecisão de comentário — `installmentNumber` vem `1` numa
`IN_CASH` (a despesa tem uma Quota só), não `null` —, corrigida no comentário de
`useGroupCycle.ts` para dizer exatamente isso em vez de sugerir `null`.

### TASK-003 — detalhamento (dados de produção)

Script: `fix-prod-8658-8659-antecipa-mes.sql` (nesta pasta). **Ainda não executado.**

Sequência: diagnóstico (passo 0) → backup (1) → `date_expected -1 mês` nas 11 quotas e
`date_payment -1 mês` nas 2 despesas (2) → marcar as parcelas que passaram a cair em julho e
agosto como `paid = 1` / `born_paid = 1` / `paid_by = 5573` (3) → desselar mai–ago se o passo 0
mostrar selados (4) → verificação por API (5) → rollback documentado (6).

Pontos que o script trava de propósito:
- o `UPDATE` do passo 3 exige `paid = 0`, então reexecução acidental é no-op;
- `born_paid = 1` (e não só `paid = 1`) é obrigatório — marcar só `paid` reintroduziria o
  settlement fantasma corrigido em `20260904-parcela-retroativa-contabilizacao` e, em agosto,
  manteria a cobrança que o usuário quer justamente remover;
- o passo 0 lista o estado esperado e manda **parar** se o que estiver no banco divergir.

**Revisão de escopo em 2026-09-05** (antes de qualquer execução): a versão inicial deste script
marcava só julho como quitado e mantinha agosto como dívida real. Perguntei explicitamente o que
"agosto pago" significava, porque as duas leituras têm consequência financeira oposta, e o usuário
escolheu **quitado, ninguém deve nada** (`born_paid = 1`). Efeito: R$ 696,15 (5 devedores ×
R$ 139,23) saem do acerto de agosto, e a primeira pendência real das duas despesas passa a ser
setembro. Reverte deliberadamente `20260904-parcela-retroativa-contabilizacao/specify.md` §2.5.

| Passo | Ação | Resultado confirmado pelo usuário |
|---|---|---|
| 0 | Diagnóstico (quotas, despesas, snapshots mai–set) | **Confere com o esperado** — ver abaixo |
| 1 | Backup em `_bkp_ex_quotas_20260904b` e `_bkp_ex_expenses_20260904b` | Criadas |
| 2 | Antecipar `date_expected` (11 quotas) e `date_payment` (2 despesas) em 1 mês | Aplicado |
| 3 | Parcelas de julho e agosto → `paid=1`, `born_paid=1`, `paid_by=5573` | Aplicado nas 4 quotas (6773, 6774, 6779, 6780) |
| 4 | Desselar mai–ago (só os que o passo 0 mostrar selados) | **Dispensado** — nada selado (ver abaixo) |
| 5 | Conferência no app | Pendente |

O backup de `ex_group_cycle_snapshots` não foi criado porque o passo 4 foi dispensado — nenhuma
linha daquela tabela é tocada por esta execução.

#### Resultado do passo 0 (executado pelo usuário em 2026-09-05, via phpMyAdmin)

**Quotas** — 11 linhas, exatamente o estado previsto em `specify.md` §2.5:

| Despesa | ids | `number` | `date_expected` | Estado |
|---|---|---|---|---|
| 8658 | 6771, 6772 | 1, 2 | 2026-06-04, 2026-07-04 | `paid=1`, `born_paid=1`, `paid_by=5573`, `paid_at` 2026-09-04 11:54 |
| 8658 | 6773–6776 | 3–6 | 2026-08-04 … 2026-11-04 | `paid=0`, `born_paid=0` |
| 8659 | 6777, 6778 | 1, 2 | 2026-06-04, 2026-07-04 | `paid=1`, `born_paid=1`, `paid_by=5573`, `paid_at` 2026-09-04 11:56 |
| 8659 | 6779–6781 | 3–5 | 2026-08-04 … 2026-10-04 | `paid=0`, `born_paid=0` |

`value_quota` 292,40 (8658) e 543,00 (8659); `payment_proof_path` nulo em todas. **Todas as datas
caem no dia 04** — nenhuma em 29/30/31, então `DATE_SUB(..., INTERVAL 1 MONTH)` não vai ajustar dia
nenhum para o fim do mês anterior. Era o risco que o passo 0 existia para descartar.

**Despesas** — 8658 (Adestrador) e 8659 (Construção parede escritório/demolição stiep), ambas
`IN_INSTALLMENTS`, `installments` 6 e 5, `total_value` 1.754,40 e 2.715,00, `date_payment`
2026-06-04, `user_payer_id` 5573, `group_id` 3878, `deleted` 0.

**Snapshots** — só **dois** existem no intervalo (`2026-07-01` e `2026-08-01`), ambos com
`settled_at`, `closed_manually_at` e `reopened_at` **nulos**. Não há snapshot de maio, junho nem
setembro. Ou seja: **nenhuma competência está selada**, todas recalculam ao vivo, e o passo 4 do
script não tem o que desselar — dispensado (o `UPDATE` já é no-op pelo filtro
`settled_at IS NOT NULL`). Divergiu da previsão do `plan.md` §3, que assumia julho possivelmente
selado; a divergência elimina trabalho em vez de criar.

Quotas que o passo 3 deve marcar, depois do deslocamento: **6773 e 6774** (8658) e **6779 e 6780**
(8659) — as que passam a cair em 2026-07-04 e 2026-08-04.

#### Estado final confirmado (após os passos 1–3, 2026-09-05)

`SELECT expense_id, number, date_expected, paid, born_paid, value_quota` nas 11 quotas devolveu
exatamente o alvo de `specify.md` §3.5:

| Despesa | `number` | `date_expected` | `paid` / `born_paid` |
|---|---|---|---|
| 8658 | 1–4 | 2026-05-04, 06-04, 07-04, 08-04 | 1 / 1 |
| 8658 | 5, 6 | 2026-09-04, 2026-10-04 | 0 / 0 |
| 8659 | 1–4 | 2026-05-04, 06-04, 07-04, 08-04 | 1 / 1 |
| 8659 | 5 | 2026-09-04 | 0 / 0 |

`value_quota` inalterado (292,40 e 543,00), quantidade de parcelas inalterada (6 e 5), totais
inalterados (R$ 1.754,40 e R$ 2.715,00) — o deslocamento não mexeu em dinheiro, só em competência.

Quitado retroativamente: R$ 1.169,60 (8658, 4 parcelas) + R$ 2.172,00 (8659, 4 parcelas).
Pendência real restante: setembro com R$ 292,40 + R$ 543,00 = R$ 835,40 (R$ 139,23 por devedor) e
outubro com R$ 292,40 só do 8658. Novembro deixou de existir para o 8658; outubro deixou de existir
para o 8659.

## 3. PRs

| PR | Conteúdo | Estado |
|---|---|---|
| [#153](https://github.com/isacaguiar/expense/pull/153) | TASK-001 (backend) + TASK-002 (frontend) + scaffold SDD + `.sql` inicial da TASK-003 | Mergeado em `dev` em 2026-09-05 |
| [#155](https://github.com/isacaguiar/expense/pull/155) | Só docs: revisão da TASK-003 — agosto passa a `born_paid` (specify §3.5.1) | Aberto — **merge é gate humano** |

O #155 existe porque o #153 foi mergeado (e a branch remota apagada) enquanto a decisão sobre
agosto ainda estava sendo tomada; o push seguinte recriou a branch com o commit de docs sozinho.
Não há código nele.

TASK-003 não entra em nenhum merge como comportamento — o `.sql` vai junto só como documento; a
execução em produção é gate humano separado e **ainda não foi feita**.

Esta pasta **permanece em `docs/feature/`** (não vai para `concluidas/<AAAAMM>/` ainda, apesar do
#153 já estar em `dev`): a feature só termina quando a TASK-003 for executada e verificada em
produção. Mover agora esconderia um trabalho em aberto na pasta de concluídos.
