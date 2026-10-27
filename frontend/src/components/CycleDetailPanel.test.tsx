import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CycleDetailPanel from './CycleDetailPanel';
import type { Summary } from '../hooks/useGroupCycle';

const summary: Summary = {
  cycle: { start: '2026-09-01', end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false },
  totals: { total: 1234.5, paid: 300, pending: 934.5 },
  expenses: [],
  balances: [],
  settlements: [],
};

describe('CycleDetailPanel', () => {
  // `GroupReports` e `GroupSummary` só conferem os valores; o período dos
  // cartões é o que prova que `summary.cycle` chega a `CycleTotalsCards`.
  it('hands the totals and the period of the summary to the totals cards', () => {
    render(<CycleDetailPanel summary={summary} />);

    const totalCard = screen.getByText('Total de despesas').closest('.MuiCard-root');
    if (!(totalCard instanceof HTMLElement)) throw new Error('Cartão de total não encontrado');

    expect(within(totalCard).getByText('R$ 1.234,50')).toBeInTheDocument();
    expect(within(totalCard).getByText(/^01 de set\.? – 30 de set\.?$/)).toBeInTheDocument();
    expect(screen.getByText('R$ 300,00')).toBeInTheDocument();
    expect(screen.getByText('R$ 934,50')).toBeInTheDocument();
  });

  it('keeps the cycle status chip above the totals', () => {
    render(<CycleDetailPanel summary={summary} />);

    const chip = screen.getByText('Ciclo fechado');
    const totals = screen.getByText('Total de despesas');
    expect(chip.compareDocumentPosition(totals) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  describe('lista de despesas', () => {
    const despesa = (id: number, description: string, date: string) => ({
      id,
      description,
      date,
      value: 100,
      valuePerPerson: 50,
      paid: false,
      paymentProofUrl: null,
      payerName: 'Ana',
      participants: ['Ana', 'Bruno'],
      isFixed: false,
      userPayerId: 1,
      userCreatorId: 1
    });

    it('mostra as despesas por data da ocorrência, da mais recente para a mais antiga, com empate pelo id', () => {
      const withExpenses: Summary = {
        ...summary,
        // Chegam como o backend entrega: pendentes primeiro, data crescente.
        expenses: [
          despesa(1, 'Aluguel', '2026-09-05'),
          despesa(3, 'Luz', '2026-09-10'),
          despesa(2, 'Água', '2026-09-10')
        ]
      };

      render(<CycleDetailPanel summary={withExpenses} />);

      expect(screen.getAllByText(/^(Aluguel|Luz|Água) — R\$/).map(item => item.textContent?.split(' — ')[0])).toEqual([
        'Luz',
        'Água',
        'Aluguel'
      ]);
    });
  });

  describe('aba Saldo', () => {
    const withBalances: Summary = {
      ...summary,
      balances: [
        { user_id: 1, name: 'Ana', balance: 50 },
        { user_id: 2, name: 'Bruno', balance: -50 }
      ]
    };
    const shownOrder = () => screen.getAllByText(/^(Ana|Bruno)$/).map(el => el.textContent);

    it('põe primeiro o usuário logado recebido em currentUserId', () => {
      render(<CycleDetailPanel summary={withBalances} currentUserId={2} />);

      expect(shownOrder()).toEqual(['Bruno', 'Ana']);
    });

    it('sem currentUserId mantém só a regra dos demais (maior saldo primeiro)', () => {
      render(<CycleDetailPanel summary={withBalances} />);

      expect(shownOrder()).toEqual(['Ana', 'Bruno']);
    });
  });
});
