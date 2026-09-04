import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatRelative, notificationText } from './notificationText';

describe('notificationText', () => {
  it('expense_paid names the actor and the expense', () => {
    expect(notificationText('expense_paid', { actorName: 'Ana', expenseDescription: 'Luz' })).toBe(
      'Ana marcou "Luz" como paga'
    );
  });

  it('settlement_confirmed formats the amount as BRL currency', () => {
    const text = notificationText('settlement_confirmed', { actorName: 'Bruno', amount: '100.00' });
    expect(text).toMatch(/^Bruno confirmou um pagamento de R\$\s?100,00$/);
  });

  it('cycle_settled names the cycle and the group', () => {
    expect(notificationText('cycle_settled', { cycleLabel: 'agosto/2026', groupName: 'Casa' })).toBe(
      'O ciclo de agosto/2026 de "Casa" foi quitado'
    );
  });

  it('cycle_closed names the actor, the cycle and the group', () => {
    expect(
      notificationText('cycle_closed', { actorName: 'Ana', cycleLabel: 'agosto/2026', groupName: 'Casa' })
    ).toBe('Ana fechou o ciclo de agosto/2026 de "Casa"');
  });

  it('group_member_added addresses the recipient directly', () => {
    expect(notificationText('group_member_added', { actorName: 'Ana', groupName: 'Casa' })).toBe(
      'Ana adicionou você ao grupo "Casa"'
    );
  });

  it('expense_created names the actor and the expense', () => {
    expect(notificationText('expense_created', { actorName: 'Ana', expenseDescription: 'Internet' })).toBe(
      'Ana adicionou a despesa "Internet"'
    );
  });

  it('falls back to a generic text for an unknown type', () => {
    expect(notificationText('whatever', {})).toBe('Nova notificação');
  });
});

describe('formatRelative', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it('formats a moment a few minutes in the past', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-03T12:00:00Z'));

    expect(formatRelative('2026-09-03T11:55:00Z')).toContain('5 minuto');
  });

  it('returns an empty string for an invalid date', () => {
    expect(formatRelative('not-a-date')).toBe('');
  });
});
