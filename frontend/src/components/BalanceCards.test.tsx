import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import BalanceCards from './BalanceCards';
import type { SummaryBalance } from '../hooks/useGroupCycle';

/**
 * Item 086, D7-B (CA-17): o cartão de saldo de quem já saiu do grupo traz o rótulo
 * "saiu do grupo". O servidor decide quem é ex-membro e manda `formerMember: true`
 * só nas entradas novas de `balances`; os cartões dos membros não mudam.
 */

const ana: SummaryBalance = { user_id: 1, name: 'Ana', avatarUrl: null, balance: 60 };
const bruno: SummaryBalance = { user_id: 2, name: 'Bruno', avatarUrl: null, balance: -30, formerMember: true };
const carla: SummaryBalance = { user_id: 3, name: 'Carla', avatarUrl: null, balance: -30 };

const cardOf = (name: string): HTMLElement => screen.getByText(name).closest('.MuiCard-root') as HTMLElement;

describe('BalanceCards', () => {
  it('labels the card of a former member with "saiu do grupo", keeping the name and the amount', () => {
    render(<BalanceCards balances={[bruno]} />);

    const card = cardOf('Bruno');
    expect(within(card).getByText('saiu do grupo')).toBeInTheDocument();
    expect(within(card).getByText('Bruno')).toBeInTheDocument();
    expect(within(card).getByText(/R\$\s?30,00 a pagar/)).toBeInTheDocument();
  });

  it('puts the label below the name and above the amount', () => {
    render(<BalanceCards balances={[bruno]} />);

    const order = Array.from(cardOf('Bruno').querySelectorAll('p, span')).map(node => node.textContent ?? '');
    const name = order.findIndex(text => text === 'Bruno');
    const label = order.findIndex(text => text === 'saiu do grupo');
    const amount = order.findIndex(text => /a pagar/.test(text));

    expect(name).toBeGreaterThanOrEqual(0);
    expect(label).toBeGreaterThan(name);
    expect(amount).toBeGreaterThan(label);
  });

  it('does not label a member card, which has no formerMember key', () => {
    render(<BalanceCards balances={[ana]} />);

    expect(screen.queryByText('saiu do grupo')).not.toBeInTheDocument();
    expect(within(cardOf('Ana')).getByText(/R\$\s?60,00 a receber/)).toBeInTheDocument();
  });

  it('does not label a card with formerMember false', () => {
    render(<BalanceCards balances={[{ ...ana, formerMember: false }]} />);

    expect(screen.queryByText('saiu do grupo')).not.toBeInTheDocument();
  });

  it('labels only the former member in a mixed list', () => {
    render(<BalanceCards balances={[ana, carla, bruno]} />);

    expect(screen.getAllByText('saiu do grupo')).toHaveLength(1);
    expect(within(cardOf('Bruno')).getByText('saiu do grupo')).toBeInTheDocument();
    expect(within(cardOf('Ana')).queryByText('saiu do grupo')).not.toBeInTheDocument();
    expect(within(cardOf('Carla')).queryByText('saiu do grupo')).not.toBeInTheDocument();
  });
});
