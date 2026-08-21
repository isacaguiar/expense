import { render, screen } from '@testing-library/react';
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
  });

  it('renders the wordmark, the outlet content and the logged-in user name/initials', async () => {
    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/dashboard" element={<div>Conteúdo Dashboard</div>} />
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
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/dashboard" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(screen.getByRole('navigation')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute('href', '/summary');
    expect(screen.getByRole('link', { name: /Despesas/ })).toHaveAttribute('href', '/expenses');
    expect(screen.getByRole('link', { name: /Participantes/ })).toHaveAttribute('href', '#');
    expect(screen.getByRole('link', { name: /Pagamentos/ })).toHaveAttribute('href', '#');
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('shows "Relatórios" as a top-level placeholder item', async () => {
    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/dashboard" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    await screen.findByText('Conteúdo Dashboard');

    expect(screen.getByRole('link', { name: /Relatórios/ })).toHaveAttribute('href', '#');
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

    expect(screen.getByRole('link', { name: 'Meus Grupos' })).toHaveAttribute('href', '/dashboard');
    expect(screen.getByRole('link', { name: 'Minha Conta' })).toHaveAttribute('href', '/profile');
    expect(screen.getByRole('link', { name: 'Alterar Senha' })).toHaveAttribute('href', '/change-password');
  });

  it('derives the header title from the active sidebar item, even when nested', async () => {
    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route element={<SimpleShellLayout />}>
            <Route path="/dashboard" element={<div>Conteúdo Dashboard</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    );

    expect(await screen.findByRole('heading', { name: 'Meus Grupos' })).toBeInTheDocument();
  });
});
