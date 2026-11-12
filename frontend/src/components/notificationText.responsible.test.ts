import { describe, expect, it } from 'vitest';
import type { SummarySettlement } from '../hooks/useGroupCycle';
import type { GroupMember, GroupMemberBasic } from '../types/group';
import { notificationText } from './notificationText';

/**
 * Quem paga por quem (feature 20261109-responsavel-do-devedor, plan §8): o texto do sino para o
 * responsável escolhido e para o credor que recebe o comprovante enviado por outra pessoa, e os tipos
 * que o resto da feature usa.
 */
describe('notificationText: quem paga por quem', () => {
  it('payment_responsible_assigned tells the chosen member they can now pay for the debtor', () => {
    expect(notificationText('payment_responsible_assigned', { actorName: 'João', groupName: 'Casa' })).toBe(
      'Você agora pode pagar por João no grupo "Casa"'
    );
  });

  it('settlement_confirmed with paidByName says who paid and for whom', () => {
    const text = notificationText('settlement_confirmed', {
      actorName: 'João',
      paidByName: 'Maria',
      amount: '100.00'
    });

    // Sem o `$` final: a TASK-606 acrescenta "Aguarda o seu aceite." (o texto completo está em notificationText.acceptance.test.ts).
    expect(text).toMatch(/^Maria confirmou um pagamento de R\$\s?100,00 por João\./);
  });

  it('settlement_confirmed without paidByName keeps the text it always had', () => {
    const text = notificationText('settlement_confirmed', { actorName: 'João', amount: '100.00' });

    expect(text).toMatch(/^João confirmou um pagamento de R\$\s?100,00$/);
  });

  it('settlement_confirmed ignores an empty or null paidByName', () => {
    for (const paidByName of [null, '']) {
      const text = notificationText('settlement_confirmed', { actorName: 'João', paidByName, amount: '100.00' });

      expect(text).toMatch(/^João confirmou um pagamento de R\$\s?100,00$/);
    }
  });
});

describe('tipos de quem paga por quem', () => {
  it('the settlement and the member carry the new optional fields', () => {
    const settlement: SummarySettlement = {
      from_user_id: 1,
      to_user_id: 2,
      amount: 10,
      confirmedProofUrl: null,
      confirmedAt: null,
      confirmedBy: { id: 3, name: 'Maria' }
    };
    const withoutSender: SummarySettlement = {
      from_user_id: 1,
      to_user_id: 2,
      amount: 10,
      confirmedProofUrl: null,
      confirmedAt: null,
      confirmedBy: null
    };
    const member: GroupMember = {
      id: 1,
      name: 'João',
      email: 'joao@exemplo.com',
      avatar_url: null,
      responsibles: [{ id: 3, name: 'Maria' }]
    };

    expect(settlement.confirmedBy?.name).toBe('Maria');
    expect(withoutSender.confirmedBy).toBeNull();
    expect(member.responsibles?.[0].id).toBe(3);
  });

  it('GET /groups members do not carry responsibles', () => {
    const basic: GroupMemberBasic = { id: 1, name: 'João', email: 'joao@exemplo.com' };
    // @ts-expect-error: `GET /api/groups` não devolve `responsibles`; só `GET /api/groups/{id}/members`.
    const invalid: GroupMemberBasic = { id: 1, name: 'João', email: 'joao@exemplo.com', responsibles: [] };

    expect(basic.name).toBe('João');
    expect(invalid.id).toBe(1);
  });
});
