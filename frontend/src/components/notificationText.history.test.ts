import { describe, expect, it } from 'vitest';
import type { SettlementProofEntry, SummarySettlement } from '../hooks/useGroupCycle';
import { notificationText } from './notificationText';

/**
 * Histórico de comprovantes e justificativa (feature 20261114-historico-de-comprovantes-e-justificativa,
 * plan §11, TASK-629): o texto do sino do complemento e os tipos novos do resumo, todos opcionais.
 */
describe('notificationText: complemento do comprovante', () => {
  it('settlement_complement_added names who added it, whose settlement it is and the amount', () => {
    const text = notificationText('settlement_complement_added', {
      actorName: 'Maria',
      debtorName: 'João',
      amount: '100.00',
      groupName: 'Casa',
      groupId: 7
    });

    expect(text).toMatch(/^Maria adicionou uma justificativa e um comprovante ao acerto de João de R\$\s?100,00$/);
  });

  it('never shows a justification, even if one is in the data (the backend does not send it)', () => {
    const text = notificationText('settlement_complement_added', {
      actorName: 'Maria',
      debtorName: 'João',
      amount: '10',
      justificativa: 'texto secreto'
    });

    expect(text).not.toContain('texto secreto');
  });

  it('does not change the neighbouring texts', () => {
    expect(notificationText('settlement_refused', { actorName: 'Ana', debtorName: 'João', amount: '100.00' })).toMatch(
      /^Ana recusou o comprovante de João de R\$\s?100,00$/
    );
    expect(notificationText('tipo_desconhecido', {})).toBe('Nova notificação');
  });
});

describe('tipos do histórico no resumo', () => {
  it('SummarySettlement keeps compiling without the new fields and accepts them when present', () => {
    const base: SummarySettlement = { from_user_id: 1, to_user_id: 2, amount: 10, confirmedProofUrl: null, confirmedAt: null };
    const entry: SettlementProofEntry = {
      id: 1,
      kind: 'complement',
      status: null,
      sentBy: { id: 3, name: 'Maria' },
      amount: 10,
      note: 'justificativa',
      refusalReason: null,
      sentAt: '2026-08-20T10:00:00-03:00',
      refusedAt: null,
      archivedAt: '2026-08-20T10:00:00-03:00',
      proofUrl: null
    };
    const full: SummarySettlement = {
      ...base,
      refusedProof: { proofUrl: 'https://x/y', note: 'foto', reason: 'cortada' },
      proofHistory: [entry]
    };

    expect(base.proofHistory).toBeUndefined();
    expect(full.proofHistory?.[0].kind).toBe('complement');
    expect(full.refusedProof?.reason).toBe('cortada');
  });
});
