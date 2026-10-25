import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import SettlementList from './SettlementList';
import { getInitials } from '../layouts/group/getInitials';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

const balances: SummaryBalance[] = [
  { user_id: 1, name: 'Ana', balance: -500 },
  { user_id: 2, name: 'Beto', balance: 500 },
  { user_id: 3, name: 'Caio', balance: 0 },
];

const settlement = (overrides: Partial<SummarySettlement> = {}): SummarySettlement => ({
  from_user_id: 1,
  to_user_id: 2,
  amount: 500,
  confirmedProofUrl: null,
  confirmedAt: null,
  ...overrides,
});

const cards = (container: HTMLElement): HTMLElement[] => Array.from(container.querySelectorAll('.MuiCard-root'));

describe('SettlementList without showConfirmation (the screens that exist today)', () => {
  it('shows only who owes how much to whom, pair by pair', () => {
    const { container } = render(
      <SettlementList
        settlements={[settlement(), settlement({ from_user_id: 3, to_user_id: 1, amount: 12.5 })]}
        balances={balances}
      />
    );

    const [first, second] = cards(container);
    expect(cards(container)).toHaveLength(2);
    expect(first.textContent).toBe(`${getInitials('Ana')}Ana deve pagar R$ 500,00 a Beto${getInitials('Beto')}`);
    expect(second.textContent).toBe(`${getInitials('Caio')}Caio deve pagar R$ 12,50 a Ana${getInitials('Ana')}`);
  });

  it('shows no confirmation status even when the settlement is confirmed and has a note', () => {
    const { container } = render(
      <SettlementList
        settlements={[
          settlement({
            confirmedProofUrl: 'https://x/proof',
            confirmedAt: '2026-10-12T14:30:00-03:00',
            confirmedNote: 'Pix no banco',
          }),
        ]}
        balances={balances}
      />
    );

    expect(container.querySelector('.MuiChip-root')).toBeNull();
    expect(screen.queryByText(/Confirmado/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Aguardando comprovante/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Pix no banco/)).not.toBeInTheDocument();
  });

  it('renders the same thing with showConfirmation explicitly false', () => {
    const settlements = [settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', confirmedNote: 'nota' })];

    const implicit = render(<SettlementList settlements={settlements} balances={balances} />);
    const implicitHtml = implicit.container.innerHTML;
    implicit.unmount();
    const explicit = render(<SettlementList settlements={settlements} balances={balances} showConfirmation={false} />);

    expect(explicit.container.innerHTML).toBe(implicitHtml);
  });

  it('falls back to "Desconhecido" for a user that is not in the balances', () => {
    const { container } = render(<SettlementList settlements={[settlement({ from_user_id: 99 })]} balances={balances} />);

    expect(within(cards(container)[0]).getByText('Desconhecido')).toBeInTheDocument();
  });
});

describe('SettlementList with showConfirmation', () => {
  it('shows the day the settlement was confirmed', () => {
    render(
      <SettlementList
        settlements={[settlement({ confirmedProofUrl: 'https://x/proof', confirmedAt: '2026-10-12T14:30:00-03:00' })]}
        balances={balances}
        showConfirmation
      />
    );

    expect(screen.getByText('Confirmado em 12/10')).toBeInTheDocument();
    expect(screen.queryByText('Aguardando comprovante')).not.toBeInTheDocument();
  });

  it('shows the day in the viewer time zone, not the one in the timestamp', () => {
    // 01:30 UTC de 13/10 ainda é a noite de 12/10 em America/Sao_Paulo (TZ do Vitest).
    render(
      <SettlementList
        settlements={[settlement({ confirmedAt: '2026-10-13T01:30:00+00:00' })]}
        balances={balances}
        showConfirmation
      />
    );

    expect(screen.getByText('Confirmado em 12/10')).toBeInTheDocument();
  });

  it('says it is waiting for the proof when there is no confirmation yet', () => {
    render(<SettlementList settlements={[settlement()]} balances={balances} showConfirmation />);

    expect(screen.getByText('Aguardando comprovante')).toBeInTheDocument();
    expect(screen.queryByText(/Confirmado em/)).not.toBeInTheDocument();
  });

  it('gives each settlement its own status', () => {
    const { container } = render(
      <SettlementList
        settlements={[
          settlement({ confirmedAt: '2026-10-12T14:30:00-03:00' }),
          settlement({ from_user_id: 3, to_user_id: 1, amount: 12.5 }),
        ]}
        balances={balances}
        showConfirmation
      />
    );

    const [confirmed, waiting] = cards(container);
    expect(within(confirmed).getByText('Confirmado em 12/10')).toBeInTheDocument();
    expect(within(confirmed).queryByText('Aguardando comprovante')).not.toBeInTheDocument();
    expect(within(waiting).getByText('Aguardando comprovante')).toBeInTheDocument();
    expect(within(waiting).queryByText(/Confirmado em/)).not.toBeInTheDocument();
  });

  it('keeps the owes-to line exactly as without the prop', () => {
    const { container } = render(<SettlementList settlements={[settlement()]} balances={balances} showConfirmation />);

    expect(within(cards(container)[0]).getByText('R$ 500,00')).toBeInTheDocument();
    expect(within(cards(container)[0]).getByText(/deve pagar/)).toHaveTextContent('Ana deve pagar R$ 500,00 a Beto');
  });

  describe('confirmation note', () => {
    it('shows the note the debtor gave with the proof', () => {
      render(
        <SettlementList
          settlements={[settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', confirmedNote: 'Pix no banco, recibo 9' })]}
          balances={balances}
          showConfirmation
        />
      );

      expect(screen.getByText('Descrição do pagamento: Pix no banco, recibo 9')).toBeInTheDocument();
    });

    it.each([
      ['null', { confirmedNote: null }],
      ['absent', {}],
      ['empty', { confirmedNote: '' }],
    ])('shows nothing extra when the note is %s', (_label, overrides) => {
      render(
        <SettlementList
          settlements={[settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', ...overrides })]}
          balances={balances}
          showConfirmation
        />
      );

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('does not show a note on a settlement that is not confirmed', () => {
      render(
        <SettlementList settlements={[settlement({ confirmedNote: 'sobra de antes' })]} balances={balances} showConfirmation />
      );

      expect(screen.queryByText(/Descrição do pagamento/)).not.toBeInTheDocument();
    });

    it('shows each note under its own settlement', () => {
      const { container } = render(
        <SettlementList
          settlements={[
            settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', confirmedNote: 'nota da Ana' }),
            settlement({ from_user_id: 3, to_user_id: 1, confirmedAt: '2026-10-13T10:00:00-03:00', confirmedNote: 'nota do Caio' }),
          ]}
          balances={balances}
          showConfirmation
        />
      );

      const [first, second] = cards(container);
      expect(within(first).getByText('Descrição do pagamento: nota da Ana')).toBeInTheDocument();
      expect(within(first).queryByText(/nota do Caio/)).not.toBeInTheDocument();
      expect(within(second).getByText('Descrição do pagamento: nota do Caio')).toBeInTheDocument();
    });

    it('renders the note as plain text, never as HTML', () => {
      const note = '<img src=x onerror="alert(1)"><b>negrito</b>';
      const { container } = render(
        <SettlementList
          settlements={[settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', confirmedNote: note })]}
          balances={balances}
          showConfirmation
        />
      );

      expect(screen.getByText(`Descrição do pagamento: ${note}`)).toBeInTheDocument();
      expect(container.querySelector('b')).toBeNull();
      expect(container.querySelector('img[src="x"]')).toBeNull();
    });

    it('puts the note on its own line and lets a long word wrap', () => {
      const note = 'x'.repeat(300);
      render(
        <SettlementList
          settlements={[settlement({ confirmedAt: '2026-10-12T14:30:00-03:00', confirmedNote: note })]}
          balances={balances}
          showConfirmation
        />
      );

      const element = screen.getByText(`Descrição do pagamento: ${note}`);
      expect(element).toHaveStyle({ display: 'block' });
      expect(element).toHaveStyle({ overflowWrap: 'anywhere' });
    });
  });
});
