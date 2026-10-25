import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CycleTotalsCards from './CycleTotalsCards';

const cycle = { start: '2026-09-01', end: '2026-09-30' };

// Escopa a busca ao cartão do rótulo, para "R$ 300,00" não valer em outro cartão.
const cardOf = (label: string): HTMLElement => {
  const card = screen.getByText(label).closest('.MuiCard-root');
  if (!(card instanceof HTMLElement)) throw new Error(`Cartão "${label}" não encontrado`);
  return card;
};

describe('CycleTotalsCards', () => {
  it('shows the three cards in order: total, paid and to pay', () => {
    render(<CycleTotalsCards totals={{ total: 1000, paid: 400, pending: 600 }} cycle={cycle} />);

    const labels = ['Total de despesas', 'Pago', 'A pagar'].map(label => screen.getByText(label));
    expect(labels).toHaveLength(3);
    expect(labels[0].compareDocumentPosition(labels[1]) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(labels[1].compareDocumentPosition(labels[2]) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('formats each amount in pt-BR inside its own card', () => {
    render(<CycleTotalsCards totals={{ total: 1234.5, paid: 300, pending: 934.5 }} cycle={cycle} />);

    expect(within(cardOf('Total de despesas')).getByText('R$ 1.234,50')).toBeInTheDocument();
    expect(within(cardOf('Pago')).getByText('R$ 300,00')).toBeInTheDocument();
    expect(within(cardOf('A pagar')).getByText('R$ 934,50')).toBeInTheDocument();
  });

  it('shows the share of the total for paid and to pay, rounded', () => {
    render(<CycleTotalsCards totals={{ total: 1234.5, paid: 300, pending: 934.5 }} cycle={cycle} />);

    expect(within(cardOf('Pago')).getByText('24% do total')).toBeInTheDocument();
    expect(within(cardOf('A pagar')).getByText('76% do total')).toBeInTheDocument();
  });

  it('shows 0% instead of dividing by zero when the cycle has no expenses', () => {
    render(<CycleTotalsCards totals={{ total: 0, paid: 0, pending: 0 }} cycle={cycle} />);

    expect(within(cardOf('Total de despesas')).getByText('R$ 0,00')).toBeInTheDocument();
    expect(within(cardOf('Pago')).getByText('0% do total')).toBeInTheDocument();
    expect(within(cardOf('A pagar')).getByText('0% do total')).toBeInTheDocument();
  });

  it('shows the period under the total without shifting the day in a negative time zone', () => {
    render(<CycleTotalsCards totals={{ total: 10, paid: 0, pending: 10 }} cycle={cycle} />);

    // TZ do Vitest é America/Sao_Paulo: `new Date('2026-09-01')` cairia em 31/08.
    expect(within(cardOf('Total de despesas')).getByText(/^01 de set\.? – 30 de set\.?$/)).toBeInTheDocument();
  });
});
