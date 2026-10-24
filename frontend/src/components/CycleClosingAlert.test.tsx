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

// Acerto par-a-par do `summary`; `confirmed` = o devedor já enviou o comprovante.
function settlement(from: number, to: number, confirmed = false): Summary['settlements'][number] {
  return {
    from_user_id: from,
    to_user_id: to,
    amount: 30,
    confirmedProofUrl: confirmed ? 'https://example.test/comprovante.jpg' : null,
    confirmedAt: confirmed ? '2026-10-20T10:00:00-03:00' : null,
  };
}

const ANA = { user_id: 1, name: 'Ana' };
const BRUNO = { user_id: 2, name: 'Bruno' };
const CARLA = { user_id: 3, name: 'Carla' };

function summary(overrides: {
  status: CycleStatus;
  end: string;
  closes_at: string;
  settled?: boolean;
  balances?: { user_id: number; name: string; balance: number }[];
  settlements?: Summary['settlements'];
  pending?: number;
}): Summary {
  return {
    cycle: {
      start: isoOffset(-40),
      end: overrides.end,
      closes_at: overrides.closes_at,
      status: overrides.status,
      settled: overrides.settled ?? false,
    },
    totals: { total: 0, paid: 0, pending: overrides.pending ?? 0 },
    expenses: [],
    balances: overrides.balances ?? [],
    settlements: overrides.settlements ?? [],
  };
}

// Ciclo já fechado por data e ainda não selado: a base dos cenários pós-fechamento.
const closedSummary = (overrides: Omit<Parameters<typeof summary>[0], 'status' | 'end' | 'closes_at'>) =>
  summary({ status: 'closed', end: isoOffset(-6), closes_at: isoOffset(-1), ...overrides });

describe('CycleClosingAlert', () => {
  it('shows the pre-closing notice inside the grace window', () => {
    render(
      <CycleClosingAlert
        summary={summary({ status: 'open', end: isoOffset(-1), closes_at: isoOffset(3) })}
      />
    );
    expect(screen.getByText(/Este ciclo fecha em/)).toBeInTheDocument();
  });

  it('lists the debtors with a settlement still unconfirmed once the cycle is closed', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [
            { ...ANA, balance: -30 },
            { ...BRUNO, balance: -10 },
            { ...CARLA, balance: 40 },
          ],
          settlements: [settlement(1, 3), settlement(2, 3)],
          pending: 40,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Ana, Bruno\./)).toBeInTheDocument();
  });

  it('drops a debtor whose settlement was already confirmed, keeping only the pending ones', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          // Os saldos são o rateio: a confirmação do acerto não os altera.
          balances: [
            { ...ANA, balance: -30 },
            { ...BRUNO, balance: -10 },
            { ...CARLA, balance: 40 },
          ],
          settlements: [settlement(1, 3, true), settlement(2, 3)],
          pending: 40,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Bruno\./)).toBeInTheDocument();
    expect(screen.queryByText(/Ana/)).not.toBeInTheDocument();
  });

  it('renders nothing when every settlement is confirmed but expenses are still pending', () => {
    const { container } = render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [
            { ...ANA, balance: -30 },
            { ...BRUNO, balance: -10 },
            { ...CARLA, balance: 40 },
          ],
          settlements: [settlement(1, 3, true), settlement(2, 3, true)],
          pending: 40,
        })}
      />
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('lists the debtors of a circular debt even though every balance nets to zero', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [
            { ...ANA, balance: 0 },
            { ...BRUNO, balance: 0 },
            { ...CARLA, balance: 0 },
          ],
          settlements: [settlement(1, 2), settlement(2, 3), settlement(3, 1)],
          pending: 90,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Ana, Bruno, Carla\./)).toBeInTheDocument();
  });

  it('lists a net creditor who still owes a third person', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [
            { ...ANA, balance: 20 },
            { ...BRUNO, balance: -20 },
            { ...CARLA, balance: 0 },
          ],
          settlements: [settlement(1, 3), settlement(2, 1)],
          pending: 50,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Ana, Bruno\./)).toBeInTheDocument();
  });

  it('names a debtor once even when owing several creditors', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [
            { ...ANA, balance: -60 },
            { ...BRUNO, balance: 30 },
            { ...CARLA, balance: 30 },
          ],
          settlements: [settlement(1, 2), settlement(1, 3)],
          pending: 60,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Ana\./)).toBeInTheDocument();
  });

  it('falls back to "Desconhecido" for a debtor missing from the balances', () => {
    render(
      <CycleClosingAlert
        summary={closedSummary({
          balances: [{ ...CARLA, balance: 30 }],
          settlements: [settlement(9, 3)],
          pending: 30,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Desconhecido\./)).toBeInTheDocument();
  });

  it('applies the same rule to a manually closed (reviewable) cycle', () => {
    render(
      <CycleClosingAlert
        summary={summary({
          status: 'closed_manually',
          end: isoOffset(-6),
          closes_at: isoOffset(-1),
          balances: [
            { ...ANA, balance: -30 },
            { ...BRUNO, balance: -10 },
            { ...CARLA, balance: 40 },
          ],
          settlements: [settlement(1, 3, true), settlement(2, 3)],
          pending: 40,
        })}
      />
    );
    expect(screen.getByText(/falta acertar: Bruno\./)).toBeInTheDocument();
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
          settlements: [settlement(1, 3)],
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
