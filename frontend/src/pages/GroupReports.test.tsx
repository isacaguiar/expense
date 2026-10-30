import type { ReactNode } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { Link, MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GroupReports from './GroupReports';
import type { ClosingReport } from '../hooks/useClosingReport';
import type { PendingCycle } from '../hooks/useGroupPendingCycles';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1' }),
  };
});

const cycleJune = {
  cycle: { start: '2026-06-01', end: '2026-06-30', status: 'closed' as const },
  totals: { total: 500, paid: 500, pending: 0 },
  expenses: [
    {
      id: 10,
      description: 'Aluguel',
      date: '2026-06-05',
      value: 500,
      paid: true,
      payerName: 'QA Relatórios',
      participants: ['QA Relatórios'],
      isFixed: true,
    },
  ],
  balances: [{ user_id: 1, name: 'QA Relatórios', balance: 0 }],
  settlements: [] as { from_user_id: number; to_user_id: number; amount: number }[],
};

const cycleJuly = {
  cycle: { start: '2026-07-01', end: '2026-07-31', status: 'closed' as const },
  totals: { total: 300, paid: 0, pending: 300 },
  expenses: [] as typeof cycleJune.expenses,
  balances: [{ user_id: 1, name: 'QA Relatórios', balance: 0 }],
  settlements: [] as { from_user_id: number; to_user_id: number; amount: number }[],
};

const pendingSeptember: PendingCycle = {
  cycle: { start: '2026-09-01', end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 300, paid: 120, pending: 180 },
  settlements_total: 3,
  settlements_confirmed: 1,
  pending_expenses_count: 2,
  awaiting_seal: false,
};

const pendingAugust: PendingCycle = {
  ...pendingSeptember,
  cycle: { ...pendingSeptember.cycle, start: '2026-08-01', end: '2026-08-31', closes_at: '2026-09-05' },
};

// O Fechamento devolve a despesa "Condomínio", que o histórico (com "Aluguel") não tem:
// assim um teste sabe de qual das duas respostas veio o que está na tela.
const closingReport = (start = '2026-09-01'): ClosingReport => ({
  group: { id: 1, name: 'Casa da Praia' },
  cycle: { start, end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 300, paid: 120, pending: 180 },
  expenses: [
    {
      id: 1,
      description: 'Condomínio',
      date: '2026-09-05',
      value: 300,
      valuePerPerson: 150,
      paid: false,
      paymentProofUrl: null,
      payerName: 'Ana',
      participants: ['Ana', 'Beto'],
      isFixed: false,
      userPayerId: 1,
      userCreatorId: 1,
    },
  ],
  balances: [
    { user_id: 1, name: 'Ana', balance: 150 },
    { user_id: 2, name: 'Beto', balance: -150 },
  ],
  settlements: [{ from_user_id: 2, to_user_id: 1, amount: 150, confirmedProofUrl: null, confirmedAt: null }],
  creditors: [
    {
      creditor: { id: 1, name: 'Ana', avatarUrl: null },
      advanced_total: 300,
      debtors: [{ id: 2, name: 'Beto', avatarUrl: null, amount: 150 }],
    },
  ],
});

type Api = {
  history?: (typeof cycleJune)[];
  lastPage?: number;
  pending?: PendingCycle[];
};

function mockApi({ history = [cycleJuly, cycleJune], lastPage = 1, pending = [pendingSeptember] }: Api = {}) {
  vi.mocked(axios.get).mockImplementation((url: string, config?: { params?: { cycle_start?: string } }) => {
    if (url.includes('/expenses/pending-cycles')) {
      return Promise.resolve({ data: { data: pending } });
    }
    if (url.includes('/expenses/closing-report')) {
      return Promise.resolve({ data: closingReport(config?.params?.cycle_start) });
    }
    if (url.includes('/expenses/cycles')) {
      return Promise.resolve({ data: { data: history, current_page: 1, last_page: lastPage } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

const calledUrls = (): string[] => vi.mocked(axios.get).mock.calls.map(([url]) => String(url));
const callsTo = (fragment: string) => vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes(fragment));

const LocationProbe = () => <output data-testid="search">{useLocation().search}</output>;

const renderPage = (entry = '/', extra: ReactNode = null) =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <GroupReports />
      <LocationProbe />
      {extra}
    </MemoryRouter>
  );

// CSS que o emotion injetou no documento, sem espaços (o jsdom não avalia `@media`).
const emotionCss = (): string =>
  Array.from(document.querySelectorAll('style[data-emotion]'))
    .map(style => style.textContent ?? '')
    .join('')
    .replace(/\s+/g, '');

const search = (): string => screen.getByTestId('search').textContent ?? '';
const tab = (name: string) => screen.getByRole('tab', { name });

describe('GroupReports', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
  });

  describe('Histórico', () => {
    it('shows a message when there are no closed cycles yet', async () => {
      mockApi({ history: [] });

      renderPage('/?aba=historico');

      expect(await screen.findByText('Nenhum ciclo fechado ainda.')).toBeInTheDocument();
    });

    it('lists the closed cycles most recent first', async () => {
      mockApi();

      renderPage('/?aba=historico');

      expect(await screen.findByText(/jul.*jul/i)).toBeInTheDocument();
      expect(screen.getByText(/jun.*jun/i)).toBeInTheDocument();
      expect(screen.getByText('Total: R$ 300,00')).toBeInTheDocument();
      expect(screen.getByText('Total: R$ 500,00')).toBeInTheDocument();
    });

    it('shows a spinner while the history is loading', () => {
      vi.mocked(axios.get).mockReturnValue(new Promise(() => {}));

      renderPage('/?aba=historico');

      expect(document.querySelector('.MuiCircularProgress-root')).not.toBeNull();
      expect(screen.queryByText('Nenhum ciclo fechado ainda.')).not.toBeInTheDocument();
    });

    it('shows the error and no list when the history request fails', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

      renderPage('/?aba=historico');

      expect(await screen.findByText('Falha ao carregar o histórico de ciclos do grupo.')).toBeInTheDocument();
      expect(screen.queryByText('Nenhum ciclo fechado ainda.')).not.toBeInTheDocument();
    });

    it('has no pagination when everything fits in one page', async () => {
      mockApi({ lastPage: 1 });

      renderPage('/?aba=historico');
      await screen.findByText('Total: R$ 300,00');

      expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    });

    it('asks for the next page of the history when it is chosen', async () => {
      mockApi({ lastPage: 2 });
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await screen.findByText('Total: R$ 300,00');
      await user.click(screen.getByRole('button', { name: 'Go to page 2' }));

      await waitFor(() => expect(callsTo('/expenses/cycles')).toHaveLength(2));
      expect(callsTo('/expenses/cycles')[1][1]?.params).toEqual({ page: 2 });
    });

    it('shows the closing report for the selected cycle', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');

      await screen.findByText(/jun.*jun/i);

      expect(screen.queryByText(/Condomínio/)).not.toBeInTheDocument();

      await user.click(screen.getByText(/jun.*jun/i));

      expect(await screen.findByText(/Condomínio/)).toBeInTheDocument();
    });

    it('asks the API for the report of the cycle that was clicked, not for the history summary', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await user.click(await screen.findByText(/jun.*jun/i));

      await screen.findByRole('heading', { name: 'Casa da Praia' });
      const [[url, config]] = callsTo('/expenses/closing-report');
      expect(String(url)).toMatch(/\/api\/groups\/1\/expenses\/closing-report$/);
      expect(config?.params).toEqual({ cycle_start: '2026-06-01' });
      // A resposta do histórico tem a despesa "Aluguel"; o painel antigo a mostraria.
      expect(screen.queryByText(/Aluguel/)).not.toBeInTheDocument();
    });

    it('puts the clicked cycle in the URL, keeping the tab', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await user.click(await screen.findByText(/jun.*jun/i));

      expect(search()).toBe('?aba=historico&ciclo=2026-06-01');
    });

    it('marks the open cycle as selected in the list', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      const june = (await screen.findByText(/jun.*jun/i)).closest('[role="button"]');
      const july = screen.getByText(/jul.*jul/i).closest('[role="button"]');
      expect(june).not.toHaveClass('Mui-selected');

      await user.click(june as HTMLElement);

      expect(june).toHaveClass('Mui-selected');
      expect(july).not.toHaveClass('Mui-selected');
    });

    it('switches the report when another cycle is clicked', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await user.click(await screen.findByText(/jun.*jun/i));
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(screen.getByText(/jul.*jul/i));

      await waitFor(() => expect(search()).toBe('?aba=historico&ciclo=2026-07-01'));
      await waitFor(() => expect(callsTo('/expenses/closing-report')).toHaveLength(2));
      expect(callsTo('/expenses/closing-report')[1][1]?.params).toEqual({ cycle_start: '2026-07-01' });
    });
  });

  describe('Acompanhamento', () => {
    it('lists the cycles waiting for payment', async () => {
      mockApi({ pending: [pendingSeptember, pendingAugust] });

      renderPage();

      const list = await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
      expect(list.querySelectorAll('li')).toHaveLength(2);
    });

    it('says no cycle is waiting when the API sends none', async () => {
      mockApi({ pending: [] });

      renderPage();

      expect(await screen.findByText('Nenhum ciclo aguardando pagamento.')).toBeInTheDocument();
    });

    it('opens the closing report of the card whose button was clicked', async () => {
      mockApi({ pending: [pendingSeptember, pendingAugust] });
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: 'Ver fechamento do ciclo 01 de ago. – 31 de ago.' }));

      await waitFor(() => expect(search()).toBe('?aba=acompanhamento&ciclo=2026-08-01'));
      expect(await screen.findByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
      expect(callsTo('/expenses/closing-report')[0][1]?.params).toEqual({ cycle_start: '2026-08-01' });
    });
  });

  describe('tabs and URL', () => {
    it('opens on Acompanhamento when the URL has no tab', async () => {
      mockApi();

      renderPage();

      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
      expect(tab('Acompanhamento')).toHaveAttribute('aria-selected', 'true');
      expect(tab('Histórico')).toHaveAttribute('aria-selected', 'false');
    });

    it('names the group of tabs for a screen reader', async () => {
      mockApi();

      renderPage();
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(screen.getByRole('tablist', { name: 'Seções de Relatórios' })).toBeInTheDocument();
    });

    it('does not load the history while Acompanhamento is the open tab', async () => {
      mockApi();

      renderPage();
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(callsTo('/expenses/pending-cycles')).toHaveLength(1);
      expect(calledUrls().filter(url => /\/expenses\/cycles/.test(url))).toHaveLength(0);
    });

    it('opens on Histórico with ?aba=historico and does not load the pending cycles', async () => {
      mockApi();

      renderPage('/?aba=historico');

      await screen.findByText('Total: R$ 300,00');
      expect(tab('Histórico')).toHaveAttribute('aria-selected', 'true');
      expect(tab('Acompanhamento')).toHaveAttribute('aria-selected', 'false');
      expect(callsTo('/expenses/pending-cycles')).toHaveLength(0);
    });

    it('falls back to Acompanhamento when ?aba is not a known tab', async () => {
      mockApi();

      renderPage('/?aba=xyz');

      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
      expect(tab('Acompanhamento')).toHaveAttribute('aria-selected', 'true');
      expect(screen.queryByText('Total: R$ 300,00')).not.toBeInTheDocument();
    });

    it('goes to Histórico when its tab is clicked, and says so in the URL', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage();
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
      await user.click(tab('Histórico'));

      expect(search()).toBe('?aba=historico');
      expect(await screen.findByText('Total: R$ 300,00')).toBeInTheDocument();
      expect(screen.queryByRole('list', { name: 'Ciclos aguardando pagamento' })).not.toBeInTheDocument();
    });

    it('goes back to Acompanhamento when its tab is clicked', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await screen.findByText('Total: R$ 300,00');
      await user.click(tab('Acompanhamento'));

      expect(search()).toBe('?aba=acompanhamento');
      expect(await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' })).toBeInTheDocument();
      expect(screen.queryByText('Total: R$ 300,00')).not.toBeInTheDocument();
    });

    it('opens the closing report of the cycle in the URL', async () => {
      mockApi();

      renderPage('/?ciclo=2026-09-01');

      expect(await screen.findByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
      expect(screen.getByText(/^Condomínio/)).toBeInTheDocument();
      expect(callsTo('/expenses/closing-report')[0][1]?.params).toEqual({ cycle_start: '2026-09-01' });
      expect(tab('Acompanhamento')).toHaveAttribute('aria-selected', 'true');
    });

    it('opens the closing report together with the history when both are in the URL', async () => {
      mockApi();

      renderPage('/?aba=historico&ciclo=2026-06-01');

      expect(await screen.findByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
      expect(await screen.findByText('Total: R$ 500,00')).toBeInTheDocument();
      expect(tab('Histórico')).toHaveAttribute('aria-selected', 'true');
    });

    it('shows no report, and asks for none, when ?ciclo is empty', async () => {
      mockApi();

      renderPage('/?ciclo=');
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(callsTo('/expenses/closing-report')).toHaveLength(0);
      expect(screen.queryByRole('button', { name: 'Imprimir / Salvar PDF' })).not.toBeInTheDocument();
    });

    it('asks for no report while no cycle is chosen', async () => {
      mockApi();

      renderPage('/?aba=historico');
      await screen.findByText('Total: R$ 300,00');

      expect(callsTo('/expenses/closing-report')).toHaveLength(0);
    });

    it('drops the cycle from the URL, and the report from the page, when the tab changes to Histórico', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=acompanhamento&ciclo=2026-09-01');
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(tab('Histórico'));

      expect(search()).toBe('?aba=historico');
      expect(screen.queryByRole('heading', { name: 'Casa da Praia' })).not.toBeInTheDocument();
      await screen.findByText('Total: R$ 300,00');
    });

    it('drops the cycle from the URL when the tab changes to Acompanhamento', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico&ciclo=2026-06-01');
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(tab('Acompanhamento'));

      expect(search()).toBe('?aba=acompanhamento');
      expect(screen.queryByRole('heading', { name: 'Casa da Praia' })).not.toBeInTheDocument();
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
    });

    it('shows "Ciclo não encontrado." for a cycle the API does not know', async () => {
      vi.spyOn(console, 'error').mockImplementation(() => {});
      vi.mocked(axios.get).mockImplementation((url: string) =>
        url.includes('/expenses/closing-report')
          ? Promise.reject({ response: { status: 404 } })
          : Promise.resolve({ data: { data: [pendingSeptember] } })
      );

      renderPage('/?ciclo=2020-01-01');

      expect(await screen.findByText('Ciclo não encontrado.')).toBeInTheDocument();
    });
  });

  describe('printing', () => {
    it('keeps the tabs off the printed sheet', async () => {
      mockApi();

      renderPage();
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(screen.getByRole('tablist').closest('.no-print')).not.toBeNull();
    });

    it('keeps the Acompanhamento list off the printed sheet', async () => {
      mockApi();

      renderPage('/?ciclo=2026-09-01');
      const list = await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(list.closest('.no-print')).not.toBeNull();
    });

    it('keeps the Histórico list off the printed sheet', async () => {
      mockApi();

      renderPage('/?aba=historico&ciclo=2026-06-01');
      const row = await screen.findByText('Total: R$ 500,00');

      expect(row.closest('.no-print')).not.toBeNull();
    });

    it('keeps the Histórico pagination off the printed sheet', async () => {
      mockApi({ lastPage: 2 });

      renderPage('/?aba=historico');
      await screen.findByText('Total: R$ 300,00');

      expect(screen.getByRole('navigation').closest('.no-print')).not.toBeNull();
    });

    it('keeps the closing report itself on the sheet', async () => {
      mockApi();

      renderPage('/?ciclo=2026-09-01');
      const title = await screen.findByRole('heading', { name: 'Casa da Praia' });

      expect(title.closest('.no-print')).toBeNull();
      expect(screen.getByText(/^Condomínio/).closest('.no-print')).toBeNull();
    });

    it('shows the closing report below the tabs and the list', async () => {
      mockApi();

      renderPage('/?ciclo=2026-09-01');
      const title = await screen.findByRole('heading', { name: 'Casa da Praia' });
      const list = screen.getByRole('list', { name: 'Ciclos aguardando pagamento' });

      expect(list.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(screen.getByRole('tablist').compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
  });

  // Na aba Acompanhamento o relatório abre abaixo de até 13 cartões, fora da tela (visto na
  // validação da TASK-497: a 1.791 px do topo numa janela de 900 px). Quem abre por clique
  // precisa ser levado até ele; quem chega por link, Voltar ou recarga já escolheu onde está.
  describe('scrolling to the closing report', () => {
    const scrollMock = vi.fn();
    const septemberButton = 'Ver fechamento do ciclo 01 de set. – 30 de set.';
    const augustButton = 'Ver fechamento do ciclo 01 de ago. – 31 de ago.';
    const scrolledTo = (call = 0) => scrollMock.mock.contexts[call] as HTMLElement;

    beforeEach(() => {
      scrollMock.mockClear();
      // O jsdom não implementa `scrollIntoView`.
      Element.prototype.scrollIntoView = scrollMock;
    });

    afterEach(() => {
      delete (Element.prototype as { scrollIntoView?: unknown }).scrollIntoView;
    });

    it('brings the report into view when "Ver fechamento" opens it', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await screen.findByRole('heading', { name: 'Casa da Praia' });

      expect(scrollMock).toHaveBeenCalledTimes(1);
      expect(scrollMock).toHaveBeenCalledWith({ block: 'start' });
      const target = scrolledTo();
      expect(within(target).getByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
      // O alvo é o relatório, não a página inteira nem a lista.
      expect(within(target).queryByRole('list', { name: 'Ciclos aguardando pagamento' })).not.toBeInTheDocument();
      expect(within(target).queryByRole('tablist')).not.toBeInTheDocument();
    });

    it('reserves a screen of height under the report, on screen only, so the scroll can reach it before the answer arrives', async () => {
      // Visto no Chrome: rolando para um relatório ainda no spinner, o documento é curto e o
      // navegador limita a rolagem; quando o conteúdo chega, o título fica no rodapé da janela.
      mockApi();
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await waitFor(() => expect(scrollMock).toHaveBeenCalled());

      const wrapperClass = Array.from(scrolledTo().classList).find(name => name.startsWith('css-'));
      expect(wrapperClass).toBeDefined();
      expect(emotionCss()).toContain(`@mediascreen{.${wrapperClass}{min-height:100vh;}}`);
    });

    it('keeps the margin of the loading spinner inside the report container', async () => {
      // Visto no Chrome: a margem de cima do spinner colapsava através do contêiner e o
      // empurrava 16 px para baixo; ao chegar o conteúdo ele subia, e o título ficava
      // 16 px acima do topo da janela. `flow-root` impede o colapso.
      mockApi();
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await waitFor(() => expect(scrollMock).toHaveBeenCalled());

      const wrapperClass = Array.from(scrolledTo().classList).find(name => name.startsWith('css-'));
      expect(emotionCss()).toContain(`.${wrapperClass}{display:flow-root;}`);
    });

    it('does the same when a Histórico item opens the report', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=historico');
      await user.click(await screen.findByText(/jun.*jun/i));
      await screen.findByRole('heading', { name: 'Casa da Praia' });

      expect(scrollMock).toHaveBeenCalledTimes(1);
      expect(within(scrolledTo()).getByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
    });

    it('does not scroll when the page loads with the cycle already in the URL', async () => {
      mockApi();

      renderPage('/?ciclo=2026-09-01');
      await screen.findByRole('heading', { name: 'Casa da Praia' });

      expect(scrollMock).not.toHaveBeenCalled();
    });

    it('does not scroll when the tab changes and the report goes away', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/?aba=acompanhamento&ciclo=2026-09-01');
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(tab('Histórico'));
      await screen.findByText('Total: R$ 300,00');

      expect(scrollMock).not.toHaveBeenCalled();
    });

    it('does not scroll when the URL changes without a click on a card', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/', <Link to="/?aba=historico&ciclo=2026-06-01">ir para o histórico</Link>);
      await screen.findByRole('list', { name: 'Ciclos aguardando pagamento' });
      await user.click(screen.getByRole('link', { name: 'ir para o histórico' }));
      await screen.findByRole('heading', { name: 'Casa da Praia' });

      expect(scrollMock).not.toHaveBeenCalled();
    });

    it('scrolls again when the card of the cycle that is already open is clicked', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(screen.getByRole('button', { name: septemberButton }));

      expect(scrollMock).toHaveBeenCalledTimes(2);
      expect(scrollMock).toHaveBeenNthCalledWith(2, { block: 'start' });
      expect(search()).toBe('?aba=acompanhamento&ciclo=2026-09-01');
      expect(within(scrolledTo(1)).getByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
    });

    it('scrolls once for each different cycle that is opened', async () => {
      mockApi({ pending: [pendingSeptember, pendingAugust] });
      const user = userEvent.setup();

      renderPage();
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      await user.click(screen.getByRole('button', { name: augustButton }));
      await waitFor(() => expect(search()).toBe('?aba=acompanhamento&ciclo=2026-08-01'));

      await waitFor(() => expect(scrollMock).toHaveBeenCalledTimes(2));
    });

    it('does not carry a click over to a later URL change that did not come from a card', async () => {
      mockApi();
      const user = userEvent.setup();

      renderPage('/', <Link to="/?aba=historico&ciclo=2026-06-01">ir para o histórico</Link>);
      await user.click(await screen.findByRole('button', { name: septemberButton }));
      await screen.findByRole('heading', { name: 'Casa da Praia' });
      expect(scrollMock).toHaveBeenCalledTimes(1);

      await user.click(screen.getByRole('link', { name: 'ir para o histórico' }));
      await waitFor(() => expect(search()).toBe('?aba=historico&ciclo=2026-06-01'));

      expect(scrollMock).toHaveBeenCalledTimes(1);
    });
  });
});
