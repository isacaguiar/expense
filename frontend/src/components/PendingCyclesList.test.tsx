import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PendingCyclesList from './PendingCyclesList';
import type { PendingCycle } from '../hooks/useGroupPendingCycles';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const pendingCycle = (overrides: Partial<PendingCycle> = {}): PendingCycle => ({
  cycle: { start: '2026-09-01', end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 300, paid: 120, pending: 180 },
  settlements_total: 3,
  settlements_confirmed: 1,
  pending_expenses_count: 2,
  awaiting_seal: false,
  ...overrides,
});

const withCycle = (cycle: Partial<PendingCycle['cycle']>, rest: Partial<PendingCycle> = {}): PendingCycle => {
  const base = pendingCycle();
  return pendingCycle({ cycle: { ...base.cycle, ...cycle }, ...rest });
};

const renderList = async (cycles: PendingCycle[], onViewClosing = vi.fn()) => {
  vi.mocked(axios.get).mockResolvedValue({ data: { data: cycles } });
  const view = render(
    <MemoryRouter>
      <PendingCyclesList groupId="7" onViewClosing={onViewClosing} />
    </MemoryRouter>
  );
  // O spinner de carregamento não tem nome acessível (como nos outros painéis) e as barras dos
  // cartões também são `progressbar`: esperar pela classe do spinner é o que distingue os dois.
  await waitFor(() => expect(document.querySelector('.MuiCircularProgress-root')).toBeNull());
  return { ...view, onViewClosing };
};

const cards = (): HTMLElement[] => screen.queryAllByRole('listitem');

describe('PendingCyclesList', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    navigateMock.mockClear();
    localStorage.setItem('accessToken', 'tok-123');
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  describe('loading, error and empty', () => {
    it('shows a spinner while the cycles are loading', () => {
      vi.mocked(axios.get).mockReturnValue(new Promise(() => {}));

      render(
        <MemoryRouter>
          <PendingCyclesList groupId="7" onViewClosing={vi.fn()} />
        </MemoryRouter>
      );

      expect(screen.getByRole('progressbar')).toBeInTheDocument();
      expect(screen.queryByRole('list')).not.toBeInTheDocument();
      expect(screen.queryByText('Nenhum ciclo aguardando pagamento.')).not.toBeInTheDocument();
    });

    it('asks the API for the pending cycles of the group it was given', async () => {
      await renderList([pendingCycle()]);

      const [url, config] = vi.mocked(axios.get).mock.calls[0];
      expect(String(url)).toMatch(/\/api\/groups\/7\/expenses\/pending-cycles$/);
      expect(config?.headers).toMatchObject({ Authorization: 'Bearer tok-123' });
    });

    it('says no cycle is waiting for payment instead of showing an empty list', async () => {
      await renderList([]);

      expect(screen.getByText('Nenhum ciclo aguardando pagamento.')).toBeInTheDocument();
      expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('shows the error and no list when the request fails', async () => {
      vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

      render(
        <MemoryRouter>
          <PendingCyclesList groupId="7" onViewClosing={vi.fn()} />
        </MemoryRouter>
      );

      expect(await screen.findByText('Falha ao carregar os ciclos em acompanhamento.')).toBeInTheDocument();
      expect(screen.queryByRole('list')).not.toBeInTheDocument();
      expect(screen.queryByText('Nenhum ciclo aguardando pagamento.')).not.toBeInTheDocument();
    });

    it('sends the user back to the login on 401 without showing an error', async () => {
      vi.mocked(axios.get).mockRejectedValue({ response: { status: 401 } });

      render(
        <MemoryRouter>
          <PendingCyclesList groupId="7" onViewClosing={vi.fn()} />
        </MemoryRouter>
      );

      await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/', { replace: true }));
      expect(screen.queryByText('Falha ao carregar os ciclos em acompanhamento.')).not.toBeInTheDocument();
    });
  });

  describe('one card per cycle', () => {
    it('lists the cycles in the order the API sent them, each with its period', async () => {
      await renderList([
        withCycle({ start: '2026-09-01', end: '2026-09-30' }),
        withCycle({ start: '2026-08-01', end: '2026-08-31' }),
      ]);

      const [recent, older] = cards();
      expect(cards()).toHaveLength(2);
      expect(within(recent).getByText('01 de set. – 30 de set.')).toBeInTheDocument();
      expect(within(older).getByText('01 de ago. – 31 de ago.')).toBeInTheDocument();
    });

    it('names the list for a screen reader', async () => {
      await renderList([pendingCycle()]);

      expect(screen.getByRole('list', { name: 'Ciclos aguardando pagamento' })).toBeInTheDocument();
    });

    it('does not shift the period by a day in a negative-offset time zone', async () => {
      await renderList([withCycle({ start: '2026-10-01', end: '2026-10-31' })]);

      expect(within(cards()[0]).getByText('01 de out. – 31 de out.')).toBeInTheDocument();
    });
  });

  describe('status chip', () => {
    it('says when a cycle in grace closes, and calls it info', async () => {
      await renderList([withCycle({ status: 'open', in_grace: true, closes_at: '2026-10-05' })]);

      const chip = within(cards()[0]).getByText('Fecha em 05/10');
      expect(chip.closest('.MuiChip-root')).toHaveClass('MuiChip-colorInfo');
    });

    it('does not shift the closing day in a negative-offset time zone', async () => {
      await renderList([withCycle({ status: 'open', in_grace: true, closes_at: '2026-11-01' })]);

      expect(within(cards()[0]).getByText('Fecha em 01/11')).toBeInTheDocument();
    });

    it('says Quitado, in success, for a cycle that is fully settled and waits only for the seal', async () => {
      await renderList([pendingCycle({ awaiting_seal: true })]);

      const chip = within(cards()[0]).getByText('Quitado');
      expect(chip.closest('.MuiChip-root')).toHaveClass('MuiChip-colorSuccess');
    });

    it('keeps "Fecha em" over Quitado while the cycle is still in grace, because its values can change', async () => {
      await renderList([withCycle({ status: 'open', in_grace: true, closes_at: '2026-10-05' }, { awaiting_seal: true })]);

      expect(within(cards()[0]).getByText('Fecha em 05/10')).toBeInTheDocument();
      expect(within(cards()[0]).queryByText('Quitado')).not.toBeInTheDocument();
    });

    it.each([
      ['closed', 'Ciclo fechado', 'MuiChip-colorDefault'],
      ['closed_manually', 'Fechado (revisável)', 'MuiChip-colorWarning'],
    ] as const)('uses the shared label and color for a %s cycle', async (status, label, colorClass) => {
      await renderList([withCycle({ status })]);

      const chip = within(cards()[0]).getByText(label);
      expect(chip.closest('.MuiChip-root')).toHaveClass(colorClass);
      expect(chip.closest('.MuiChip-root')).not.toHaveClass('MuiChip-outlined');
      expect(within(cards()[0]).queryByText('Quitado')).not.toBeInTheDocument();
      expect(within(cards()[0]).queryByText(/Fecha em/)).not.toBeInTheDocument();
    });

    it('gives each card its own chip', async () => {
      await renderList([
        pendingCycle({ awaiting_seal: true }),
        withCycle({ start: '2026-08-01', end: '2026-08-31' }),
      ]);

      const [settled, waiting] = cards();
      expect(within(settled).getByText('Quitado')).toBeInTheDocument();
      expect(within(waiting).getByText('Ciclo fechado')).toBeInTheDocument();
      expect(within(waiting).queryByText('Quitado')).not.toBeInTheDocument();
    });
  });

  describe('payment progress', () => {
    it('shows a bar with the share of the total that is paid', async () => {
      await renderList([pendingCycle({ totals: { total: 300, paid: 120, pending: 180 } })]);

      const bar = within(cards()[0]).getByRole('progressbar');
      expect(bar).toHaveAttribute('aria-valuenow', '40');
      expect(bar).toHaveAccessibleName('Pago: 40% do total');
      expect(within(cards()[0]).getByText('R$ 120,00 de R$ 300,00 pagos')).toBeInTheDocument();
    });

    it('keeps the bar empty, not NaN, when the cycle has nothing to divide', async () => {
      await renderList([pendingCycle({ totals: { total: 0, paid: 0, pending: 0 } })]);

      const bar = within(cards()[0]).getByRole('progressbar');
      expect(bar).toHaveAttribute('aria-valuenow', '0');
      expect(within(cards()[0]).getByText('R$ 0,00 de R$ 0,00 pagos')).toBeInTheDocument();
    });

    it('fills the bar when everything is paid', async () => {
      await renderList([pendingCycle({ totals: { total: 300, paid: 300, pending: 0 } })]);

      expect(within(cards()[0]).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    });

    it('never goes past 100% even if the paid amount exceeds the total', async () => {
      await renderList([pendingCycle({ totals: { total: 100, paid: 130, pending: 0 } })]);

      expect(within(cards()[0]).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');
    });

    it.each([
      [100, '33'],
      [200, '67'],
    ])('rounds the share to the nearest whole percent (%s of 300)', async (paid, expected) => {
      await renderList([pendingCycle({ totals: { total: 300, paid, pending: 300 - paid } })]);

      expect(within(cards()[0]).getByRole('progressbar')).toHaveAttribute('aria-valuenow', expected);
    });
  });

  describe('confirmed settlements', () => {
    it('shows how many settlements are confirmed out of the total', async () => {
      await renderList([pendingCycle({ settlements_total: 3, settlements_confirmed: 1 })]);

      expect(within(cards()[0]).getByText('Acertos confirmados 1 de 3')).toBeInTheDocument();
    });

    it('omits the line when the cycle has no settlements to confirm', async () => {
      await renderList([pendingCycle({ settlements_total: 0, settlements_confirmed: 0 })]);

      expect(within(cards()[0]).queryByText(/Acertos confirmados/)).not.toBeInTheDocument();
    });

    it('shows zero out of the total when none was confirmed yet', async () => {
      await renderList([pendingCycle({ settlements_total: 3, settlements_confirmed: 0 })]);

      expect(within(cards()[0]).getByText('Acertos confirmados 0 de 3')).toBeInTheDocument();
    });

    it('shows the line when every settlement is already confirmed', async () => {
      await renderList([pendingCycle({ settlements_total: 2, settlements_confirmed: 2 })]);

      expect(within(cards()[0]).getByText('Acertos confirmados 2 de 2')).toBeInTheDocument();
    });

    it('omits the line only on the card that has no settlements', async () => {
      await renderList([
        pendingCycle({ settlements_total: 0, settlements_confirmed: 0 }),
        withCycle({ start: '2026-08-01', end: '2026-08-31' }, { settlements_total: 4, settlements_confirmed: 3 }),
      ]);

      const [none, some] = cards();
      expect(within(none).queryByText(/Acertos confirmados/)).not.toBeInTheDocument();
      expect(within(some).getByText('Acertos confirmados 3 de 4')).toBeInTheDocument();
    });
  });

  describe('pending expenses', () => {
    it('counts the expenses still unpaid', async () => {
      await renderList([pendingCycle({ pending_expenses_count: 2 })]);

      expect(within(cards()[0]).getByText('2 despesas pendentes')).toBeInTheDocument();
    });

    it('uses the singular for one expense', async () => {
      await renderList([pendingCycle({ pending_expenses_count: 1 })]);

      expect(within(cards()[0]).getByText('1 despesa pendente')).toBeInTheDocument();
    });

    it('says there is none when everything is paid', async () => {
      await renderList([pendingCycle({ pending_expenses_count: 0 })]);

      expect(within(cards()[0]).getByText('Nenhuma despesa pendente')).toBeInTheDocument();
    });
  });

  describe('Ver fechamento', () => {
    it('hands the start of the cycle to the callback when clicked', async () => {
      const user = userEvent.setup();
      const { onViewClosing } = await renderList([pendingCycle()]);

      await user.click(within(cards()[0]).getByRole('button', { name: /Ver fechamento/ }));

      expect(onViewClosing).toHaveBeenCalledTimes(1);
      expect(onViewClosing).toHaveBeenCalledWith('2026-09-01');
    });

    it('hands over the start of the card that was clicked, not another one', async () => {
      const user = userEvent.setup();
      const { onViewClosing } = await renderList([
        withCycle({ start: '2026-09-01', end: '2026-09-30' }),
        withCycle({ start: '2026-08-01', end: '2026-08-31' }),
      ]);

      await user.click(within(cards()[1]).getByRole('button', { name: /Ver fechamento/ }));

      expect(onViewClosing).toHaveBeenCalledTimes(1);
      expect(onViewClosing).toHaveBeenCalledWith('2026-08-01');
    });

    it('names the cycle for a screen reader while keeping the visible words', async () => {
      await renderList([pendingCycle()]);

      expect(within(cards()[0]).getByRole('button', { name: 'Ver fechamento do ciclo 01 de set. – 30 de set.' })).toBeInTheDocument();
    });

    it('does not call the callback just by rendering', async () => {
      const { onViewClosing } = await renderList([pendingCycle()]);

      expect(onViewClosing).not.toHaveBeenCalled();
    });
  });
});
