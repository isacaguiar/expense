import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MemberResponsible } from '../types/group';
import Payments from './Payments';

/**
 * TASK-635 (feature 20261114-historico-de-comprovantes-e-justificativa, plan §12): em Pagamentos, quem paga
 * pelo devedor abre "Adicionar justificativa", envia a foto e a justificativa, a tela avisa "Justificativa e
 * comprovante enviados." e recarrega o ciclo; a mensagem do servidor aparece no diálogo quando falha.
 */

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock, useParams: () => ({ id: '1' }) };
});

const ME = 700; // quem paga por João
const JOAO = 501;
const MARIA = 999;
const ISAC: MemberResponsible = { id: ME, name: 'Isac' };

const sentProof = {
  from_user_id: JOAO,
  to_user_id: MARIA,
  amount: 50,
  confirmedAt: '2026-08-20T10:00:00-03:00',
  confirmedProofUrl: 'http://x/p.jpg',
  confirmedBy: null,
  confirmationStatus: 'sent',
  refusedAt: null
};

const member = (id: number, name: string, responsibles: MemberResponsible[] = []) => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  pix: null,
  avatar_url: null,
  responsibles
});

const BALANCES = [
  { user_id: ME, name: 'Isac', balance: 0 },
  { user_id: JOAO, name: 'João', balance: 0 },
  { user_id: MARIA, name: 'Maria', balance: 0 }
];

function mockApi() {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/summary')) {
      return Promise.resolve({
        data: {
          cycle: { start: '2026-08-01', end: '2026-08-31', closes_at: '2026-09-05', status: 'closed', settled: false },
          totals: { total: 0, paid: 0, pending: 0 },
          expenses: [],
          balances: BALANCES,
          settlements: [sentProof]
        }
      });
    }
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: ME } });
    if (url.includes('/members')) return Promise.resolve({ data: [member(ME, 'Isac'), member(JOAO, 'João', [ISAC]), member(MARIA, 'Maria')] });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

const summaryLoads = () => vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/expenses/summary')).length;

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  render(
    <MemoryRouter>
      <Payments />
    </MemoryRouter>
  );
  await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
  await user.click(await screen.findByRole('button', { name: 'Adicionar justificativa e comprovante' }));
  return screen.findByRole('dialog', { name: 'Adicionar justificativa' });
}

async function fillAndSend(user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement) {
  await user.upload(dialog.querySelector('input[type="file"]') as HTMLInputElement, new File(['x'], 'prova.jpg', { type: 'image/jpeg' }));
  await user.type(within(dialog).getByLabelText('Justificativa'), 'o pix foi de outra conta');
  await user.click(within(dialog).getByRole('button', { name: 'Enviar' }));
}

describe('Payments: complemento do comprovante', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  it('opens the dialog naming the debtor, and nothing is sent before "Enviar"', async () => {
    mockApi();
    const user = userEvent.setup();

    const dialog = await openDialog(user);

    expect(within(dialog).getByText(/Use quando o comprovante enviado por João está errado ou incompleto/)).toBeInTheDocument();
    expect(axios.post).not.toHaveBeenCalled();
  });

  it('sends the pair, cycles_ago, photo and justification, confirms and reloads the cycle', async () => {
    mockApi();
    const user = userEvent.setup();
    const dialog = await openDialog(user);
    const loadsBefore = summaryLoads();

    await fillAndSend(user, dialog);

    expect(await screen.findByText('Justificativa e comprovante enviados.')).toBeInTheDocument();
    const [url, body] = vi.mocked(axios.post).mock.calls[0] as unknown as [string, FormData];
    expect(url).toMatch(/\/api\/groups\/1\/settlements\/complement$/);
    expect(body.get('from_user_id')).toBe(String(JOAO));
    expect(body.get('to_user_id')).toBe(String(MARIA));
    expect(body.get('cycles_ago')).toBe('0');
    expect(body.get('justificativa')).toBe('o pix foi de outra conta');
    await waitFor(() => expect(summaryLoads()).toBeGreaterThan(loadsBefore));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Adicionar justificativa' })).not.toBeInTheDocument());
  });

  it('keeps the dialog open and shows the server message when it fails', async () => {
    mockApi();
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 422, data: { error: 'O limite de comprovantes e justificativas de quem paga por esta pessoa foi atingido.' } } });
    const user = userEvent.setup();
    const dialog = await openDialog(user);

    await fillAndSend(user, dialog);

    expect(await within(dialog).findByText('O limite de comprovantes e justificativas de quem paga por esta pessoa foi atingido.')).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Adicionar justificativa' })).toBeInTheDocument();
    expect(screen.queryByText('Justificativa e comprovante enviados.')).not.toBeInTheDocument();
  });

  it('cancelling closes the dialog without sending', async () => {
    mockApi();
    const user = userEvent.setup();
    const dialog = await openDialog(user);

    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Adicionar justificativa' })).not.toBeInTheDocument());
    expect(axios.post).not.toHaveBeenCalled();
  });
});
