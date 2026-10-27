import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import SummarySidePanel from './SummarySidePanel';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

// Saldos do specify §5.2 (somam zero), entregues fora de qualquer ordem útil.
const balances: SummaryBalance[] = [
  { user_id: 6, name: 'Fábio', balance: -105 },
  { user_id: 1, name: 'Ana', balance: 90 },
  { user_id: 4, name: 'Diego', balance: 0 },
  { user_id: 3, name: 'Carla', balance: -30 },
  { user_id: 2, name: 'Bruno', balance: 90 },
  { user_id: 5, name: 'Eva', balance: -45 }
];

// Já vêm do servidor em uma ordem própria (Eva antes de Diego, que deve mais): a aba "À pagar" não reordena.
const settlements: SummarySettlement[] = [
  { from_user_id: 5, to_user_id: 2, amount: 70, confirmedProofUrl: null, confirmedAt: null },
  { from_user_id: 4, to_user_id: 1, amount: 90, confirmedProofUrl: null, confirmedAt: null }
];

const NAMES = /^(Ana|Bruno|Carla|Diego|Eva|Fábio)$/;
const shownOrder = () => screen.getAllByText(NAMES).map(el => el.textContent);

describe('SummarySidePanel - aba Saldo', () => {
  it('põe a logada com saldo negativo primeiro e ordena os demais do maior saldo para o menor', () => {
    render(<SummarySidePanel balances={balances} settlements={[]} cycleStatus="open" currentUserId={3} />);

    expect(shownOrder()).toEqual(['Carla', 'Ana', 'Bruno', 'Diego', 'Eva', 'Fábio']);
  });

  it('põe a logada com saldo positivo primeiro; os negativos seguem do menos negativo ao mais negativo', () => {
    render(<SummarySidePanel balances={balances} settlements={[]} cycleStatus="open" currentUserId={1} />);

    expect(shownOrder()).toEqual(['Ana', 'Bruno', 'Diego', 'Carla', 'Eva', 'Fábio']);
  });

  it.each([
    ['sem currentUserId', undefined],
    ['com currentUserId nulo', null],
    ['com a logada fora da lista', 99]
  ])('usa só a regra dos demais %s, sem erro', (_caso, currentUserId) => {
    render(<SummarySidePanel balances={balances} settlements={[]} cycleStatus="open" currentUserId={currentUserId} />);

    expect(shownOrder()).toEqual(['Ana', 'Bruno', 'Diego', 'Carla', 'Eva', 'Fábio']);
  });

  it('mantém o valor e o rótulo "a receber" / "a pagar" de cada saldo', () => {
    render(<SummarySidePanel balances={balances} settlements={[]} cycleStatus="open" currentUserId={3} />);

    expect(screen.getAllByText('R$ 90,00 a receber')).toHaveLength(2);
    expect(screen.getByText('R$ 30,00 a pagar')).toBeInTheDocument();
    expect(screen.getByText('R$ 45,00 a pagar')).toBeInTheDocument();
    expect(screen.getByText('R$ 105,00 a pagar')).toBeInTheDocument();
    expect(screen.getByText('R$ 0,00')).toBeInTheDocument();
  });
});

describe('SummarySidePanel - aba À pagar', () => {
  it('lista os acertos na ordem recebida, sem reordenar (a ordem dos acertos é de Pagamentos)', async () => {
    const user = userEvent.setup();
    render(<SummarySidePanel balances={balances} settlements={settlements} cycleStatus="open" currentUserId={3} />);

    await user.click(screen.getByRole('tab', { name: 'À pagar' }));

    expect(screen.getAllByText(/deve pagar/).map(el => el.textContent)).toEqual([
      'Eva deve pagar R$ 70,00 a Bruno',
      'Diego deve pagar R$ 90,00 a Ana'
    ]);
  });
});
