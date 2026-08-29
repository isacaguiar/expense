import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupGrossDebtsPanel from './GroupGrossDebtsPanel';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

const grossDebtsResponse = {
  cycle: { start: '2026-08-01', end: '2026-08-31', status: 'open' as const },
  creditors: [
    {
      creditor: { id: 1, name: 'Credor Um', email: 'credor@example.com', pix: 'credor@pix.example' },
      debtors: [
        { id: 2, name: 'Devedor A', amount: 100 },
        { id: 3, name: 'Devedor B', amount: 50 },
      ],
    },
  ],
};

function mockGetResponses(gross: typeof grossDebtsResponse = grossDebtsResponse) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/expenses/gross-debts')) {
      return Promise.resolve({ data: gross });
    }
    if (url.includes('/pix/generate')) {
      return Promise.resolve({ data: { qrcode: 'data:image/png;base64,fake', copiacola: '00020126...' } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

describe('GroupGrossDebtsPanel', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.put).mockReset();
  });

  it('shows a message when there is no pending debt in the cycle', async () => {
    mockGetResponses({ cycle: grossDebtsResponse.cycle, creditors: [] });

    render(
      <MemoryRouter>
        <GroupGrossDebtsPanel groupId="1" />
      </MemoryRouter>
    );

    expect(await screen.findByText('Nenhuma pendência neste ciclo.')).toBeInTheDocument();
  });

  it('renders the creditor->debtors tree with gross amounts', async () => {
    mockGetResponses();

    render(
      <MemoryRouter>
        <GroupGrossDebtsPanel groupId="1" />
      </MemoryRouter>
    );

    expect(await screen.findByText('Credor Um')).toBeInTheDocument();
    expect(screen.getByText('Devedor A')).toBeInTheDocument();
    expect(screen.getByText('R$ 100,00')).toBeInTheDocument();
    expect(screen.getByText('Devedor B')).toBeInTheDocument();
    expect(screen.getByText('R$ 50,00')).toBeInTheDocument();
  });

  it('opens the Pix dialog with the correct creditor/amount when clicking the Pix icon', async () => {
    mockGetResponses();
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupGrossDebtsPanel groupId="1" />
      </MemoryRouter>
    );

    await screen.findByText('Devedor A');

    await user.click(screen.getByRole('button', { name: 'Pagar Credor Um via Pix (dívida de Devedor A)' }));

    expect(await screen.findByText('Pagar Credor Um')).toBeInTheDocument();
    expect(screen.getByText('R$ 100,00 via Pix')).toBeInTheDocument();
  });

  it('marking a debt as informed only changes the UI, without calling the API', async () => {
    mockGetResponses();
    const user = userEvent.setup();

    render(
      <MemoryRouter>
        <GroupGrossDebtsPanel groupId="1" />
      </MemoryRouter>
    );

    await screen.findByText('Devedor A');

    await user.click(screen.getByRole('button', { name: 'Informar pagamento de Devedor A para Credor Um' }));

    expect(await screen.findByText('Informado')).toBeInTheDocument();
    expect(axios.post).not.toHaveBeenCalled();
    expect(axios.put).not.toHaveBeenCalled();
  });
});
