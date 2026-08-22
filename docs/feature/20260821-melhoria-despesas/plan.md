# Plan — Melhoria da Gestão de Despesas do Grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Grid de despesas + saldo por pessoa (specify §2.1)

- `GroupSummary.tsx` (rota `/groups/:id/summary`) continua existindo como Home do grupo — é linkada do `Sidebar`, `GroupSidebar` e `Dashboard`, não vai ser removida nem substituída.
- `ExpenseManager.tsx` (rota `/groups/:id/expenses`) é reescrita para o layout em grid: extrai da `GroupSummary.tsx` o padrão de navegação por ciclo (`cyclesAgo`, setas, chip de status do ciclo — hoje só em `GroupSummary.tsx:85,133-157`) e o de saldo por pessoa (`balances`, avatar + nome + valor — hoje só em `GroupSummary.tsx:242-262`) para um hook/componente compartilhado (ex.: `useGroupCycle(groupId)` e `<BalanceCards balances={...} />`) reaproveitado pelas duas páginas. Isso evita duplicar a lógica de ciclo em dois lugares divergentes (ver §8) sem fundir as rotas.
- A listagem principal (coluna esquerda) usa o estilo de card da imagem de referência como base, mas ganha: Tipo (já existe o ícone `AutorenewOutlinedIcon`/`ReceiptOutlinedIcon`), Competência (implícita — é a competência selecionada no cabeçalho), Credor (`payerName`, já vem do backend), Pagadores (`participants`, já vem como array de nomes — trocar "Dividido entre N pessoas" por lista/tooltip com os nomes), Status (`paid`, hoje só existe de fato para `IN_CASH`/`IN_INSTALLMENTS` — ver §6), e os ícones de ação (Editar/Excluir/Marcar como paga) com `Tooltip` do MUI, renderizados condicionalmente conforme as regras de §2–§6.
- Fonte de dados: `GET /groups/{groupId}/expenses/summary` (`ExpenseController::summary`, já devolve `expenses` e `balances` prontos) substitui a chamada atual de `ExpenseManager.tsx` a `GET /groups/{groupId}/expenses` (que não tem saldo nem status) — ver §8 sobre a divergência de navegação que isso implica resolver junto.

## 2. Exclusão de despesas variáveis (specify §2.2)

- Frontend: novo `Dialog` de confirmação (padrão já existe em `ExpenseManager.tsx:273-293` para o fluxo de corte de recorrência — reaproveitar o mesmo componente/estilo), mostrando a descrição da despesa antes de confirmar `DELETE /expenses/{id}`.
- Backend: `ExpenseController::destroy` (`ExpenseController.php:139-151`) ganha uma checagem nova antes de `rejectIfCycleClosed()`: se `expense` tem alguma quota com `paid = true` associada à competência corrente, recusar com 422 ("despesa paga não pode ser excluída — desfaça o pagamento primeiro"). `rejectIfCycleClosed()` continua cobrindo o bloqueio por competência fechada (já existe e funciona para `IN_CASH`/`IN_INSTALLMENTS`).
- Ação de excluir só aparece na UI quando: despesa é `IN_CASH`/`IN_INSTALLMENTS` (não `FIXED` — essa já tem seu próprio fluxo de corte de recorrência), competência selecionada é a aberta, e status é `PENDENTE`.

## 3. Despesas fixas — valor por competência (specify §2.3)

Decisão central: o mecanismo de "congelar valor por competência" já existe parcialmente — é a `Quota` (`ex_quotas`, `value_quota`, `date_expected`, ligada a `expense_id`). Hoje uma despesa `FIXED` só materializa **uma** `Quota` (mês de criação, `ExpenseController.php:244-251`); os meses seguintes são recalculados ao vivo a partir de `expense.total_value`, hardcoded sem `Quota` própria (`ExpenseController.php:524-556`, `'value' => (float) $expense->total_value`).

- Estender `collectCycleEntries()` (`ExpenseController.php:524-556`): para cada ocorrência mensal de uma `FIXED`, buscar primeiro se já existe uma `Quota` com `date_expected` naquele mês (mesmo padrão já usado para `$direct` na linha 493); se existir, usar `value_quota`/`paid` dela (valor congelado); se não existir, projetar ao vivo a partir de `expense.total_value` (comportamento atual, para meses ainda não tocados).
- Materializar a `Quota` do mês (criar a linha real, com o valor vigente naquele momento) sempre que a competência daquele mês for fechada (§4) — isso substitui a dependência atual de `GroupCycleSnapshot` ser a única proteção de histórico (que falha se ninguém consultou o resumo antes de editar `total_value`, achado do `specify.md §2.3`). Com a `Quota` materializada no fechamento, editar `total_value` da `FIXED` depois nunca mais afeta o valor daquele mês, independente de snapshot ter sido lido ou não.
- `ExpenseController::update()` continua sem checar `rejectIfCycleClosed()` para `FIXED` (`ExpenseController.php:162-164`) — correto manter assim, porque a definição `FIXED` não pertence a uma única competência; o que muda é que a alteração só afeta meses **sem** `Quota` materializada (abertos/futuros), nunca os já fechados — a proteção passa a estar nos dados, não numa regra de bloqueio de edição.

## 4. Fechamento mensal (specify §2.4)

Hoje o "fechamento" é 100% automático por data (`BillingCycle::statusFor`, `ex_groups.closing_day`) e a foto (`GroupCycleSnapshot`) só é criada preguiçosamente na primeira leitura pós-fechamento (`ExpenseController.php:355-396`). Isso não cobre "fechar a qualquer momento, atualizável até a virada" — é preciso um estado novo, desacoplado do status automático.

- Adicionar em `ex_group_cycle_snapshots`: colunas `closed_manually_at` (timestamp nullable) e `reopened_at` (timestamp nullable). O registro em si (`totals`/`expenses`/`balances`) continua sendo a cópia congelada.
- Nova rota `POST /groups/{groupId}/expenses/close`: só permitida se `BillingCycle::statusFor(...)` da competência vigente for `open`. Materializa `Quota` para toda `FIXED` sem quota do mês (§3), calcula `computeCycleSummary()` e faz **upsert** (não só `create`, ao contrário de `cycleSnapshotFor` hoje) em `GroupCycleSnapshot` para `cycle_start` da competência vigente, setando `closed_manually_at = now()` e `reopened_at = null`. Chamar de novo (re-fechar) recalcula e sobrescreve — é o "a cópia pode ser atualizada até a virada" do objetivo.
- `ExpenseController::summary()` (`ExpenseController.php:320-345`) passa a considerar "fechado para leitura/edição" quando `BillingCycle` diz `closed` **OU** existe snapshot da competência vigente com `closed_manually_at` preenchido e `reopened_at` nulo — nesse segundo caso, devolve o snapshot manual (não recomputa ao vivo), mas o `cycle.status` retornado ao frontend distingue `closed` (automático/definitivo) de um novo `closed_manually` (para a UI saber que ainda pode reabrir, diferente de uma competência definitivamente fechada).
- `rejectIfCycleClosed()` (§9) passa a checar os dois casos (automático e manual) para `IN_CASH`/`IN_INSTALLMENTS`, e o novo bloqueio de status (§6) também.

## 5. Reabertura de mês (specify §2.5)

- Nova rota `POST /groups/{groupId}/expenses/reopen`: só permitida se a competência tiver um `GroupCycleSnapshot` com `closed_manually_at` preenchido e `reopened_at` nulo, **e** `BillingCycle::statusFor(...)` ainda for `open` (mesmo mês vigente — se já virou `closed` automático, a rota recusa com 422, independente do estado manual). Seta `reopened_at = now()`.
- Depois de reaberta, a competência volta a computar ao vivo (`computeCycleSummary`) até ser fechada de novo — o snapshot antigo fica no histórico da tabela (não é apagado), mas deixa de ser a fonte de leitura enquanto `reopened_at` for mais recente que `closed_manually_at`.
- Nenhuma ação de reabertura é exposta na UI para competências com `BillingCycle` `closed` (automático) — a regra "competências anteriores não podem ser reabertas" fica garantida tanto no backend (recusa) quanto na UI (botão nem aparece).

## 6. Pagamento da despesa (specify §2.6)

- **Nasce sempre `PENDENTE`**: `store()` (`ExpenseController.php:175-262`) hoje aceita `quotas.*.paid` vindo direto do cliente (`ExpenseController.php:197,248`) — remover esse campo da validação de entrada (ou ignorá-lo) e forçar `paid = false` na criação de toda `Quota`, sempre.
- **Status por despesa** (o que a UI mostra como `PENDENTE`/`PAGA`) é o `paid` da `Quota` da competência selecionada — já é assim para `IN_CASH`/`IN_INSTALLMENTS`; para `FIXED` passa a valer a partir da materialização descrita em §3/§4.
- Adicionar em `ex_quotas`: colunas `paid_at` (timestamp nullable) e `paid_by` (FK `ex_users`, nullable) — hoje só existe o boolean `paid`, sem quem/quando.
- Nova rota `POST /expenses/{expenseId}/pay`: identifica a `Quota` da competência vigente (materializando-a se for `FIXED` ainda virtual — reaproveita a lógica de §3), checa que `auth()->id() === $expense->user_payer_id` (é o credor — achado da §5 do `specify.md`, apesar do nome de coluna) e que a competência está aberta (nem automática nem manualmente fechada); marca `paid = true`, `paid_at = now()`, `paid_by = auth()->id()`.
- Nova rota `POST /expenses/{expenseId}/unpay` (desfazer pagamento — decisão confirmada: permitido): mesma checagem de credor + competência aberta; volta `paid = false`, `paid_at = null`, `paid_by = null`.
- Uma vez com `paid = true`, `total_value`/`value_quota` não pode mais ser alterado (`update()` ganha checagem: se a `Quota` da competência tem `paid = true`, recusar alteração de valor com 422, independente do tipo).

## 7. Saldo por pessoa (specify §2.7)

- Sem mudança de cálculo — `computeCycleSummary()` (`ExpenseController.php:404-466`) e `cycleSnapshotFor()` já produzem `balances` por participante (pago/creditado, devido, líquido implícito no `balance` final). Único ajuste: garantir que a materialização de `Quota` para `FIXED` (§3) não muda o resultado do cálculo de saldo (que já usa `payers`/`user_payer_id`, não depende de `paid`) — confirmar com teste, não é mudança de lógica.
- Frontend: cards de saldo (coluna direita da grid, §1) reaproveitam exatamente o que `GroupSummary.tsx:245-262` já renderiza (avatar com iniciais, nome, valor, "a receber"/"a pagar") via o componente compartilhado `<BalanceCards />` decidido em §1.

## 8. Competência selecionada / navegação (specify §2.8)

- A navegação da tela final usa o padrão de `GroupSummary.tsx` (`cyclesAgo`, `BillingCycle` real via backend) — não o `currentDate`/`changeMonth` de mês calendário puro que `ExpenseManager.tsx` usa hoje (`ExpenseManager.tsx:60,79-85`). Essa segunda abordagem é removida da tela de despesas.
- Cabeçalho mostra o rótulo da competência (`< Agosto 2026 >`) e um chip de status: `Ciclo em andamento` (aberto, sem fechamento manual), `Fechado (revisável)` (fechamento manual ainda no mês vigente — permite reabrir), `Ciclo fechado` (definitivo, automático) ou `Ciclo futuro` — reaproveita `cycleStatusChip` de `GroupSummary.tsx:33-37`, estendido com o novo estado.
- Para competências fechadas (manual ou automática), a UI esconde ações de editar/excluir/pagar/despagar — refletindo o que o backend já recusa (§2, §4, §5, §6), nunca decidindo isso só no frontend (specify §2.9).

## 9. Regras de consistência — validação também no backend (specify §2.9)

- `rejectIfCycleClosed()` (`ExpenseController.php:160-173`) é generalizado para: (a) considerar fechamento manual além do automático (§4), (b) ser chamada também em `store()` (hoje não é chamada lá — `ExpenseController.php:175-262` — permite criar despesa em competência já fechada sem bloqueio, achado do `specify.md`), e (c) ser reaproveitada pelas novas rotas de pagar/despagar/excluir (§2, §6), não só update/destroy de valor.
- Toda regra nova (fechar, reabrir, pagar, despagar, excluir) vive em métodos de `ExpenseController` (ou um novo `ExpenseController`-adjacent, se o tamanho justificar — decisão de implementação, não deste plano) que fazem a validação de domínio primeiro e só then persistem — o frontend nunca decide sozinho se uma ação é permitida, só reflete o que a API já retornaria (esconder botão quando a regra não seria satisfeita, mas a API recusa de qualquer forma se chamada direto).

## 10. Ordem de execução

Há dependência técnica real entre os itens — não é livre por severidade/esforço:

1. **§6 (status de pagamento) primeiro** — schema novo (`paid_at`/`paid_by` em `ex_quotas`, forçar `paid=false` no `store()`) é pré-requisito de §2 (excluir bloqueia se pago) e de §4/§5 (fechar precisa saber o que está pago).
2. **§3 (materialização de `Quota` para `FIXED`)** — pré-requisito técnico de §4 (fechar precisa materializar) e de §6 (pagar uma `FIXED` precisa de uma `Quota` real).
3. **§4 (fechamento manual) e §9 (generalização de `rejectIfCycleClosed`)** juntos — §4 não funciona sem a checagem de competência já cobrir o estado manual, e vice-versa.
4. **§5 (reabertura)** depende de §4 existir (não há o que reabrir sem fechamento manual).
5. **§2 (exclusão de variável)** depende de §6 (checar pago) e §9 (checar competência) já implementados.
6. **§7 (saldo por pessoa)** não tem dependência de código novo — só validar que continua correto após §3; pode ser feito em paralelo com o backend acima.
7. **§8 (navegação) e §1 (layout/grid)** são frontend, consomem tudo acima — vêm por último, junto com a UI de cada ação (modal de exclusão, botão fechar/reabrir, marcar/desfazer pagamento) descrita nas seções correspondentes.
