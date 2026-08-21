import { render, screen } from '@testing-library/react';
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

  it('has no group sidebar or group selector', async () => {
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

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });
});
