import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PaymentSettlementsPanel, { SettlementsTab } from './PaymentSettlementsPanel';
import { settlementLines } from './settlementLines.testHelper';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

/**
 * Feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-612, CA-3 e CA-5, D2, D3 e D4): as duas
 * abas de Pagamentos usam o arranjo "route" da linha de acerto (sem "deve pagar" nem o "a"); o comprovante
 * aguardando o aceite continua em "Valores pagos"; o selo do credor e os demais selos seguem. Arquivo próprio:
 * `PaymentSettlementsPanel.test.tsx` e `.responsible.test.tsx` só trocam a leitura da frase pelo helper.
 */

// A logada é a Carla (3).
const balances: SummaryBalance[] = [
  { user_id: 1, name: 'Ana', balance: 0 },
  { user_id: 2, name: 'Bruno', balance: 0 },
  { user_id: 3, name: 'Carla', balance: 0 },
  { user_id: 4, name: 'Diego', balance: 0 }
];

const SENT_AT = '2026-10-20T10:00:00-03:00';
const make = (from: number, to: number, amount: number, extra: Partial<SummarySettlement> = {}): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null,
  ...extra
});

const settlements: SummarySettlement[] = [
  make(3, 1, 60), // Carla → Ana: a pagar, saída da logada
  make(4, 3, 40), // Diego → Carla: a pagar, a logada recebe
  make(2, 3, 25, { confirmedAt: SENT_AT, confirmedProofUrl: 'http://api/proofs/1' }), // Bruno → Carla: pago a ela
  // Diego → Ana: o Bruno enviou o comprovante e a Ana (credora) ainda não aceitou.
  make(4, 1, 90, {
    confirmedAt: SENT_AT,
    confirmedProofUrl: 'http://api/proofs/2',
    confirmedBy: { id: 2, name: 'Bruno' },
    confirmationStatus: 'awaiting_acceptance'
  })
];

const noop = () => {};

function renderPanel(tab: SettlementsTab) {
  return render(
    <PaymentSettlementsPanel
      settlements={settlements}
      balances={balances}
      currentUserId={3}
      canConfirm
      cycleSettled={false}
      tab={tab}
      onTabChange={noop}
      onPayWithPix={noop}
      onSendProof={noop}
    />
  );
}

const panel = () => screen.getByRole('tabpanel');
const sorted = (lines: string[]) => [...lines].sort();

describe('PaymentSettlementsPanel: o arranjo "route" nas duas abas', () => {
  it('"Valores a pagar": cada acerto é "Pagador → Credor R$ N", sem "deve pagar" e sem o "a"', () => {
    renderPanel('toPay');

    expect(sorted(settlementLines(panel()))).toEqual(['Carla → Ana R$ 60,00', 'Diego → Carla R$ 40,00']);
    expect(panel()).not.toHaveTextContent(/deve pagar/);
    expect(panel()).not.toHaveTextContent(/R\$ \d+,\d{2}\s+a\s/);
  });

  it('"Valores pagos": o mesmo arranjo', () => {
    renderPanel('paid');

    expect(sorted(settlementLines(panel()))).toEqual(['Bruno → Carla R$ 25,00', 'Diego → Ana R$ 90,00']);
    expect(panel()).not.toHaveTextContent(/deve pagar/);
  });

  it('keeps the proof awaiting acceptance under "Valores pagos" (D2), never under "Valores a pagar"', () => {
    const toPay = renderPanel('toPay');
    expect(settlementLines(panel())).not.toContain('Diego → Ana R$ 90,00');
    toPay.unmount();

    renderPanel('paid');
    expect(settlementLines(panel())).toContain('Diego → Ana R$ 90,00');
    expect(within(panel()).getByText('Aguardando aceite do credor')).toBeInTheDocument();
  });

  it('keeps the creditor chip "Deve pagar a você" in "Valores a pagar" and "Pagou a você" in "Valores pagos" (D4)', () => {
    const toPay = renderPanel('toPay');
    expect(within(panel()).getAllByText('Deve pagar a você')).toHaveLength(1);
    toPay.unmount();

    renderPanel('paid');
    expect(within(panel()).getAllByText('Pagou a você')).toHaveLength(1);
  });

  it('shows the same buttons and chips as before on each card (R4)', () => {
    renderPanel('toPay');

    // A saída da logada tem os botões do devedor; a que ela recebe, só o selo.
    expect(within(panel()).getAllByRole('button', { name: 'Enviar comprovante' })).toHaveLength(1);
    expect(within(panel()).getAllByRole('button', { name: /Pagar com Pix/ })).toHaveLength(1);
  });
});
