import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SimpleShellLayout from './SimpleShellLayout';

vi.mock('axios');

const currentUser = { name: 'QA Simple Usuario', email: 'qa-simple@example.com' };

describe('SimpleShellLayout', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/api/me')) {
        return Promise.resolve({ data: currentUser });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { message: 'ok' } });
    localStorage.setItem('accessToken', 'a-token');
  });

  it('renders the wordmark, the outlet content and the logged-in user name/initials', async () => {
    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    expect(screen.getByText('Shared')).toBeInTheDocument();
    expect(screen.getByText('Expense')).toBeInTheDocument();
    expect(screen.getByText('Conteúdo Dashboard')).toBeInTheDocument();
    expect(await screen.findByText('QA Simple Usuario')).toBeInTheDocument();
    expect(screen.getByText('QS')).toBeInTheDocument();
  });

  it('shows a generic menu (same visual pattern as the group shell), but no group selector', async () => {
    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(screen.getByRole('navigation')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute('href', '/summary');
    expect(screen.getByRole('link', { name: /Despesas/ })).toHaveAttribute('href', '/expenses');
    expect(screen.getByRole('link', { name: /Pagamentos/ })).toHaveAttribute('href', '/payments');
    expect(screen.getByRole('link', { name: /Participantes/ })).toHaveAttribute('href', '/members');
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('shows "Relatórios" as a top-level item linking to /reports', async () => {
    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(screen.getByRole('link', { name: /Relatórios/ })).toHaveAttribute('href', '/reports');
  });

  it('nests "Meus Grupos", "Minha Conta" and "Alterar Senha" under "Configurações", not as top-level items', async () => {
    render(
      <MemoryRouter initialEntries={['/summary']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/summary" element={<div>Conteúdo Resumo</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Resumo');

    expect(screen.queryByRole('link', { name: /Meus Grupos/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Minha Conta/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Alterar Senha/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Configurações/ })).not.toBeInTheDocument();

    await screen.findByText('Configurações');
    const user = userEvent.setup();
    await user.click(screen.getByText('Configurações'));

    expect(screen.getByRole('link', { name: 'Meus Grupos' })).toHaveAttribute('href', '/meus-grupos');
    expect(screen.getByRole('link', { name: 'Minha Conta' })).toHaveAttribute('href', '/profile');
    expect(screen.getByRole('link', { name: 'Alterar Senha' })).toHaveAttribute('href', '/change-password');
  });

  it('derives the header title from the active sidebar item, even when nested', async () => {
    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    expect(await screen.findByRole('heading', { name: 'Meus Grupos' })).toBeInTheDocument();
  });

  it('opens the mobile nav drawer via the header menu button, and closes it after navigating', async () => {
    const user = userEvent.setup();

    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
            <Route path="/expenses" element={<div>Conteúdo Despesas</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(document.querySelector('.MuiDrawer-paper')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Abrir menu de navegação' }));

    const drawerPaper = await waitFor(() => {
      const paper = document.querySelector('.MuiDrawer-paper');
      expect(paper).not.toBeNull();
      return paper as HTMLElement;
    });
    const drawerLink = within(drawerPaper).getByRole('link', { name: /Despesas/ });

    await user.click(drawerLink);

    await screen.findByText('Conteúdo Despesas');
    await waitFor(() => {
      expect(document.querySelector('.MuiDrawer-paper')).not.toBeInTheDocument();
    });
  });

  it('logs out when "Sair" is clicked, as a top-level item (not nested)', async () => {
    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/meus-grupos" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(screen.queryByRole('link', { name: 'Sair' })).not.toBeInTheDocument();

    const user = userEvent.setup();
    await user.click(screen.getByText('Sair'));

    await waitFor(() => {
      expect(axios.post).toHaveBeenCalledWith(expect.stringContaining('/api/logout'), null, expect.anything());
      expect(localStorage.getItem('accessToken')).toBeNull();
    });
  });
});
