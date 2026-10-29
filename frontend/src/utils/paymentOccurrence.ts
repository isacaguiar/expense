import type { ExpenseType } from '../types/expense';

// Textos da seção "Pagamento (opcional)" do cadastro de despesa
// (docs/feature/20261027-pagamento-no-cadastro-despesa, R9 e §3.6). São apresentação:
// quais ocorrências ficam pagas e a regra de competência vêm do servidor.

export type PaymentOccurrenceInput = {
  expenseType: ExpenseType;
  /** Parcelas digitadas; só vale para Parcelada, e só se for um inteiro de 2 em diante. */
  installments: number;
  /** 'YYYY-MM-DD': a data da despesa, ou o mês de início das parcelas. */
  date: string;
  /** Nome do Pagador (o credor) escolhido no formulário. */
  payerName: string;
  /** O Pagador escolhido é o próprio usuário logado? */
  isSelf: boolean;
};

export type PaymentOccurrenceText = {
  /** O que será marcado como pago. */
  summary: string;
  /** Aviso de que o pagamento vai em nome de outra pessoa; `null` quando o Pagador é o usuário. */
  onBehalf: string | null;
  /** Aviso fixo: depois do fechamento do ciclo, o pagamento da compra não muda mais. */
  warning: string;
};

/** 'YYYY-MM-DD' → "agosto de 2026", sem o deslocamento de um dia que `new Date(string)` causa em fusos negativos. */
const monthLabel = (date: string): string | null => {
  const [year, month, day] = date.split('-').map(Number);

  if (!year || !month || !day) return null;

  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
};

const summaryOf = ({ expenseType, installments, date }: PaymentOccurrenceInput): string => {
  const month = monthLabel(date);

  if (expenseType === 'IN_INSTALLMENTS') {
    const count = Number.isInteger(installments) && installments >= 2 ? `as ${installments} parcelas` : 'todas as parcelas';
    const start = month ? `, a começar por ${month}` : '';

    return `Serão marcadas como pagas ${count}${start} (compra paga pelo credor, por exemplo no cartão). O valor não poderá ser alterado depois.`;
  }

  if (expenseType === 'FIXED') {
    return month
      ? `Será marcada como paga apenas a ocorrência de ${month}; as próximas continuam pendentes.`
      : 'Será marcada como paga apenas a primeira ocorrência; as próximas continuam pendentes.';
  }

  return month
    ? `Esta despesa será marcada como paga (competência de ${month}).`
    : 'Esta despesa será marcada como paga.';
};

export const describePaymentOccurrence = (input: PaymentOccurrenceInput): PaymentOccurrenceText => ({
  summary: summaryOf(input),
  onBehalf: input.isSelf ? null : `O pagamento será registrado em nome de ${input.payerName}, que será avisado.`,
  warning: 'Depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado.',
});
