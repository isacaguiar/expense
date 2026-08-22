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
});
