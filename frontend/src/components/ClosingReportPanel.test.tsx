import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClosingReportPanel from './ClosingReportPanel';
import type { ClosingReport } from '../hooks/useClosingReport';

vi.mock('axios');

const navigateMock = vi.fn();
vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const closingReport = (overrides: Partial<ClosingReport> = {}): ClosingReport => ({
  group: { id: 7, name: 'Casa da Praia' },
  cycle: { start: '2026-09-01', end: '2026-09-30', closes_at: '2026-10-05', status: 'closed', settled: false, in_grace: false },
  totals: { total: 390, paid: 90, pending: 300 },
  expenses: [
    {
      id: 1,
      description: 'Aluguel',
      date: '2026-09-05',
      value: 300,
      valuePerPerson: 150,
      paid: false,
      paymentProofUrl: null,
      payerName: 'Ana',
      participants: ['Ana', 'Beto'],
      isFixed: true,
      userPayerId: 1,
      userCreatorId: 1,
    },
    {
      id: 2,
      description: 'Luz',
      date: '2026-09-10',
      value: 90,
      valuePerPerson: 45,
      paid: true,
      paymentProofUrl: null,
      paymentNote: 'Pago em dinheiro, recibo 123',
      payerName: 'Ana',
      participants: ['Ana', 'Beto'],
      isFixed: false,
      userPayerId: 1,
      userCreatorId: 1,
    },
  ],
  balances: [
    { user_id: 1, name: 'Ana', balance: 195 },
    { user_id: 2, name: 'Beto', balance: -195 },
  ],
  settlements: [{ from_user_id: 2, to_user_id: 1, amount: 195, confirmedProofUrl: null, confirmedAt: null }],
  creditors: [
    {
      creditor: { id: 1, name: 'Ana', avatarUrl: null },
      advanced_total: 390,
      debtors: [{ id: 2, name: 'Beto', avatarUrl: null, amount: 195 }],
    },
  ],
  ...overrides,
});

const withCycle = (cycle: Partial<ClosingReport['cycle']>, rest: Partial<ClosingReport> = {}): ClosingReport => {
  const base = closingReport();
  return closingReport({ cycle: { ...base.cycle, ...cycle }, ...rest });
};

const renderPanel = async (report: ClosingReport = closingReport()) => {
  vi.mocked(axios.get).mockResolvedValue({ data: report });
  const view = render(
    <MemoryRouter>
      <ClosingReportPanel groupId="7" cycleStart="2026-09-01" />
    </MemoryRouter>
  );
  await screen.findByRole('button', { name: 'Imprimir / Salvar PDF' });
  return view;
};

const emotionCss = (): string =>
  Array.from(document.querySelectorAll('style[data-emotion]'))
    .map(style => style.textContent ?? '')
    .join('')
    .replace(/\s+/g, '');

describe('ClosingReportPanel', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    navigateMock.mockClear();
    localStorage.setItem('accessToken', 'tok-123');
    vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 9, 24, 15, 0, 0));
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  describe('expense list order', () => {
    it('lists the expenses by date, newest first, even when the frozen snapshot arrives oldest first', async () => {
      // `closingReport()` entrega Aluguel (05/09) antes de Luz (10/09), como um snapshot selado.
      await renderPanel();

      expect(screen.getAllByText(/^(Aluguel|Luz) — R\$/).map(item => item.textContent)).toEqual([
        'Luz — R$ 90,00',
        'Aluguel — R$ 300,00',
      ]);
    });
  });

  describe('loading and errors', () => {
    it('asks the API for the report of the cycle it was given', async () => {
      await renderPanel();

      const [url, config] = vi.mocked(axios.get).mock.calls[0];
      expect(String(url)).toMatch(/\/api\/groups\/7\/expenses\/closing-report$/);
      expect(config).toMatchObject({ params: { cycle_start: '2026-09-01' } });
    });

    it('shows a spinner while the report loads, with no print button yet', () => {
      vi.mocked(axios.get).mockReturnValue(new Promise(() => {}));

      render(
        <MemoryRouter>
          <ClosingReportPanel groupId="7" cycleStart="2026-09-01" />
        </MemoryRouter>
      );

      expect(screen.getByRole('progressbar')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Imprimir / Salvar PDF' })).not.toBeInTheDocument();
    });

    it('says the cycle was not found on 404, with nothing to print', async () => {
      vi.mocked(axios.get).mockRejectedValue({ response: { status: 404 } });

      render(
        <MemoryRouter>
          <ClosingReportPanel groupId="7" cycleStart="2099-01-01" />
        </MemoryRouter>
      );

      expect(await screen.findByText('Ciclo não encontrado.')).toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Imprimir / Salvar PDF' })).not.toBeInTheDocument();
      expect(screen.queryByText('Despesas do ciclo')).not.toBeInTheDocument();
    });

    it('shows a friendly error when the request fails for another reason', async () => {
      vi.mocked(axios.get).mockRejectedValue({ response: { status: 500 } });

      render(
        <MemoryRouter>
          <ClosingReportPanel groupId="7" cycleStart="2026-09-01" />
        </MemoryRouter>
      );

      expect(await screen.findByText('Falha ao carregar o Relatório de Fechamento.')).toBeInTheDocument();
    });
  });

  describe('header', () => {
    it('names the group, the period and the day it was issued', async () => {
      await renderPanel();

      expect(screen.getByRole('heading', { name: 'Casa da Praia' })).toBeInTheDocument();
      expect(screen.getByText('Período: 01/09/2026 a 30/09/2026')).toBeInTheDocument();
      expect(screen.getByText('Emitido em 24/10/2026')).toBeInTheDocument();
    });

    it('does not shift the period by a day in a negative time zone', async () => {
      await renderPanel(withCycle({ start: '2026-10-01', end: '2026-10-31' }));

      // TZ do Vitest é America/Sao_Paulo: `new Date('2026-10-01')` cairia em 30/09.
      expect(screen.getByText('Período: 01/10/2026 a 31/10/2026')).toBeInTheDocument();
    });

    it.each([
      ['an open cycle', { status: 'open' as const }, {}, 'Prévia'],
      ['an open cycle that has nothing left to pay', { status: 'open' as const }, { totals: { total: 90, paid: 90, pending: 0 }, settlements: [] }, 'Prévia'],
      ['a cycle in its grace days', { status: 'open' as const, in_grace: true }, {}, 'Prévia'],
      ['a closed cycle with expenses to pay', { status: 'closed' as const }, {}, 'Aguardando pagamentos'],
      [
        'a closed cycle whose only open point is an unconfirmed settlement',
        { status: 'closed' as const },
        { totals: { total: 390, paid: 390, pending: 0 } },
        'Aguardando pagamentos',
      ],
      [
        'a closed cycle with expenses still to pay even though every settlement is confirmed',
        { status: 'closed' as const },
        {
          settlements: [
            { from_user_id: 2, to_user_id: 1, amount: 195, confirmedProofUrl: 'https://x/p', confirmedAt: '2026-10-02T10:00:00-03:00' },
          ],
        },
        'Aguardando pagamentos',
      ],
      ['a manually closed cycle with expenses to pay', { status: 'closed_manually' as const }, {}, 'Aguardando pagamentos'],
      ['a sealed cycle', { status: 'closed' as const, settled: true }, {}, 'Quitado'],
      [
        'a closed cycle with everything paid and no settlements',
        { status: 'closed' as const },
        { totals: { total: 90, paid: 90, pending: 0 }, settlements: [] },
        'Quitado',
      ],
      [
        'a closed cycle with everything paid and every settlement confirmed',
        { status: 'closed' as const },
        {
          totals: { total: 390, paid: 390, pending: 0 },
          settlements: [
            { from_user_id: 2, to_user_id: 1, amount: 195, confirmedProofUrl: 'https://x/p', confirmedAt: '2026-10-02T10:00:00-03:00' },
          ],
        },
        'Quitado',
      ],
    ])('shows the right status chip for %s', async (_label, cycle, rest, expected) => {
      await renderPanel(withCycle(cycle, rest));

      const chips = ['Prévia', 'Aguardando pagamentos', 'Quitado'].filter(label => screen.queryByText(label) !== null);
      expect(chips).toEqual([expected]);
    });

    it('warns that the values can still change while the cycle is in its grace days', async () => {
      await renderPanel(withCycle({ status: 'open', in_grace: true, closes_at: '2026-10-05' }));

      expect(screen.getByText('Em carência até 05/10/2026: os valores ainda podem mudar.')).toBeInTheDocument();
    });

    it('does not mention the grace days for a cycle that is not in them', async () => {
      await renderPanel(withCycle({ status: 'closed', in_grace: false }));

      expect(screen.queryByText(/carência/)).not.toBeInTheDocument();
    });
  });

  describe('content', () => {
    it('shows the sections in order: totals, gross values, settlements and expenses', async () => {
      await renderPanel();

      const marks = [
        screen.getByRole('heading', { name: 'Casa da Praia' }),
        screen.getByText('Total de despesas'),
        screen.getByRole('heading', { name: 'Valores por despesa (bruto)' }),
        screen.getByRole('heading', { name: 'Acertos (líquido entre pares)' }),
        screen.getByRole('heading', { name: 'Despesas do ciclo' }),
      ];

      marks.slice(1).forEach((mark, index) => {
        expect(marks[index].compareDocumentPosition(mark) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      });
    });

    it('shows the three totals', async () => {
      await renderPanel();

      expect(screen.getByText('R$ 390,00')).toBeInTheDocument();
      expect(screen.getByText('R$ 90,00')).toBeInTheDocument();
      expect(screen.getByText('R$ 300,00')).toBeInTheDocument();
    });

    describe('gross values (creditors to debtors)', () => {
      const gross = () => within(screen.getByRole('region', { name: 'Valores por despesa (bruto)' }));

      it('shows what each creditor advanced and what each debtor owes them', async () => {
        await renderPanel(
          closingReport({
            creditors: [
              {
                creditor: { id: 1, name: 'Ana', avatarUrl: null },
                advanced_total: 390,
                debtors: [
                  { id: 2, name: 'Beto', avatarUrl: null, amount: 195 },
                  { id: 3, name: 'Caio', avatarUrl: null, amount: 100.5 },
                ],
              },
              {
                creditor: { id: 2, name: 'Beto', avatarUrl: null },
                advanced_total: 40,
                debtors: [{ id: 1, name: 'Ana', avatarUrl: null, amount: 20 }],
              },
            ],
          })
        );

        const [ana, beto] = gross().getAllByRole('listitem');
        expect(within(ana).getByText('Ana')).toBeInTheDocument();
        expect(within(ana).getByText('Adiantou R$ 390,00')).toBeInTheDocument();
        expect(within(ana).getByText('Beto deve R$ 195,00')).toBeInTheDocument();
        expect(within(ana).getByText('Caio deve R$ 100,50')).toBeInTheDocument();
        expect(within(beto).getByText('Adiantou R$ 40,00')).toBeInTheDocument();
        expect(within(beto).getByText('Ana deve R$ 20,00')).toBeInTheDocument();
        expect(within(beto).queryByText(/Caio/)).not.toBeInTheDocument();
      });

      it('keeps the order the API gave', async () => {
        await renderPanel(
          closingReport({
            creditors: [
              { creditor: { id: 2, name: 'Beto', avatarUrl: null }, advanced_total: 500, debtors: [] },
              { creditor: { id: 1, name: 'Ana', avatarUrl: null }, advanced_total: 10, debtors: [] },
            ],
          })
        );

        const [first, second] = gross().getAllByRole('listitem');
        expect(first).toHaveTextContent('Adiantou R$ 500,00');
        expect(second).toHaveTextContent('Adiantou R$ 10,00');
      });

      it('says so when a creditor has nobody who owes them', async () => {
        await renderPanel(
          closingReport({
            creditors: [{ creditor: { id: 1, name: 'Ana', avatarUrl: null }, advanced_total: 90, debtors: [] }],
          })
        );

        expect(gross().getByText('Ana')).toBeInTheDocument();
        expect(gross().getByText('Sem devedores neste ciclo.')).toBeInTheDocument();
      });

      it('says so when nobody advanced anything', async () => {
        await renderPanel(closingReport({ creditors: [] }));

        expect(gross().getByText('Nenhum valor a dividir neste ciclo.')).toBeInTheDocument();
        expect(gross().queryByRole('listitem')).not.toBeInTheDocument();
      });
    });

    describe('settlements (net between pairs)', () => {
      const settlementsSection = () => within(screen.getByRole('region', { name: 'Acertos (líquido entre pares)' }));

      it('explains in one line how the net differs from the gross values', async () => {
        await renderPanel();

        expect(
          settlementsSection().getByText(
            'O líquido desconta o que cada par deve um ao outro, por isso os acertos podem diferir dos valores brutos acima.'
          )
        ).toBeInTheDocument();
      });

      it('shows who pays whom, with the status of each settlement', async () => {
        await renderPanel(
          closingReport({
            balances: [
              { user_id: 1, name: 'Ana', balance: 255 },
              { user_id: 2, name: 'Beto', balance: -195 },
              { user_id: 3, name: 'Caio', balance: -60 },
            ],
            settlements: [
              {
                from_user_id: 2,
                to_user_id: 1,
                amount: 195,
                confirmedProofUrl: 'https://x/p',
                confirmedNote: 'Pix no banco',
                confirmedAt: '2026-10-02T10:00:00-03:00',
              },
              { from_user_id: 3, to_user_id: 1, amount: 60, confirmedProofUrl: null, confirmedAt: null },
            ],
          })
        );

        const section = settlementsSection();
        const [first, second] = section.getAllByText(/deve pagar/);
        expect(first).toHaveTextContent('Beto deve pagar R$ 195,00 a Ana');
        expect(second).toHaveTextContent('Caio deve pagar R$ 60,00 a Ana');
        expect(section.getByText('Confirmado em 02/10')).toBeInTheDocument();
        expect(section.getByText('Descrição do pagamento: Pix no banco')).toBeInTheDocument();
        expect(section.getByText('Aguardando comprovante')).toBeInTheDocument();
      });

      it('says so when nobody owes anybody', async () => {
        await renderPanel(closingReport({ settlements: [] }));

        expect(settlementsSection().getByText('Nenhum acerto entre os membros neste ciclo.')).toBeInTheDocument();
        expect(settlementsSection().queryByText('Aguardando comprovante')).not.toBeInTheDocument();
      });
    });

    it('lists the expenses of the cycle with the payment note', async () => {
      await renderPanel();

      const expenses = within(screen.getByRole('region', { name: 'Despesas do ciclo' }));
      expect(expenses.getByText('Aluguel — R$ 300,00')).toBeInTheDocument();
      expect(expenses.getByText('Luz — R$ 90,00')).toBeInTheDocument();
      expect(expenses.getByText('Descrição do pagamento: Pago em dinheiro, recibo 123')).toBeInTheDocument();
    });
  });

  describe('printing', () => {
    it('opens the browser print dialog when the button is clicked', async () => {
      const print = vi.spyOn(window, 'print').mockImplementation(() => {});
      await renderPanel();

      await userEvent.setup().click(screen.getByRole('button', { name: 'Imprimir / Salvar PDF' }));

      expect(print).toHaveBeenCalledTimes(1);
    });

    it('keeps the print button itself off the printed sheet', async () => {
      await renderPanel();

      expect(screen.getByRole('button', { name: 'Imprimir / Salvar PDF' })).toHaveClass('no-print');
    });

    it('hides what is marked no-print, whitens the page and sets the margins, only for printing', async () => {
      await renderPanel();

      const css = emotionCss();
      expect(css).toMatch(/@mediaprint\{/);
      expect(css).toContain('.no-print{display:none!important;}');
      expect(css).toMatch(/body\{background-color:#fff!important;\}/);
      expect(css).toContain('@page{margin:12mm;}');
      // Nada disso vale fora da impressão: as regras ficam todas dentro do @media print.
      expect(css.replace(/@mediaprint\{.*\}\}/, '')).not.toContain('no-print');
    });

    it('removes the print rules when the panel leaves the screen', async () => {
      const { unmount } = await renderPanel();
      expect(emotionCss()).toContain('.no-print');

      unmount();

      expect(emotionCss()).not.toContain('.no-print');
    });
  });
});
