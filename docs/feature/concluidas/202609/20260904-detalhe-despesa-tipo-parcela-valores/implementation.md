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
| TASK-004 | Executada | 2026-09-06 | Isac (usuário) executou; IA redigiu e conferiu | Ver detalhamento abaixo | Reverte o `born_paid` de agosto decidido em 2026-09-05 |
| TASK-005 | Executada | 2026-09-06 | Isac (usuário) executou; IA redigiu e conferiu | Ver detalhamento abaixo | Agosto selado; setembro intocado |
| TASK-006 | Executada | 2026-09-06 | Isac (usuário) executou; IA redigiu e conferiu | Ver detalhamento abaixo | 4 tabelas legadas + as de trabalho removidas |

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

> **Atualização de 2026-09-21:** esse item deixou de estar aberto. Foi promovido para a TASK-276 e
> entregue por `docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/` (PRs #161 e
> #162) — `frontend/src/pages/ExpenseView.tsx:470` e `:527` citam `detailTypeLabel` e
> `renderDetailPayers` desta feature como referência.

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

Script: `fix-prod-8658-8659-antecipa-mes.sql` (nesta pasta). **Executado em 2026-09-05** — ver "Resultado da execução" abaixo. (O texto "ainda não executado" ficou aqui desatualizado até 2026-09-21.)

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

### TASK-004 — detalhamento (recolocar as parcelas no acerto de agosto)

Script: `fix-prod-3878-agosto-cobrar-parcelas.sql` (nesta pasta). **Executado e conferido em 2026-09-06** — ver "Resultado da execução" abaixo.

#### Por que existe: a decisão de 2026-09-05 estava errada

A TASK-003 gravou `born_paid = 1` nas parcelas de agosto de 8658/8659, a pedido do usuário
("agosto entra como quitado, ninguém deve nada"). Em 2026-09-06 o usuário reviu: *"se o
valor entrou no mês antes do fechamento deveria ser contabilizado"*. Os R$ 696,15 que a
TASK-003 tirou do acerto de agosto voltam.

Na primeira rodada desta sessão eu tratei o relato como premissa equivocada — com
`closing_day = NULL` a competência é o mês calendário e o "fecha em 05/09" que o app exibe é
a carência de `BillingCycle::GRACE_DAYS`, não a fronteira. Isso está correto sobre *como o
sistema funciona*, mas era resposta para outra pergunta: o usuário não estava reclamando de
onde a parcela de 04/09 cai, e sim de que **a parcela de 04/08, que já está dentro de
agosto, não cobra ninguém**. Só ficou claro ao abrir o app em produção.

#### O que foi conferido em produção (2026-09-06, via browser, grupo 3878)

Competência **01 de ago – 31 de ago**, "Ciclo fechado", aviso "ainda falta acertar:
ngaguiar, mateus.davi.10, Natália, gabriel":

| Despesa | Modal | Data | Valor | Por pagador | Status |
|---|---|---|---|---|---|
| Adestrador (8658) | `Parcelada 4/6` | 04/08/2026 | R$ 292,40 | R$ 48,73 | Paga |
| Construção parede escritório/demolição stiep (8659) | — | 04/08/2026 | R$ 543,00 | R$ 90,50 | Paga |

Pagadores idênticos nas duas, 6 pessoas: Isac, naumel67 (credor), ngaguiar, mateus.davi.10,
Natália, gabriel. Na aba "À pagar" de agosto **não há nenhuma linha referente às duas**.

#### Causa

`computeCycleSummary()` pula toda entry com `bornPaid` ao montar `balances`/`$owed`/
`settlements` (`ExpenseController.php:1199`). Não é o `paid`: a Placa Solar do mesmo mês
também está "Paga" e continua gerando acerto. A correção é tirar `born_paid` das duas
quotas de 04/08 — `paid = 1` e `paid_by = 5573` ficam como estão, porque naumel67 pagou
mesmo as duas contas.

#### Verificação (antes de qualquer execução em produção)

Teste temporário `backend/tests/Feature/TmpAgostoCobrarParcelasTest.php` (criado, executado
e removido — não entra no commit), reproduzindo o caso real: parcela de R$ 292,40 dividida
entre 6, credor + 5 devedores, competência de agosto já fechada em 06/09.

| Comando | Resultado |
|---|---|
| `php artisan test --filter=TmpAgostoCobrarParcelasTest` | 3 passed (34 assertions) |

O que ficou provado, nessa ordem: (1) com `born_paid = 1` o ciclo devolve `settlements: []`
e todos os saldos zerados, apesar de `totals.paid = 292,40` — é o estado de hoje em
produção; (2) tirando só o `born_paid`, aparecem 5 acertos de R$ 48,73 para o credor, os
saldos viram +243,67/−48,73 e **os totais não mudam**; (3) `paid = 1` com `born_paid = 0`
gera acerto normalmente — o `paid` sozinho não suprime nada.

#### Efeito esperado em produção

Cada um dos 5 devedores passa a dever mais R$ 139,23 a naumel67 (R$ 48,73 + R$ 90,50),
R$ 696,15 no total. Os cards do topo não mudam. `ngaguiar → naumel67 R$ 139,23` é linha
nova — hoje esse par não tem acerto em agosto. Tabela completa de valores esperados no
passo 4 do script.

Efeito colateral conhecido, não novo: `grossDebts()` filtra por `paid`
(`ExpenseController.php:814`), então as duas seguem fora do painel de dívidas brutas
enquanto aparecem no acerto — divergência já registrada como pré-existente em
`docs/feature/concluidas/202609/20260904-parcela-retroativa-contabilizacao/specify.md` §3.


#### Resultado da execução (2026-09-06, conferido no app pela IA)

Usuário rodou o script em produção. Conferência feita lendo a competência 01/08–31/08 do
grupo 3878 no browser, aba "À pagar" — os 5 pares bateram **exatamente**, ao centavo:

| Devedor → Nádia (naumel67, 5573) | Antes | Previsto | Real |
|---|---|---|---|
| Gabriel | R$ 2.658,66 | R$ 2.797,89 | **R$ 2.797,89** |
| Isac | R$ 1.976,83 | R$ 2.116,06 | **R$ 2.116,06** |
| Mateus | R$ 1.016,12 | R$ 1.155,35 | **R$ 1.155,35** |
| Natália | R$ 16,12 | R$ 155,35 | **R$ 155,35** |
| Norma (ngaguiar) | *(sem linha)* | R$ 139,23 | **R$ 139,23** (linha nova) |

As demais 8 linhas do acerto ficaram intactas (Mateus/Gabriel → Isac R$ 727,82, Natália →
Isac R$ 445,99, as cinco de R$ 75,00 → Ian, Norma → Isac R$ 400,00), e os cards do topo
seguem em Total R$ 14.004,87 / Pago R$ 4.546,67 / A pagar R$ 9.458,20 — inalterados, como
previsto, porque `paid` não foi tocado.

**Correção de uma imprecisão minha**: a tabela de *saldos* que eu havia previsto errou de
1 a 2 centavos (previ Nádia 6.288,86 e o real é 6.288,88; Isac 110,57 contra 110,56 real;
Mateus/Natália/Gabriel um centavo abaixo cada). Motivo: eu somei R$ 139,23 já arredondado
aos saldos exibidos, enquanto o backend acumula sem arredondar (292,40/6 = 48,7333… mais
543,00/6 = 90,50 = 139,2333…) e arredonda uma vez só no fim — `computeCycleSummary()`
arredonda o saldo depois do laço e o settlement só no `net`. O app está certo; a previsão
é que era aproximada. Os *settlements*, que são o dinheiro que cada um deve, bateram sem
divergência.

Observação lateral, não relacionada a esta task: os nomes de exibição dos membros mudaram
entre a leitura da manhã e esta (naumel67 → Nádia, ngaguiar → Norma, mateus.davi.10 →
Mateus, ian.gaguiar → Ian, juliagaguiar → Júlia). Alteração de cadastro feita fora daqui.

### TASK-005 — detalhamento (fechar agosto/2026 em produção)

Script: `fix-prod-3878-fechar-agosto.sql` (nesta pasta). **Executado e conferido em 2026-09-06** — ver "Resultado da execução" abaixo.

Origem: o usuário relatou em 2026-09-06 que "as despesas do dia 04/09 (Adestrador e
Construção) não foram contabilizadas para o mês de agosto, apesar do fechamento ser dia 5",
e pediu (a) o recálculo dos valores a pagar e (b) o fechamento de todos os pagamentos de
agosto, dos dois lados (credores e pagadores).

#### Diagnóstico da premissa

Não é defeito de contabilização — são dois conceitos diferentes de `BillingCycle`:

- **Fronteira do ciclo** (`closing_day`): define a QUAL competência uma data pertence.
  `closing_day = NULL` (que é o que `20260904-parcela-retroativa-contabilizacao/specify.md`
  §1 registrou para o grupo 3878) significa mês calendário — agosto é 01/08–31/08, e
  04/09 é setembro por definição.
- **Carência** (`BillingCycle::GRACE_DAYS = 5`): agosto continua `open`/editável por mais
  5 dias depois da fronteira, virando `closed` só em 05/09 (`closesAt()`). É esse número
  que o app mostra no `CycleClosingAlert` — "Este ciclo fecha em 05/09" — e é a origem
  provável do "o fechamento é dia 5". A carência dá prazo para registrar e acertar; ela
  não puxa uma despesa de setembro para agosto.

O passo 0 do script confirma qual dos dois casos vale lendo `ex_groups.closing_day`: se
vier `5` em vez de `NULL`, a janela de agosto passa a ser 06/08–05/09 e as parcelas de
04/09 realmente pertencem a ela — por isso a janela é parâmetro (`@cycle_start`/
`@cycle_end`) e não está chumbada no script.

#### O que o script faz

Parte 1 (passos 0–3, somente leitura) reescreve `computeCycleSummary()` em SQL: monta as
entradas da competência pelas três origens de `collectCycleEntries()` (direta, parcela,
fixa projetada), devolve `totals` e calcula os pares devedor→credor com o mesmo netting.
É o "recalcule os valores a serem pagos" — não há valor chumbado no script.

Parte 2 (passos 4–10, escrita) fecha os dois lados que `cycleIsFullySettled()` exige:
`ex_quotas.paid = 1` com `paid_by` = credor (lado do credor) e uma linha em
`ex_settlement_confirmations` por par em aberto (lado do pagador). O passo 5 materializa
a Quota das despesas FIXAS projetadas, que sem linha em `ex_quotas` contam como não pagas
e travariam a selagem. A selagem em si fica para a aplicação (passo 9): `settled_at`
escrito à mão sem as fotos JSON serviria uma competência vazia para todos.

Decisões registradas no script:
- **não** grava `born_paid` no passo 6 — `born_paid` tiraria as despesas de
  `balances`/`settlements` e apagaria o registro de quem devia a quem, o oposto de "fechar
  o pagamento" (é o mecanismo da feature `20260904-parcela-retroativa-contabilizacao`,
  usado lá para o caso oposto);
- `proof_path = ''` nas confirmações (coluna é `NOT NULL`): o accessor `proof_url` trata
  string vazia como "sem comprovante" e o app não mostra o chip. `INSERT IGNORE` preserva
  as confirmações reais que já existam;
- passo 7 (quitar as parcelas de 04/09) fica **comentado** — depende da decisão pendente.

#### Verificação do script (antes de qualquer execução em produção)

Teste temporário `backend/tests/Feature/TmpFecharAgostoSqlTest.php` (criado, executado e
removido — não entra no commit), com `DatabaseTransactions` e `TEMPORARY TABLE` no lugar
das tabelas de trabalho, sobre um cenário com as três origens de entry, uma parcela
`born_paid`, um par com dívida nos dois sentidos (para exercitar o netting), uma quota já
`paid` (para provar que `paid` não filtra settlement) e uma despesa de setembro (para
provar o recorte da janela):

| Comando | Resultado |
|---|---|
| `php artisan test --filter=TmpFecharAgostoSqlTest` (1ª execução) | 1 failed — helper do teste, não o SQL (`Cannot use object of type stdClass as array`) |
| `php artisan test --filter=TmpFecharAgostoSqlTest` (após corrigir o helper) | 1 passed (11 assertions) — `totals` e `settlements` do SQL idênticos aos do `computeCycleSummary` |
| `php artisan test --filter=TmpFecharAgostoSqlTest` (com o teste dos passos de escrita) | 1 failed, 1 passed — assert errado meu: eu esperava 500 de pendência em setembro e vieram 560, porque a despesa FIXA também projeta em setembro. Comportamento correto do sistema |
| `php artisan test --filter=TmpFecharAgostoSqlTest` (assert corrigido para 560) | 2 passed (19 assertions) — depois dos passos 5/6/8, agosto fica `cycle.settled = true`, `totals.pending = 0`, `status = closed`, e setembro permanece intocado |

Achado registrado durante a verificação: o MySQL não permite abrir a mesma `TEMPORARY
TABLE` mais de uma vez na mesma query, e o `SELECT` de settlements lê `_fech_owed` três
vezes. Em produção as tabelas de trabalho são reais (`CREATE TABLE`, não `TEMPORARY`) e a
restrição não se aplica — o teste é que precisou de duas cópias auxiliares.

#### Segunda verificação (2026-09-06), agora lendo o .sql do disco

Antes de liberar a execução, refiz a verificação com um teste que **lê o arquivo
`fix-prod-3878-fechar-agosto.sql` do disco** e executa os comandos dele, em vez de uma cópia
colada — o arquivo tinha sido editado três vezes desde a primeira verificação e uma cópia
podia ter divergido. Cenário no formato do grupo 3878 depois da TASK-004: parcela paga que
ainda cobra (`born_paid = 0`), parcela retroativa `born_paid`, fixa projetada sem quota,
despesa em aberto e uma parcela de 04/09 fora da janela.

Isso encontrou **dois defeitos reais no script**, os dois corrigidos:

1. **Backup do passo 4 virava no-op silencioso.** O script usava
   `_bkp_ex_quotas_20260906`, o mesmo nome que o `fix-prod-3878-agosto-cobrar-parcelas.sql`
   já havia criado (só com as quotas de 8658/8659). Com `CREATE TABLE IF NOT EXISTS`, o
   backup do fechamento não seria criado, e as quotas que o passo 6 altera ficariam sem
   cópia para rollback. Renomeado para `_bkp_ex_quotas_fechamento_20260906`.
2. **Despesas FIXAS não eram marcadas como pagas.** O passo 6 fazia
   `JOIN _fech_entries d ON d.quota_id = q.id`, e as quotas que o passo 5 acabara de criar
   não têm `quota_id` em `_fech_entries` — o script dependia de um comentário mandando
   reexecutar o passo 1 à mão entre os dois. Quem roda o arquivo de cima a baixo (o normal
   no phpMyAdmin) deixaria a fixa em aberto e a competência nunca selaria. O `UPDATE` passa
   a filtrar por despesa da competência + vencimento dentro da janela, sem depender de
   `_fech_entries` ser reconstruída; o `SELECT` de conferência do passo 5 também passou a
   ler o banco direto em vez da tabela de trabalho desatualizada.

| Comando | Resultado |
|---|---|
| `php artisan test --filter=TmpFecharAgostoScriptTest` (1ª execução) | 1 failed — R$ 900 da fixa sobrando em `totals.pending`: o defeito 2 acima |
| `php artisan test --filter=TmpFecharAgostoScriptTest` (após corrigir os passos 5 e 6) | 1 failed — assert meu errado: previ R$ 292,40 de pendência em setembro e vieram R$ 1.192,40, porque a fixa também projeta em setembro. Comportamento correto do sistema |
| `php artisan test --filter=TmpFecharAgostoScriptTest` (assert corrigido) | 1 passed (22 assertions) |
| `php artisan test` (suíte completa, após remover o teste temporário) | 330 passed (1065 assertions) |

O teste verde prova, sobre o arquivo que o usuário vai rodar: os `settlements` do SQL são
idênticos aos do `computeCycleSummary`; depois dos passos 5/6/8 agosto fica
`cycle.settled = true` com `totals.pending = 0`, `totals.total` inalterado e todo acerto com
`confirmedAt` preenchido; e a parcela de 04/09 continua `paid = 0`, `born_paid = 0`,
`paid_by = NULL` — o fechamento de agosto não encosta em setembro.

#### Execução interrompida na 1ª tentativa (2026-09-06) — armadilha das variáveis de sessão

O usuário rodou o script e o app não mudou nada. Diagnóstico feito lendo a estrutura do
banco no phpMyAdmin já aberto: `_fech_entries`, `_fech_owed` e `_fech_settlements` existiam,
mas **com 0 linhas**; os três `_bkp_*_20260906` existiam com conteúdo (30 quotas, 2
confirmações, 1 snapshot).

Causa: variáveis `@` vivem na **conexão**, e o phpMyAdmin abre conexão nova a cada envio. O
bloco do passo 1 foi enviado sem os `SET` do topo do arquivo, então `@group_id` /
`@cycle_start` / `@cycle_end` vieram `NULL`, nenhum `WHERE` casou e `_fech_entries` nasceu
vazia. Como todos os passos de escrita são dirigidos por essa tabela, os passos 5, 6 e 8
viraram no-op — **nada foi gravado em dado de aplicação**, e não houve rollback a fazer. Os
backups do passo 4 rodaram corretamente porque aquele bloco foi enviado com os `SET`.

Nada disso deu erro: é falha silenciosa, o pior formato. Correções no script:

1. Aviso no topo explicando que variáveis `@` são por conexão.
2. Os três `SET` **repetidos dentro de cada passo que os usa** (1, 3, 5, 6 e 8), para que
   qualquer bloco enviado isoladamente funcione.
3. Guarda obrigatória logo após o passo 1 — `SELECT COUNT(*) AS entradas FROM _fech_entries;`
   — com a instrução de parar se vier 0.

Re-verificação após essas edições, com o mesmo teste que lê o `.sql` do disco, agora também
conferindo que os 6 blocos de `SET` estão presentes: `php artisan test
--filter=TmpFecharAgostoScriptTest` → **1 passed (21 assertions)**.

O usuário pediu que eu executasse direto no phpMyAdmin dele; a ação foi **bloqueada pelo
classificador de permissões** do Claude Code. A execução seguiu com o usuário, bloco a bloco.

#### Decisões do usuário (2026-09-06)

1. **`closing_day` do grupo não muda.** O usuário respondeu que "o problema só ocorreu na
   divisão do mês de agosto, os demais meses estão ok" — descompasso pontual, não uma
   regra de negócio nova. Configurar `closing_day = 5` re-encaixaria todo o histórico do
   grupo e órfãria os snapshots e as `ex_settlement_confirmations` já gravados com as datas
   antigas (as duas tabelas são chaveadas por `cycle_start`), então fica fora.
2. **As parcelas de 04/09 continuam devidas em setembro** — R$ 835,40 no total, R$ 139,23
   por devedor, para acertar no fechamento do próximo mês. O passo 7 do script é o registro
   dessa decisão, sem SQL para rodar.

Efeito prático: este script fecha só a janela 01/08–31/08. Depois de executado, a primeira
pendência do grupo passa a ser setembro, com as duas parcelas em aberto.

#### Resultado da execução (2026-09-06, conferido no app pela IA)

2ª tentativa, com os `SET` repetidos em cada bloco. Conferência do usuário durante a
execução, antes de qualquer escrita:

| Conferência | Previsto | Obtido |
|---|---|---|
| `SELECT COUNT(*) FROM _fech_entries` (guarda do passo 1) | 10 | **10** |
| Pares de acerto (passo 3) | 14 | **14** |
| Soma dos acertos (passo 3) | R$ 9.040,51 | **R$ 9.040,51** |
| Acertos já confirmados (passo 3) | 2 | **2** |

Estado final lido no app pela IA, competência 01/08–31/08:

1. **As 10 despesas do ciclo aparecem como "Paga"** — Aluguel, Água, Pão e Aluguel Mateus
   (R$ 9.458,20) estavam "Pendente" antes.
2. O aviso "O ciclo fechou e ainda falta acertar: Norma, Mateus, Natália, Gabriel"
   **desapareceu**.
3. A Home do grupo passou a abrir em **setembro** ("01 De Set. – 30 De Set., Ciclo em
   andamento") — `focus-cycle` deixou de apontar para agosto.
4. **Prova da selagem**: agosto aparece em Relatórios → "Histórico de ciclos fechados —
   01 de ago. – 31 de ago., Total: R$ 14.004,87". O endpoint `history()` filtra
   `whereNotNull('settled_at')`, então só ciclo selado entra ali.
5. O badge de notificações saiu de 0 para 1 — `Notifier::cycleSettled` disparou, como
   previsto.
6. Os saldos de agosto seguem registrados (Nádia R$ 6.288,88 a receber, Gabriel R$ 3.600,71
   a pagar etc.): a selagem congela o histórico de quem devia a quem, não zera.
7. **Setembro intocado**: Adestrador (R$ 292,40) e Construção (R$ 543,00) aparecem como
   "Pendente" em 04 de set., que é a decisão registrada acima.

Como previsto no plano, os acertos confirmados por SQL **não** exibem o chip "Comprovante
enviado" — `proof_path = ''` faz o accessor `proof_url` devolver `null`. Confirmado na aba
"À pagar": nenhum chip, nenhum acerto em aberto.


### TASK-006 — detalhamento (limpeza de tabelas em produção)

Script: `cleanup-prod-tabelas-auxiliares.sql` (nesta pasta). **Executado em 2026-09-06.**

Origem: ao pedir a limpeza das tabelas de trabalho, o usuário observou que "algumas tabelas
não possuem registros e creio que podem ser apagadas".

#### Duas correções na premissa

1. **`TABLE_ROWS` não serve para decidir.** Em InnoDB aquele número é uma estimativa
   amostrada — é a coluna que a tela de estrutura do phpMyAdmin mostra, e pode vir 0 para
   tabela com linhas. O próprio inventário do usuário provou: exibiu
   `_bkp_ex_group_cycle_snapshots_20260906` com 0, sendo que eu tinha visto 1 nela horas
   antes. Só `COUNT(*)` decide — virou o passo 1 do script.
2. **Tabela vazia não é tabela inútil.** Contraexemplo concreto: `ex_participations` está
   vazia e fora do fluxo de dinheiro, mas `GroupController::destroy()` (`:118`) chama
   `$group->participations()->delete()` — apagá-la quebraria a exclusão de grupo. O mesmo
   vale para `ex_failed_jobs`, `ex_password_reset_tokens`, `ex_personal_access_tokens`
   (infra do Laravel), `ex_notifications` e `migrations`. A lista do que nunca sai, com o
   motivo de cada uma, ficou no passo 6 do script.

#### O que de fato era descartável

Quatro tabelas **no singular** — `ex_expense`, `ex_expense_payers`, `ex_group`, `ex_user` —
ao lado das corretas no plural, todas criadas em `2026-08-29 08:48:35` (o instante do import
original), nenhuma criada por migration e nenhuma referenciada em `app/`, `database/`,
`routes/` ou `config/`. Mais as três `_fech_*` de trabalho, que o passo 9 do script de
fechamento já mandava dropar.

#### Sobre as foreign keys

O usuário levantou que seria preciso apagar as FKs antes do `DROP`. Não é: `DROP TABLE` já
remove as constraints da própria tabela. O que atrapalha é (a) a **ordem**, quando uma
legada referencia outra — resolvido com `FOREIGN_KEY_CHECKS = 0` no escopo da sessão; e (b)
uma tabela **viva** apontando para uma legada, caso em que a resposta certa não é remover a
FK, é parar, porque a tabela não seria órfã. O passo 2 passou a classificar cada FK nessas
três situações e a dizer sozinho quando parar.

#### Conferência pós-execução

Feita pela IA:

- **Leitura íntegra**: a Home do grupo 3878 carrega a competência de setembro com despesas,
  saldos e notificações; navegação normal.
- **Nenhuma constraint órfã possível pelo lado do sistema**: todas as foreign keys
  declaradas nas migrations miram só `ex_users`, `ex_groups`, `ex_expenses` e `ex_quotas` —
  nenhuma no singular. Era o risco real de rodar com `FOREIGN_KEY_CHECKS = 0`: uma FK
  apontando para tabela inexistente quebra `INSERT`/`UPDATE` com errno 1824 e **não aparece
  em leitura nenhuma**.

Não conferido pela IA, porque a saída não foi reportada: os passos 1, 2 e 3 e as duas
consultas de verificação do fim do passo 4. A checagem das migrations cobre as FKs que o
Laravel cria, não uma constraint manual vinda no import legado; a consulta de constraint
órfã do passo 4 responde isso a qualquer momento, e é leitura pura.

Estado final: **21 tabelas** — as 13 do sistema, `migrations`, os quatro `_bkp_*` de
04–05/09 (mantidos por escolha do usuário; o passo 5 os deixa comentados) e os três
`_bkp_*_20260906`, que são o rollback do fechamento de agosto e só devem sair depois de o
grupo confirmar os valores do mês.

## 3. PRs

| PR | Conteúdo | Estado |
|---|---|---|
| [#153](https://github.com/isacaguiar/expense/pull/153) | TASK-001 (backend) + TASK-002 (frontend) + scaffold SDD + `.sql` inicial da TASK-003 | Mergeado em `dev` em 2026-09-05 |
| [#155](https://github.com/isacaguiar/expense/pull/155) | Só docs: revisão da TASK-003 — agosto passa a `born_paid` (specify §3.5.1) | Mergeado em `dev` |
| [#156](https://github.com/isacaguiar/expense/pull/156) | Continuação da feature | Mergeado em `dev` |
| [#158](https://github.com/isacaguiar/expense/pull/158) | Continuação da feature | Mergeado em `dev` |
| [#159](https://github.com/isacaguiar/expense/pull/159) | Continuação da feature | Mergeado em `dev` |

O #155 existe porque o #153 foi mergeado (e a branch remota apagada) enquanto a decisão sobre
agosto ainda estava sendo tomada; o push seguinte recriou a branch com o commit de docs sozinho.
Não há código nele.

TASK-003 não entra em nenhum merge como comportamento — o `.sql` vai junto só como documento; a
execução em produção foi feita pelo usuário em 2026-09-05 e conferida junto com as TASK-004 e
TASK-005 em 2026-09-06.

Esta pasta foi para `docs/feature/concluidas/202609/` em 2026-09-21 (`ADR-009`), depois de uma
auditoria confirmar que as seis tasks estão entregues: as três de código com evidência no
repositório (ver a nota no topo dos critérios de aceite em `tasks.md`) e as três de produção com
relatório de execução conferido aqui. A condição registrada na versão anterior desta seção — "só
termina quando a TASK-003 for executada e verificada" — foi cumprida em 2026-09-06; a nota é que
ficou para trás.
