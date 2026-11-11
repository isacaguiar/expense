import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MemberResponsible } from '../types/group';
import Payments from './Payments';

/**
 * Feature 20261110-quem-paga-se-declara-e-credor-recusa (TASK-608, plan §11): em Pagamentos o credor aceita
 * ou recusa o comprovante enviado por responsável, quem enviou (ou o devedor) o exclui, a tela recarrega o
 * ciclo depois de cada ação e mostra a mensagem do servidor quando falha. Arquivo próprio: `Payments.test.tsx`
 * e `Payments.responsible.test.tsx` não são editados.
 */

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock, useParams: () => ({ id: '1' }) };
});

const ME = 700;
const JOAO = 501; // devedor
const MARIA = 999; // credora
const MARIA_M: MemberResponsible = { id: MARIA, name: 'Maria' };
const ISAC: MemberResponsible = { id: ME, name: 'Isac' };

type State = 'awaiting_acceptance' | 'refused';

const settlementIn = (state: State, from: number, to: number) => ({
  from_user_id: from,
  to_user_id: to,
  amount: 50,
  confirmedAt: state === 'refused' ? null : '2026-08-20T10:00:00-03:00',
  confirmedProofUrl: state === 'refused' ? null : 'http://x/p.jpg',
  confirmedBy: ISAC,
  confirmationStatus: state,
  refusedAt: state === 'refused' ? '2026-08-20T11:00:00-03:00' : null
});

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

/** O usuário logado é `me`; o ciclo de agosto/2026 está fechado e não selado. */
function mockApi(me: number, settlements: ReturnType<typeof settlementIn>[]) {
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
    if (url.includes('/api/me')) return Promise.resolve({ data: { id: me } });
    if (url.includes('/members')) return Promise.resolve({ data: [member(ME, 'Isac'), member(JOAO, 'João', [ISAC]), member(MARIA, 'Maria')] });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

const summaryLoads = () => vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/expenses/summary')).length;

function renderPayments() {
  render(
    <MemoryRouter>
      <Payments />
    </MemoryRouter>
  );
}

async function openPaidTab(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('tab', { name: /Valores pagos/ }));
}

describe('Payments: aceitar, recusar e excluir o comprovante', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.mocked(axios.delete).mockResolvedValue({ data: {} });
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  it('the creditor accepts: posts the pair with cycles_ago, reloads the cycle and confirms', async () => {
    const user = userEvent.setup();
    mockApi(MARIA, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await openPaidTab(user);
    const loadsBefore = summaryLoads();

    await user.click(await screen.findByRole('button', { name: 'Aceitar' }));

    expect(await screen.findByText('Comprovante aceito.')).toBeInTheDocument();
    expect(axios.post).toHaveBeenCalledTimes(1);
    const [url, body] = vi.mocked(axios.post).mock.calls[0];
    expect(url).toMatch(/\/api\/groups\/1\/settlements\/accept$/);
    expect(body).toEqual({ from_user_id: JOAO, to_user_id: MARIA, cycles_ago: 0 });
    await waitFor(() => expect(summaryLoads()).toBeGreaterThan(loadsBefore));
  });

  it('sends the cycle that is on screen: with the previous cycle selected, cycles_ago is 1', async () => {
    const user = userEvent.setup();
    mockApi(MARIA, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await user.click(await screen.findByRole('button', { name: 'Ciclo anterior' }));
    await openPaidTab(user);

    await user.click(await screen.findByRole('button', { name: 'Aceitar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ from_user_id: JOAO, to_user_id: MARIA, cycles_ago: 1 });
  });

  it('disables the decision buttons while the request is in flight', async () => {
    const user = userEvent.setup();
    let finish: (value: unknown) => void = () => undefined;
    vi.mocked(axios.post).mockReturnValue(new Promise(resolve => (finish = resolve)));
    mockApi(MARIA, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await openPaidTab(user);

    await user.click(await screen.findByRole('button', { name: 'Aceitar' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Aceitar' })).toBeDisabled());
    expect(screen.getByRole('button', { name: 'Recusar' })).toBeDisabled();
    finish({ data: {} });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Aceitar' })).toBeEnabled());
  });

  it('the creditor refuses: posts to refuse, reloads and confirms', async () => {
    const user = userEvent.setup();
    mockApi(MARIA, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await openPaidTab(user);
    const loadsBefore = summaryLoads();

    await user.click(await screen.findByRole('button', { name: 'Recusar' }));

    expect(await screen.findByText('Comprovante recusado.')).toBeInTheDocument();
    expect(vi.mocked(axios.post).mock.calls[0][0]).toMatch(/\/api\/groups\/1\/settlements\/refuse$/);
    expect(vi.mocked(axios.post).mock.calls[0][1]).toEqual({ from_user_id: JOAO, to_user_id: MARIA, cycles_ago: 0 });
    await waitFor(() => expect(summaryLoads()).toBeGreaterThan(loadsBefore));
  });

  it('who sent it deletes after confirming: DELETE with the pair in the query, reloads and confirms', async () => {
    const user = userEvent.setup();
    mockApi(ME, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await openPaidTab(user);
    const loadsBefore = summaryLoads();

    await user.click(await screen.findByRole('button', { name: 'Excluir' }));
    const dialog = await screen.findByRole('dialog', { name: 'Excluir comprovante?' });
    expect(axios.delete).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole('button', { name: 'Excluir' }));

    expect(await screen.findByText('Comprovante excluído.')).toBeInTheDocument();
    const [url, config] = vi.mocked(axios.delete).mock.calls[0];
    expect(url).toMatch(/\/api\/groups\/1\/settlements\/confirmation$/);
    expect(config).toMatchObject({ params: { from_user_id: JOAO, to_user_id: MARIA, cycles_ago: 0 } });
    await waitFor(() => expect(summaryLoads()).toBeGreaterThan(loadsBefore));
  });

  it('a refused settlement shows up under "Valores a pagar" with Enviar comprovante and Excluir', async () => {
    mockApi(ME, [settlementIn('refused', JOAO, MARIA)]);
    renderPayments();

    expect(await screen.findByText('Recusado por Maria')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Enviar comprovante' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Excluir' })).toBeInTheDocument();
  });

  it('shows the server message when the decision fails and enables the buttons again', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 422, data: { error: 'Esta competência já foi encerrada.' } } });
    mockApi(MARIA, [settlementIn('awaiting_acceptance', JOAO, MARIA)]);
    renderPayments();
    await openPaidTab(user);
    const loadsBefore = summaryLoads();

    await user.click(await screen.findByRole('button', { name: 'Aceitar' }));

    expect(await screen.findByText('Esta competência já foi encerrada.')).toBeInTheDocument();
    expect(screen.queryByText('Comprovante aceito.')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Aceitar' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Recusar' })).toBeEnabled();
    expect(summaryLoads()).toBe(loadsBefore);
  });
});
