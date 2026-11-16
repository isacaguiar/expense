import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { SettlementProofEntry, SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';
import PayableSettlementList from './PayableSettlementList';
import SettlementProofHistory from './SettlementProofHistory';

/**
 * TASK-633 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12, R7): a seção recolhível
 * "Histórico (n)" do acerto. Nota, justificativa e motivo saem como texto puro.
 */
const entry = (over: Partial<SettlementProofEntry> = {}): SettlementProofEntry => ({
  id: 1,
  kind: 'replaced',
  status: 'sent',
  sentBy: { id: 3, name: 'Maria' },
  amount: 100,
  note: null,
  refusalReason: null,
  sentAt: '2026-08-20T10:00:00-03:00',
  refusedAt: null,
  archivedAt: '2026-08-22T09:30:00-03:00',
  proofUrl: 'https://x/a.jpg',
  ...over
});

const open = async (entries: SettlementProofEntry[]) => {
  const user = userEvent.setup();
  const view = render(<SettlementProofHistory entries={entries} />);
  await user.click(screen.getByRole('button', { name: /Histórico \(/ }));
  return { user, ...view };
};

describe('SettlementProofHistory', () => {
  it('renders nothing for an empty list', () => {
    const { container } = render(<SettlementProofHistory entries={[]} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('starts collapsed: shows "Histórico (n)" and none of the entries', () => {
    render(<SettlementProofHistory entries={[entry({ note: 'minha nota' }), entry({ id: 2 })]} />);

    expect(screen.getByRole('button', { name: 'Histórico (2)' })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByText(/minha nota/)).not.toBeInTheDocument();
  });

  it('opens on click and lists the entries in the received order', async () => {
    await open([
      entry({ id: 1, kind: 'withdrawn', sentBy: { id: 3, name: 'Maria' } }),
      entry({ id: 2, kind: 'replaced', sentBy: { id: 4, name: 'Rita' } }),
      entry({ id: 3, kind: 'complement', status: null, sentBy: { id: 5, name: 'Pedro' }, note: 'foi o pix certo' })
    ]);

    const items = screen.getAllByRole('listitem');
    expect(items).toHaveLength(3);
    expect(within(items[0]).getByText('Retirado')).toBeInTheDocument();
    expect(within(items[1]).getByText('Substituído')).toBeInTheDocument();
    expect(within(items[2]).getByText('Justificativa')).toBeInTheDocument();
    expect(within(items[0]).getByText(/Maria/)).toBeInTheDocument();
    expect(within(items[2]).getByText(/Pedro/)).toBeInTheDocument();
  });

  it.each([
    ['sent', 'Estava: Enviado'],
    ['awaiting_acceptance', 'Estava: Aguardando aceite'],
    ['refused', 'Estava: Recusado']
  ] as const)('shows "%s" as "%s"', async (status, text) => {
    await open([entry({ status })]);

    expect(screen.getByText(text)).toBeInTheDocument();
  });

  it('never shows "Estava" on a complement', async () => {
    await open([entry({ kind: 'complement', status: null, note: 'justificativa' })]);

    expect(screen.queryByText(/Estava:/)).not.toBeInTheDocument();
  });

  it('shows the note, the refusal reason and the date', async () => {
    await open([entry({ status: 'refused', note: 'pix de agosto', refusalReason: 'foto cortada' })]);

    expect(screen.getByText('Nota: pix de agosto')).toBeInTheDocument();
    expect(screen.getByText('Motivo da recusa: foto cortada')).toBeInTheDocument();
    expect(screen.getByText(/\d{2}\/\d{2}\/\d{4}/)).toBeInTheDocument();
  });

  it('shows a complement note as "Justificativa: …"', async () => {
    await open([entry({ kind: 'complement', status: null, note: 'o pix foi de outra conta' })]);

    expect(screen.getByText('Justificativa: o pix foi de outra conta')).toBeInTheDocument();
  });

  it('links "Ver comprovante" only when the file exists', async () => {
    await open([entry({ id: 1, proofUrl: 'https://x/a.jpg' }), entry({ id: 2, proofUrl: null })]);

    const links = screen.getAllByRole('link', { name: 'Ver comprovante' });
    expect(links).toHaveLength(1);
    expect(links[0]).toHaveAttribute('href', 'https://x/a.jpg');
    expect(links[0]).toHaveAttribute('target', '_blank');
    expect(links[0]).toHaveAttribute('rel', 'noreferrer');
  });

  it('names a removed account instead of crashing', async () => {
    await open([entry({ sentBy: null })]);

    expect(screen.getByText(/conta removida/i)).toBeInTheDocument();
  });

  it('renders note, justification and reason as plain text', async () => {
    const { container } = await open([
      entry({ note: '<i>x</i> https://a.com/n', refusalReason: '<b>y</b> https://b.com/m' })
    ]);

    expect(screen.getByText('Nota: <i>x</i> https://a.com/n')).toBeInTheDocument();
    expect(container.querySelector('i, b')).toBeNull();
    expect(container.querySelector('a[href="https://a.com/n"], a[href="https://b.com/m"]')).toBeNull();
  });

  it('lets long text wrap', async () => {
    await open([entry({ note: 'n'.repeat(500) })]);

    expect(screen.getByText(`Nota: ${'n'.repeat(500)}`)).toHaveStyle({ overflowWrap: 'anywhere' });
  });
});

describe('PayableSettlementList with the history', () => {
  const BALANCES: SummaryBalance[] = [
    { user_id: 10, name: 'Joana', balance: 0 },
    { user_id: 20, name: 'Carlos', balance: 0 }
  ];
  const settlement = (proofHistory?: SettlementProofEntry[]): SummarySettlement => ({
    from_user_id: 10,
    to_user_id: 20,
    amount: 100,
    confirmedAt: null,
    confirmedProofUrl: null,
    ...(proofHistory === undefined ? {} : { proofHistory })
  });
  const show = (s: SummarySettlement) =>
    render(
      <PayableSettlementList
        settlements={[s]}
        balances={BALANCES}
        currentUserId={10}
        canConfirm
        cycleSettled={false}
        onPayWithPix={vi.fn()}
        onSendProof={vi.fn()}
      />
    );

  it('shows the history section only when proofHistory has entries', () => {
    show(settlement([entry(), entry({ id: 2 })]));

    expect(screen.getByRole('button', { name: 'Histórico (2)' })).toBeInTheDocument();
  });

  it.each([undefined, []])('shows nothing when proofHistory is %j', proofHistory => {
    show(settlement(proofHistory as SettlementProofEntry[] | undefined));

    expect(screen.queryByRole('button', { name: /Histórico/ })).not.toBeInTheDocument();
  });
});
