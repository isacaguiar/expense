import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MemberResponsible } from '../types/group';
import Payments from './Payments';

/**
 * Feature 20261109-responsavel-do-devedor (TASK-592, CA-15): a tela de Pagamentos oferece o selo e os
 * botões nos acertos de quem o usuário paga, manda `from_user_id` só ao pagar por um dependente,
 * mostra "Enviado por {nome}" e põe as saídas de quem ele paga logo depois das dele. Arquivo próprio:
 * `Payments.test.tsx` não é editado.
 */

vi.mock('axios');

// O `navigate` tem de ser estável: `useGroupCycle` o usa nas dependências de um efeito, e uma função nova
// a cada render faria a tela buscar o ciclo de novo sem parar.
const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock, useParams: () => ({ id: '1' }) };
});

const ME = 700;
const JOAO = 501; // dependente: o usuário paga por ele
const PEDRO = 502; // terceiro: o usuário não paga por ele
const MARIA = 999; // credora

const ISAC: MemberResponsible = { id: ME, name: 'Isac' };

type SettlementFixture = {
  from_user_id: number;
  to_user_id: number;
  amount: number;
  confirmedAt?: string | null;
  confirmedProofUrl?: string | null;
  confirmedBy?: MemberResponsible | null;
};

type MemberFixture = {
  id: number;
  name: string;
  email: string;
  pix: string | null;
  avatar_url: null;
  responsibles?: MemberResponsible[];
};

const member = (id: number, name: string, responsibles?: MemberResponsible[]): MemberFixture => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  pix: null,
  avatar_url: null,
  ...(responsibles === undefined ? {} : { responsibles })
});

const MEMBERS = [member(ME, 'Isac', []), member(JOAO, 'João', [ISAC]), member(PEDRO, 'Pedro', []), member(MARIA, 'Maria', [])];

const BALANCES = [
  { user_id: ME, name: 'Isac', balance: 0 },
  { user_id: JOAO, name: 'João', balance: 0 },
  { user_id: PEDRO, name: 'Pedro', balance: 0 },
  { user_id: MARIA, name: 'Maria', balance: 0 }
];

function mockApi(settlements: SettlementFixture[], members: MemberFixture[] = MEMBERS) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({
        data: {
          cycle: { start: '2026-08-01', end: '2026-08-31', closes_at: '2026-09-05', status: 'closed', settled: false },
          totals: { total: 0, paid: 0, pending: 0 },
          expenses: [],
          balances: BALANCES,
          settlements
        }
      });
    }
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: ME } });
    if (url.includes('/members')) return Promise.resolve({ data: members });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderPayments() {
  render(
    <MemoryRouter>
      <Payments />
    </MemoryRouter>
  );
}

const SEND = { name: 'Enviar comprovante' };

/** Abre o popup do comprovante, anexa um arquivo e confirma. */
async function sendProofOnTheOnlyButton(user: ReturnType<typeof userEvent.setup>): Promise<File> {
  await user.click(await screen.findByRole('button', SEND));
  const confirm = await screen.findByRole('button', { name: 'Confirmar' });
  const file = new File(['foto'], 'pix.png', { type: 'image/png' });
  await user.upload(document.querySelector('input[type="file"]') as HTMLInputElement, file);
  await user.click(confirm);
  return file;
}

describe('Payments: pagar por quem o usuário paga', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
  });

  it('offers the badge and the buttons on the dependent settlement, and nothing on the third party one', async () => {
    mockApi([
      { from_user_id: JOAO, to_user_id: MARIA, amount: 50 },
      { from_user_id: PEDRO, to_user_id: MARIA, amount: 40 }
    ]);
    renderPayments();

    expect(await screen.findByText('Você paga por João')).toBeInTheDocument();
    expect(screen.queryByText('Você paga por Pedro')).not.toBeInTheDocument();
    expect(screen.getAllByRole('button', SEND)).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: 'Pagar com Pix' })).toHaveLength(1);
  });

  it('sends from_user_id with the dependent id when paying for the dependent', async () => {
    const user = userEvent.setup();
    mockApi([{ from_user_id: JOAO, to_user_id: MARIA, amount: 50 }]);
    renderPayments();

    const file = await sendProofOnTheOnlyButton(user);

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toContain('/api/groups/1/settlements/confirm');
    const form = body as FormData;
    expect(form.get('from_user_id')).toBe(String(JOAO));
    expect(form.get('to_user_id')).toBe(String(MARIA));
    expect(form.get('comprovante')).toBe(file);
    expect(form.get('cycles_ago')).toBe('0');
    expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
  });

  it('does not send from_user_id when paying for oneself, even if the user pays for someone', async () => {
    const user = userEvent.setup();
    mockApi([{ from_user_id: ME, to_user_id: MARIA, amount: 50 }]);
    renderPayments();

    await sendProofOnTheOnlyButton(user);

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    const form = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(form.has('from_user_id')).toBe(false);
    expect(form.get('to_user_id')).toBe(String(MARIA));
  });

  it('offers no button on the settlement the user receives from the dependent (the user is the creditor)', async () => {
    mockApi([{ from_user_id: JOAO, to_user_id: ME, amount: 50 }]);
    renderPayments();

    expect(await screen.findByText('Deve pagar a você')).toBeInTheDocument();
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pagar com Pix' })).not.toBeInTheDocument();
  });

  it('offers nothing for others when the members come without the responsibles field', async () => {
    mockApi(
      [{ from_user_id: JOAO, to_user_id: MARIA, amount: 50 }],
      MEMBERS.map(({ responsibles: _ignored, ...rest }) => rest)
    );
    renderPayments();

    await screen.findByText(/deve pagar/);
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
  });

  it('does not offer the buttons when someone else is the one who pays for the dependent', async () => {
    mockApi(
      [{ from_user_id: JOAO, to_user_id: MARIA, amount: 50 }],
      MEMBERS.map(m => (m.id === JOAO ? { ...m, responsibles: [{ id: PEDRO, name: 'Pedro' }] } : m))
    );
    renderPayments();

    await screen.findByText(/deve pagar/);
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', SEND)).not.toBeInTheDocument();
  });

  it('shows the own outgoing first, then the ones the user pays for, then the others, however large', async () => {
    mockApi([
      { from_user_id: PEDRO, to_user_id: MARIA, amount: 900 },
      { from_user_id: JOAO, to_user_id: MARIA, amount: 10 },
      { from_user_id: ME, to_user_id: MARIA, amount: 5 }
    ]);
    renderPayments();

    await screen.findByText('Você paga por João');
    const lines = screen.getAllByText(/deve pagar/).map(line => line.textContent);

    expect(lines).toEqual([
      expect.stringContaining('Isac deve pagar'),
      expect.stringContaining('João deve pagar'),
      expect.stringContaining('Pedro deve pagar')
    ]);
  });

  it('shows "Enviado por {nome}" on the paid tab when someone else sent the proof', async () => {
    const user = userEvent.setup();
    mockApi([
      {
        from_user_id: JOAO,
        to_user_id: MARIA,
        amount: 50,
        confirmedAt: '2026-08-20T10:00:00-03:00',
        confirmedProofUrl: 'http://api/proofs/9',
        confirmedBy: ISAC
      }
    ]);
    renderPayments();

    await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));

    const panel = within(screen.getByRole('tabpanel'));
    expect(await panel.findByText('Comprovante enviado')).toBeInTheDocument();
    expect(panel.getByText('Enviado por Isac')).toBeInTheDocument();
  });
});
