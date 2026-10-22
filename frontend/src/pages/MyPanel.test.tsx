import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MyPanel from './MyPanel';
import { myOverviewFixture, overviewGroupFixture } from '../types/overview.fixtures';
import type { OverviewGroup, OverviewItem } from '../types/overview';

vi.mock('axios');

// O tema de Despesas é aplicado pela página, não pelo shell: o marcador deixa o
// teste afirmar que os cartões estão dentro dele.
vi.mock('../theme/DespesasThemeScope', () => ({
  default: ({ children }: { children: React.ReactNode }) => <div data-testid="despesas-theme-scope">{children}</div>
}));

const named = (id: number, name: string, overrides: Partial<OverviewGroup> = {}): OverviewGroup =>
  overviewGroupFixture({ group: { id, name }, ...overrides });

const creditorItem = (overrides: Partial<OverviewItem> = {}): OverviewItem => ({
  expense_id: 61,
  description: 'Internet',
  date: '2026-09-05',
  value: 100,
  my_share: 50,
  paid: false,
  role: 'creditor',
  creditor: { id: 7, name: 'Isac' },
  installment: null,
  can_pay: true,
  can_unpay: false,
  ...overrides,
});

const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;

function renderPanel() {
  render(
    <MemoryRouter>
      <MyPanel />
    </MemoryRouter>
  );
}

describe('MyPanel', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  it('shows a spinner while the first load is running', () => {
    vi.mocked(axios.get).mockReturnValue(new Promise(() => {}));

    renderPanel();

    expect(screen.getByRole('progressbar')).toBeInTheDocument();
    expect(screen.queryByText('Você ainda não participa de nenhum grupo.')).not.toBeInTheDocument();
  });

  it('shows the error with a way to try again, and loads the panel on the second attempt', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.get).mockRejectedValueOnce({ response: { status: 500 } });
    vi.mocked(axios.get).mockResolvedValueOnce({ data: myOverviewFixture() });

    renderPanel();

    expect(await screen.findByText('Falha ao carregar o painel.')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Residencial' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }));

    expect(await screen.findByRole('heading', { name: 'Residencial' })).toBeInTheDocument();
    expect(screen.queryByText('Falha ao carregar o painel.')).not.toBeInTheDocument();
  });

  it('says the user is in no group, with a way to Meus Grupos', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture({ groups: [] }) });

    renderPanel();

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ir para Meus Grupos' })).toHaveAttribute('href', '/meus-grupos');
  });

  it('shows one card per group, in the order the server sent, inside the Despesas theme', async () => {
    // Fora de ordem alfabética de propósito: quem ordena é o servidor (pendência primeiro).
    vi.mocked(axios.get).mockResolvedValue({
      data: myOverviewFixture({
        groups: [named(11, 'Zeta'), named(12, 'Beta', { has_open: false }), named(13, 'Alfa', { has_open: false })],
      }),
    });

    renderPanel();

    const scope = await screen.findByTestId('despesas-theme-scope');
    const names = within(scope)
      .getAllByRole('heading', { level: 2 })
      .map(heading => heading.textContent);
    expect(names).toEqual(['Zeta', 'Beta', 'Alfa']);
  });

  it('hands the logged user to each card, who is the debtor in "Você paga"', async () => {
    const user = userEvent.setup();
    vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture({ user: { id: 7, name: 'Isac' } }) });

    renderPanel();
    await user.click(await screen.findByRole('button', { name: /Ver detalhes/ }));

    expect(screen.getByText('Isac', { selector: 'strong' })).toBeInTheDocument();
  });

  it('warns when the server cut the list at 50 groups, and not otherwise', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture({ truncated: true }) });

    renderPanel();

    expect(
      await screen.findByText(
        'Você participa de mais de 50 grupos. Aqui aparecem os 50 mais antigos; os demais estão em Meus Grupos.'
      )
    ).toBeInTheDocument();
  });

  it('shows no such warning when the list was not cut', async () => {
    vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture({ truncated: false }) });

    renderPanel();

    await screen.findByRole('heading', { name: 'Residencial' });
    expect(screen.queryByText(/mais de 50 grupos/)).not.toBeInTheDocument();
  });

  describe('depois de uma ação', () => {
    it('reloads GET /api/me/overview, keeps the cards on screen and shows "Comprovante enviado."', async () => {
      const user = userEvent.setup();
      vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture() });

      renderPanel();
      await user.click(await screen.findByRole('button', { name: /Ver detalhes/ }));
      await user.click(screen.getByRole('button', { name: 'Enviar comprovante' }));
      await user.upload(fileInput(), new File(['foto'], 'pix.png', { type: 'image/png' }));
      await user.click(screen.getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
      await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
      // `findBy`: enquanto o diálogo de comprovante termina a animação de saída, o
      // MUI esconde o resto da página das consultas por papel.
      expect(await screen.findByRole('heading', { name: 'Residencial' })).toBeInTheDocument();
      expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('reloads and shows "Pagamento confirmado." after marking an expense as paid', async () => {
      const user = userEvent.setup();
      vi.mocked(axios.get).mockResolvedValue({ data: myOverviewFixture({ groups: [named(3, 'Residencial', { my_items: [creditorItem()] })] }) });

      renderPanel();
      await user.click(await screen.findByRole('button', { name: /Ver detalhes/ }));
      await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
      await user.upload(fileInput(), new File(['foto'], 'comprovante.png', { type: 'image/png' }));
      await user.click(screen.getByRole('button', { name: 'Confirmar' }));

      expect(await screen.findByText('Pagamento confirmado.')).toBeInTheDocument();
      await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
    });

    it('does not reload when the action fails', async () => {
      const user = userEvent.setup();
      vi.mocked(axios.get).mockResolvedValue({
        data: myOverviewFixture({
          groups: [named(3, 'Residencial', { my_items: [creditorItem({ paid: true, can_pay: false, can_unpay: true })] })],
        }),
      });
      vi.mocked(axios.post).mockRejectedValue({ response: { data: { error: 'Ciclo selado.' } } });

      renderPanel();
      await user.click(await screen.findByRole('button', { name: /Ver detalhes/ }));
      await user.click(screen.getByRole('button', { name: 'Desfazer' }));

      expect(await screen.findByText('Ciclo selado.')).toBeInTheDocument();
      expect(axios.get).toHaveBeenCalledTimes(1);
    });
  });
});
