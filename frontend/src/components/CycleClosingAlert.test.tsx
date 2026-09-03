import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CycleClosingAlert from './CycleClosingAlert';
import type { Summary, CycleStatus } from '../hooks/useGroupCycle';

// 'YYYY-MM-DD' local para hoje + `days`.
function isoOffset(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function summary(overrides: {
  status: CycleStatus;
  end: string;
  closes_at: string;
  settled?: boolean;
  balances?: { user_id: number; name: string; balance: number }[];
}): Summary {
  return {
    cycle: {
      start: isoOffset(-40),
      end: overrides.end,
      closes_at: overrides.closes_at,
      status: overrides.status,
      settled: overrides.settled ?? false,
    },
    totals: { total: 0, paid: 0, pending: 0 },
    expenses: [],
    balances: overrides.balances ?? [],
    settlements: [],
  };
}

describe('CycleClosingAlert', () => {
  it('shows the pre-closing notice inside the grace window', () => {
    render(
      <CycleClosingAlert
        summary={summary({ status: 'open', end: isoOffset(-1), closes_at: isoOffset(3) })}
      />
    );
    expect(screen.getByText(/Este ciclo fecha em/)).toBeInTheDocument();
  });

  it('lists the debtors once the cycle is closed and still unsettled', () => {
    render(
      <CycleClosingAlert
        summary={summary({
          status: 'closed',
          end: isoOffset(-6),
          closes_at: isoOffset(-1),
          settled: false,
          balances: [
            { user_id: 1, name: 'Ana', balance: -30 },
            { user_id: 2, name: 'Bruno', balance: -10 },
            { user_id: 3, name: 'Carla', balance: 40 },
          ],
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Ana, Bruno\./)).toBeInTheDocument();
  });

  it('renders nothing once the cycle is settled', () => {
    const { container } = render(
      <CycleClosingAlert
        summary={summary({
          status: 'closed',
          end: isoOffset(-6),
          closes_at: isoOffset(-1),
          settled: true,
          balances: [{ user_id: 1, name: 'Ana', balance: -30 }],
        })}
      />
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing for an open cycle whose boundary is still in the future', () => {
    const { container } = render(
      <CycleClosingAlert
        summary={summary({ status: 'open', end: isoOffset(5), closes_at: isoOffset(10) })}
      />
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing for a closed cycle with no debtors', () => {
    const { container } = render(
      <CycleClosingAlert
        summary={summary({
          status: 'closed',
          end: isoOffset(-6),
          closes_at: isoOffset(-1),
          balances: [{ user_id: 1, name: 'Ana', balance: 0 }],
        })}
      />
    );
    expect(container).toBeEmptyDOMElement();
  });
});
