import { render, screen } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

vi.mock('axios');

describe('App routing', () => {
  beforeEach(() => {
    localStorage.setItem('accessToken', 'a-token');
    vi.mocked(axios.get).mockImplementation((url: string) => {
      // Antes de `/api/me`, que também casa com `/api/me/overview`.
      if (url.includes('/api/me/overview')) {
        return Promise.resolve({ data: { user: { id: 1, name: 'QA' }, groups: [], truncated: false } });
      }
      if (url.includes('/api/me')) return Promise.resolve({ data: { id: 1, name: 'QA', email: 'qa@example.com' } });
      if (url.includes('/api/groups')) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
  });

  it('redirects the legacy /dashboard route to /meus-grupos, rendering the groups page', async () => {
    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <App />
      </MemoryRouter>
    );

    expect(await screen.findByLabelText('Buscar grupo')).toBeInTheDocument();
  });

  it('serves the Painel at /painel, inside the simple shell with its title (TASK-422)', async () => {
    render(
      <MemoryRouter initialEntries={['/painel']}>
        <App />
      </MemoryRouter>
    );

    expect(await screen.findByText('Você ainda não participa de nenhum grupo.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Painel' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Painel/ })).toHaveAttribute('href', '/painel');
  });
});
