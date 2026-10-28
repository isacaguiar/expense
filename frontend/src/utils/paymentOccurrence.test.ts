import { describe, expect, it } from 'vitest';
import { describePaymentOccurrence, type PaymentOccurrenceInput } from './paymentOccurrence';

const input = (overrides: Partial<PaymentOccurrenceInput> = {}): PaymentOccurrenceInput => ({
  expenseType: 'IN_CASH',
  installments: 1,
  date: '2026-08-10',
  payerName: 'Ana',
  isSelf: true,
  ...overrides,
});

describe('describePaymentOccurrence', () => {
  it('says an in-cash expense itself is marked as paid, with its competence', () => {
    const { summary } = describePaymentOccurrence(input());

    expect(summary).toBe('Esta despesa será marcada como paga (competência de agosto de 2026).');
  });

  it('says a purchase in installments has every installment marked as paid, and the value is locked', () => {
    const { summary } = describePaymentOccurrence(input({ expenseType: 'IN_INSTALLMENTS', installments: 12 }));

    expect(summary).toContain('Serão marcadas como pagas as 12 parcelas');
    expect(summary).toContain('a começar por agosto de 2026');
    expect(summary).toContain('compra paga pelo credor, por exemplo no cartão');
    expect(summary).toContain('O valor não poderá ser alterado depois.');
  });

  it('does not print a made-up count while the number of installments is not valid yet', () => {
    for (const installments of [Number.NaN, 0, 1]) {
      const { summary } = describePaymentOccurrence(input({ expenseType: 'IN_INSTALLMENTS', installments }));

      expect(summary).toContain('Serão marcadas como pagas todas as parcelas');
      expect(summary).not.toMatch(/\d+ parcelas/);
    }
  });

  it('says a fixed expense has only the initial occurrence marked as paid', () => {
    const { summary } = describePaymentOccurrence(input({ expenseType: 'FIXED', date: '2026-09-05' }));

    expect(summary).toBe('Será marcada como paga apenas a ocorrência de setembro de 2026; as próximas continuam pendentes.');
  });

  it('always carries the fixed warning about the cycle closing', () => {
    for (const expenseType of ['IN_CASH', 'IN_INSTALLMENTS', 'FIXED'] as const) {
      expect(describePaymentOccurrence(input({ expenseType })).warning).toBe(
        'Depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado.'
      );
    }
  });

  it('says the payment is registered in the payers name, and that they will be told, only when it is not the user', () => {
    expect(describePaymentOccurrence(input({ isSelf: true })).onBehalf).toBeNull();
    expect(describePaymentOccurrence(input({ isSelf: false, payerName: 'Carla' })).onBehalf).toBe(
      'O pagamento será registrado em nome de Carla, que será avisado.'
    );
  });

  it('names the competence of the first and last day of a month without shifting it (no UTC drift)', () => {
    expect(describePaymentOccurrence(input({ date: '2026-08-31' })).summary).toContain('agosto de 2026');
    expect(describePaymentOccurrence(input({ date: '2026-09-01' })).summary).toContain('setembro de 2026');
    expect(describePaymentOccurrence(input({ date: '2026-12-31' })).summary).toContain('dezembro de 2026');
    expect(describePaymentOccurrence(input({ date: '2027-01-01' })).summary).toContain('janeiro de 2027');
  });

  it('falls back to a text without a month while the date is empty or invalid', () => {
    for (const date of ['', 'not-a-date']) {
      const { summary } = describePaymentOccurrence(input({ date }));

      expect(summary).toBe('Esta despesa será marcada como paga.');
    }
  });
});
