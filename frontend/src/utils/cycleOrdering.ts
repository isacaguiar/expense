// Ordem e agrupamento de apresentação de despesas, saldos e acertos de um ciclo.
// Funções puras sobre o `summary` já carregado: não calculam, não arredondam e
// não liquidam nada (isso é do backend), só decidem a ordem em que o que ele
// devolveu aparece e a que aba cada acerto pertence. Nunca alteram o argumento:
// o `summary` é estado de `useGroupCycle`, e o `sort` nativo reordena no lugar.
// docs/feature/20261026-organizacao-despesas-pagamentos/plan.md §1.

import type { SummaryBalance, SummaryExpense, SummarySettlement } from '../hooks/useGroupCycle';

// Dinheiro é comparado em centavos inteiros, para diferença abaixo de um
// centavo não decidir a ordem. O `|| 0` troca o `-0` de `Math.round(-0.4)` por 0.
const cents = (value: number): number => Math.round(value * 100) || 0;

// Mesmo critério de `utils/expenseFilters.ts`: "Álvaro" vem antes de "Bruno".
const byName = (a: string, b: string): number => a.localeCompare(b, 'pt-BR');

/**
 * Despesas pela data da ocorrência na competência (`date`, 'YYYY-MM-DD'), da mais
 * recente para a mais antiga. Substitui o "pendentes primeiro" do backend: o
 * status não decide a posição. Empate: `id` da despesa decrescente e, quando a
 * mesma despesa tem duas parcelas na competência, o número da parcela
 * decrescente (ausente vale 0).
 */
export const sortExpensesByOccurrence = (expenses: readonly SummaryExpense[]): SummaryExpense[] =>
  [...expenses].sort((a, b) => {
    // Comparação de texto: 'YYYY-MM-DD' ordena como data e evita o fuso de `new Date`.
    if (a.date !== b.date) return a.date < b.date ? 1 : -1;
    if (a.id !== b.id) return b.id - a.id;
    return (b.installmentNumber ?? 0) - (a.installmentNumber ?? 0);
  });

/**
 * Chave de React de uma linha de despesa. O `id` é o da despesa e se repete quando
 * a mesma despesa tem duas parcelas na competência: o número da parcela e a data
 * entram para a chave continuar única.
 */
export const expenseRowKey = (expense: SummaryExpense): string =>
  `${expense.id}-${expense.installmentNumber ?? 0}-${expense.date}`;

/**
 * Saldos com o usuário logado primeiro; os demais por saldo decrescente (positivos,
 * zero, depois negativos do menos para o mais negativo), com empate por nome e
 * por `user_id`. Sem usuário logado, ou fora da lista, vale só a regra dos demais.
 */
export const sortBalancesForViewer = (
  balances: readonly SummaryBalance[],
  viewerId: number | null | undefined
): SummaryBalance[] => {
  const viewer = viewerId == null ? undefined : balances.find(balance => balance.user_id === viewerId);

  const others = balances
    .filter(balance => balance !== viewer)
    .sort(
      (a, b) => cents(b.balance) - cents(a.balance) || byName(a.name, b.name) || a.user_id - b.user_id
    );

  return viewer ? [viewer, ...others] : others;
};

/**
 * Um acerto está "pago" quando tem `confirmedAt`. Nunca por `Quota.paid` da
 * despesa nem pela existência de URL de comprovante (que expira no snapshot).
 */
export const isSettlementConfirmed = (settlement: Pick<SummarySettlement, 'confirmedAt'>): boolean =>
  Boolean(settlement.confirmedAt);

/**
 * Reparte os acertos nas abas "Valores a pagar" (`toPay`) e "Valores pagos"
 * (`paid`), preservando a ordem de entrada. Snapshot antigo sem `settlements`
 * (`null`/`undefined`) vira duas listas vazias.
 */
export const splitSettlementsByConfirmation = (
  settlements: readonly SummarySettlement[] | null | undefined
): { toPay: SummarySettlement[]; paid: SummarySettlement[] } => {
  const toPay: SummarySettlement[] = [];
  const paid: SummarySettlement[] = [];

  for (const settlement of settlements ?? []) {
    (isSettlementConfirmed(settlement) ? paid : toPay).push(settlement);
  }

  return { toPay, paid };
};

/**
 * Ordem dos acertos de **uma aba**. Primeiro as saídas do usuário logado (valor
 * decrescente, depois nome do credor e id). Depois os demais devedores, pelo total
 * do devedor **na lista recebida** (soma dos acertos dele naquela aba) decrescente,
 * com empate por nome e id; dentro do devedor, valor decrescente, nome do credor e
 * id. O que o logado tem a receber fica entre os demais, no grupo de quem deve.
 * O total serve só de chave de ordenação: não é exibido.
 */
export const orderSettlementsForViewer = (
  settlements: readonly SummarySettlement[],
  viewerId: number | null | undefined,
  nameFor: (userId: number) => string
): SummarySettlement[] => {
  const debtorTotals = new Map<number, number>();
  for (const settlement of settlements) {
    debtorTotals.set(
      settlement.from_user_id,
      (debtorTotals.get(settlement.from_user_id) ?? 0) + cents(settlement.amount)
    );
  }

  const isViewerOutgoing = (settlement: SummarySettlement): boolean =>
    viewerId != null && settlement.from_user_id === viewerId;

  return [...settlements].sort((a, b) => {
    if (isViewerOutgoing(a) !== isViewerOutgoing(b)) return isViewerOutgoing(a) ? -1 : 1;

    const byDebtor =
      (debtorTotals.get(b.from_user_id) ?? 0) - (debtorTotals.get(a.from_user_id) ?? 0) ||
      byName(nameFor(a.from_user_id), nameFor(b.from_user_id)) ||
      a.from_user_id - b.from_user_id;
    if (byDebtor !== 0) return byDebtor;

    return (
      cents(b.amount) - cents(a.amount) ||
      byName(nameFor(a.to_user_id), nameFor(b.to_user_id)) ||
      a.to_user_id - b.to_user_id
    );
  });
};
