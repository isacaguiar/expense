import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupShellLayout from './GroupShellLayout';

vi.mock('axios');

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
  };
});

const groups = [
  { id: 1, name: 'Grupo A' },
  { id: 2, name: 'Grupo B' },
];

const currentUser = { name: 'QA Shell Usuario', email: 'qa-shell@example.com' };

function mockGetResponses() {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/api/groups')) {
      return Promise.resolve({ data: groups });
    }
    if (url.includes('/api/me')) {
      return Promise.resolve({ data: currentUser });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderShell(initialPath: string) {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route element={<GroupShellLayout />}>
          <Route path="/groups/:id/summary" element={<div>Conteúdo Resumo</div>} />
          <Route path="/groups/:id/expenses" element={<div>Conteúdo Despesas</div>} />
        </Route>
      </Routes>
    </MemoryRouter>
  );
}

describe('GroupShellLayout', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(axios.get).mockReset();
    mockGetResponses();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 'a-token');
  });

  it('renders the sidebar with real links for Home/Despesas/Participantes and placeholders for the rest', async () => {
    renderShell('/groups/1/summary');

    await screen.findByText('Conteúdo Resumo');

    expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute('href', '/groups/1/summary');
    expect(screen.getByRole('link', { name: /Despesas/ })).toHaveAttribute('href', '/groups/1/expenses');
    expect(screen.getByRole('link', { name: /Participantes/ })).toHaveAttribute('href', '/groups/1/members');
    expect(screen.getByRole('link', { name: /Pagamentos/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: /Relatórios/ })).toHaveAttribute('href', '#');
  });

  it('nests "Meus Grupos", "Minha Conta" and "Alterar Senha" under "Configurações" instead of linking straight to the group edit page', async () => {
    const user = userEvent.setup();
    renderShell('/groups/1/summary');

    await screen.findByText('Conteúdo Resumo');

    expect(screen.queryByRole('link', { name: /Configurações/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /groups\/1\/edit/ })).not.toBeInTheDocument();

    await user.click(screen.getByText('Configurações'));

    expect(screen.getByRole('link', { name: 'Meus Grupos' })).toHaveAttribute('href', '/dashboard');
    expect(screen.getByRole('link', { name: 'Minha Conta' })).toHaveAttribute('href', '/profile');
    expect(screen.getByRole('link', { name: 'Alterar Senha' })).toHaveAttribute('href', '/change-password');
  });

  it('shows the logged-in user name and initials from GET /api/me', async () => {
    renderShell('/groups/1/summary');

    expect(await screen.findByText('QA Shell Usuario')).toBeInTheDocument();
    expect(screen.getByText('QS')).toBeInTheDocument();
  });

  it('derives the header title from the active sidebar item', async () => {
    renderShell('/groups/1/expenses');

    await screen.findByText('Conteúdo Despesas');

    expect(screen.getByRole('heading', { name: 'Despesas' })).toBeInTheDocument();
  });

  it('navigates to the same page for the newly selected group when the group dropdown changes', async () => {
    const user = userEvent.setup();

    renderShell('/groups/1/expenses');

    await screen.findByText('Conteúdo Despesas');

    await user.click(await screen.findByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Grupo B' }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/2/expenses');
  });

  it('logs out when "Sair" is clicked, as a top-level item (not nested)', async () => {
    renderShell('/groups/1/summary');

    await screen.findByText('Conteúdo Resumo');

    expect(screen.queryByRole('link', { name: 'Sair' })).not.toBeInTheDocument();

    const user = userEvent.setup();
    await user.click(screen.getByText('Sair'));

    await waitFor(() => {
      expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/logout'), null, expect.anything());
      expect(localStorage.getItem('accessToken')).toBeNull();
      expect(navigateMock).toHaveBeenCalledWith('/');
    });
  });
});
