import { describe, expect, it } from 'vitest';
import type { SummarySettlement } from '../hooks/useGroupCycle';
import { notificationText } from './notificationText';

/**
 * Quem paga se declara e o credor aceita ou recusa (feature 20261110-quem-paga-se-declara-e-credor-recusa,
 * plan §9, TASK-606): os textos do sino dos avisos novos e os campos do resumo que as telas da 608 usam.
 */
describe('notificationText: quem paga se declara e o credor decide', () => {
  it('payment_responsible_self_declared tells the debtor who now pays for them', () => {
    expect(
      notificationText('payment_responsible_self_declared', { actorName: 'Maria', groupName: 'Casa', groupId: 7 })
    ).toBe('Maria agora paga por você no grupo "Casa"');
  });

  it('settlement_refused names the creditor, whose proof it was and the amount', () => {
    const text = notificationText('settlement_refused', {
      actorName: 'Ana',
      debtorName: 'João',
      amount: '100.00',
      groupName: 'Casa'
    });

    expect(text).toMatch(/^Ana recusou o comprovante de João de R\$\s?100,00$/);
  });

  it('settlement_accepted names the creditor and the amount', () => {
    const text = notificationText('settlement_accepted', { actorName: 'Ana', amount: '100.00', groupName: 'Casa' });

    expect(text).toMatch(/^Ana aceitou o comprovante de R\$\s?100,00$/);
  });

  it('settlement_confirmed from someone paying for the debtor adds that it awaits the creditor’s acceptance', () => {
    const text = notificationText('settlement_confirmed', {
      actorName: 'João',
      paidByName: 'Maria',
      amount: '100.00'
    });

    expect(text).toMatch(/^Maria confirmou um pagamento de R\$\s?100,00 por João\. Aguarda o seu aceite\.$/);
  });

  it('settlement_confirmed from the debtor themself keeps the text it always had, without the complement', () => {
    for (const paidByName of [undefined, null, '']) {
      const text = notificationText('settlement_confirmed', { actorName: 'João', paidByName, amount: '100.00' });

      expect(text).toMatch(/^João confirmou um pagamento de R\$\s?100,00$/);
      expect(text).not.toContain('Aguarda');
    }
  });

  it('the new types are not the generic fallback', () => {
    for (const type of ['payment_responsible_self_declared', 'settlement_refused', 'settlement_accepted']) {
      expect(notificationText(type, { actorName: 'Ana', amount: '10.00' })).not.toBe('Nova notificação');
    }
  });
});

describe('tipos do comprovante em decisão', () => {
  const base = { from_user_id: 1, to_user_id: 2, amount: 10, confirmedProofUrl: null, confirmedAt: null };

  it('the settlement carries the status of its proof and when it was refused', () => {
    const awaiting: SummarySettlement = {
      ...base,
      confirmedBy: { id: 3, name: 'Maria' },
      confirmationStatus: 'awaiting_acceptance',
      refusedAt: null
    };
    const refused: SummarySettlement = {
      ...base,
      confirmedBy: { id: 3, name: 'Maria' },
      confirmationStatus: 'refused',
      refusedAt: '2026-08-20T10:00:00-03:00'
    };
    const noProof: SummarySettlement = { ...base, confirmationStatus: null };
    const olderResponse: SummarySettlement = { ...base };

    expect(awaiting.confirmationStatus).toBe('awaiting_acceptance');
    expect(refused.refusedAt).toBe('2026-08-20T10:00:00-03:00');
    expect(noProof.confirmationStatus).toBeNull();
    expect(olderResponse.confirmationStatus).toBeUndefined();
  });

  it('only the four states the API knows are accepted', () => {
    const states: SummarySettlement['confirmationStatus'][] = ['sent', 'awaiting_acceptance', 'accepted', 'refused', null];
    // @ts-expect-error: não existe o estado "pending" no contrato da API.
    const invalid: SummarySettlement['confirmationStatus'] = 'pending';

    expect(states).toHaveLength(5);
    expect(invalid).toBe('pending');
  });
});
