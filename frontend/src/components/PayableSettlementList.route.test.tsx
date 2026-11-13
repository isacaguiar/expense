import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PayableSettlementList from './PayableSettlementList';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

/**
 * Feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-611, CA-3 e CA-5): o arranjo "route" da
 * linha de acerto (`rowLayout="route"`): os dois avatares nas pontas, "pagador → credor" numa linha e o valor
 * abaixo, sem "deve pagar" nem o "a". O padrão (`'sentence'`) é a linha de hoje e não muda uma linha. Arquivo
 * próprio: `PayableSettlementList.test.tsx`, `.decision.test.tsx` e `.responsible.test.tsx` não são editados.
 */

const ANA = 'Ana Paula Rodrigues';

const balances: SummaryBalance[] = [
  { user_id: 1, name: ANA, balance: -100 },
  { user_id: 2, name: 'Carla', balance: 100 },
  { user_id: 3, name: 'Bruno', balance: 0 }
];

const base: SummarySettlement = { from_user_id: 1, to_user_id: 2, amount: 100, confirmedProofUrl: null, confirmedAt: null };

const noop = () => {};

type Props = React.ComponentProps<typeof PayableSettlementList>;

function renderList(props: Partial<Props> = {}) {
  return render(
    <PayableSettlementList
      settlements={[base]}
      balances={balances}
      currentUserId={1}
      canConfirm
      cycleSettled={false}
      onPayWithPix={noop}
      onSendProof={noop}
      {...props}
    />
  );
}

const follows = (a: Node, b: Node) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);

describe('PayableSettlementList: arranjo "route"', () => {
  it('shows payer, payee and amount, with no "deve pagar" and no "a" between the amount and the payee', () => {
    renderList({ rowLayout: 'route' });

    const card = screen.getByTestId('settlement-card');
    expect(within(card).getByTestId('settlement-payer')).toHaveTextContent(/^Ana Paula Rodrigues$/);
    expect(within(card).getByTestId('settlement-payee')).toHaveTextContent(/^Carla$/);
    expect(within(card).getByTestId('settlement-amount')).toHaveTextContent(/^R\$ 100,00$/);

    expect(card).not.toHaveTextContent(/deve pagar/i);
    expect(card).not.toHaveTextContent(/R\$ 100,00\s+a\s/);
  });

  it('puts payer, payee and then the amount in that order, between the two avatars', () => {
    renderList({ rowLayout: 'route' });

    const card = screen.getByTestId('settlement-card');
    const payer = within(card).getByTestId('settlement-payer');
    const payee = within(card).getByTestId('settlement-payee');
    const amount = within(card).getByTestId('settlement-amount');
    const payerAvatar = within(card).getByLabelText(ANA);
    const payeeAvatar = within(card).getByLabelText('Carla');

    expect(follows(payerAvatar, payer)).toBe(true);
    expect(follows(payer, payee)).toBe(true);
    expect(follows(payee, amount)).toBe(true);
    expect(follows(amount, payeeAvatar)).toBe(true);
  });

  it('draws one arrow only, right after the payer and before the payee, and no loose arrow', () => {
    renderList({ rowLayout: 'route' });

    const card = screen.getByTestId('settlement-card');
    const arrows = card.querySelectorAll('[data-testid="ArrowForwardIcon"]');
    expect(arrows).toHaveLength(1);

    const payer = within(card).getByTestId('settlement-payer');
    const payee = within(card).getByTestId('settlement-payee');
    // A seta é irmã do pagador (não se separam ao quebrar a linha) e fica antes do credor.
    expect(payer.nextElementSibling).toBe(arrows[0]);
    expect(follows(arrows[0], payee)).toBe(true);
  });

  it('shows the amount in bold red and the names in bold', () => {
    renderList({ rowLayout: 'route' });

    const card = screen.getByTestId('settlement-card');
    expect(within(card).getByTestId('settlement-amount')).toHaveStyle({ fontWeight: '700', color: 'rgb(211, 47, 47)' });
    expect(within(card).getByTestId('settlement-payer')).toHaveStyle({ fontWeight: '700' });
    expect(within(card).getByTestId('settlement-payee')).toHaveStyle({ fontWeight: '700' });
  });

  it('has one card, with its own anchors, per settlement', () => {
    renderList({
      rowLayout: 'route',
      settlements: [base, { ...base, from_user_id: 3, amount: 25.5 }]
    });

    expect(screen.getAllByTestId('settlement-card')).toHaveLength(2);
    expect(screen.getAllByTestId('settlement-payer').map(el => el.textContent)).toEqual([ANA, 'Bruno']);
    expect(screen.getAllByTestId('settlement-amount').map(el => el.textContent)).toEqual(['R$ 100,00', 'R$ 25,50']);
  });
});

describe('PayableSettlementList: arranjo "sentence" (o padrão) segue como hoje', () => {
  it.each([
    ['sem a propriedade', {}],
    ['com rowLayout="sentence"', { rowLayout: 'sentence' as const }]
  ])('keeps the sentence "X deve pagar R$ N a Y", the loose arrow and no anchors %s', (_label, props) => {
    const { container } = renderList(props);

    expect(screen.getByText(/deve pagar/).textContent).toBe('Ana Paula Rodrigues deve pagar R$ 100,00 a Carla');
    expect(container.querySelectorAll('[data-testid="ArrowForwardIcon"]')).toHaveLength(1);
    expect(screen.queryByTestId('settlement-card')).not.toBeInTheDocument();
    expect(screen.queryByTestId('settlement-payer')).not.toBeInTheDocument();
    expect(screen.queryByTestId('settlement-payee')).not.toBeInTheDocument();
    expect(screen.queryByTestId('settlement-amount')).not.toBeInTheDocument();
  });
});

describe('PayableSettlementList: selos e botões iguais nos dois arranjos (CA-5)', () => {
  const confirmedBy = { id: 3, name: 'Bruno' };
  const sent = { confirmedAt: '2026-10-20T10:00:00-03:00', confirmedProofUrl: 'http://api/proofs/9', confirmedNote: 'Pix feito' };
  const third = { ...sent, confirmedBy };

  type Case = [string, Partial<Props>];
  const cases: Case[] = [
    ['devedor sem comprovante', {}],
    ['devedor com o próprio comprovante e nota', { settlements: [{ ...base, ...sent }] }],
    ['devedor com o ciclo ainda sem fechar', { canConfirm: false }],
    ['credor sem comprovante', { currentUserId: 2 }],
    [
      'credor com comprovante aguardando o aceite',
      { currentUserId: 2, settlements: [{ ...base, ...third, confirmationStatus: 'awaiting_acceptance' }], onAccept: noop, onRefuse: noop }
    ],
    [
      'devedor com comprovante aguardando o aceite',
      { settlements: [{ ...base, ...third, confirmationStatus: 'awaiting_acceptance' }], onDelete: noop }
    ],
    [
      'quem enviou, com o comprovante aguardando o aceite',
      { currentUserId: 3, payForUserIds: [1], settlements: [{ ...base, ...third, confirmationStatus: 'awaiting_acceptance' }], onDelete: noop }
    ],
    ['credor com comprovante recusado', { currentUserId: 2, settlements: [{ ...base, confirmedBy, confirmationStatus: 'refused', refusedAt: '2026-10-21T10:00:00-03:00' }] }],
    [
      'devedor com comprovante recusado',
      { settlements: [{ ...base, confirmedBy, confirmationStatus: 'refused', refusedAt: '2026-10-21T10:00:00-03:00' }], onDelete: noop }
    ],
    ['comprovante aceito', { settlements: [{ ...base, ...third, confirmationStatus: 'accepted' }] }],
    ['quem paga pelo devedor', { currentUserId: 3, payForUserIds: [1] }]
  ];

  // Chips, botões e legendas do cartão, na ordem em que aparecem: tudo o que não é o topo.
  const controls = (container: HTMLElement) => ({
    chips: [...container.querySelectorAll('.MuiChip-label')].map(el => el.textContent),
    buttons: [...container.querySelectorAll('button')].map(el => el.textContent),
    captions: [...container.querySelectorAll('.MuiTypography-caption')].map(el => el.textContent)
  });

  it.each(cases)('%s: mesmos selos, botões e legendas', (_label, props) => {
    const sentence = renderList({ ...props, rowLayout: 'sentence' });
    const expected = controls(sentence.container);
    sentence.unmount();

    const route = renderList({ ...props, rowLayout: 'route' });

    expect(controls(route.container)).toEqual(expected);
    // E o dataset não é vazio de propósito: todo caso mostra ao menos um botão, selo ou legenda.
    expect(expected.chips.length + expected.buttons.length + expected.captions.length).toBeGreaterThan(0);
  });
});
