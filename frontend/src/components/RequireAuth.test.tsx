import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import RequireAuth from './RequireAuth';

const renderAtDashboard = () =>
  render(
    <MemoryRouter initialEntries={['/meus-grupos']}>
      <Routes>
        <Route path="/" element={<div>Login Page</div>} />
        <Route element={<RequireAuth />}>
          <Route path="/meus-grupos" element={<div>Protected Content</div>} />
        </Route>
      </Routes>
    </MemoryRouter>
  );

describe('RequireAuth', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('redirects to login when there is no access token', () => {
    renderAtDashboard();

    expect(screen.getByText('Login Page')).toBeInTheDocument();
    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  it('renders the protected content when an access token is present', () => {
    localStorage.setItem('accessToken', 'valid-token');

    renderAtDashboard();

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
    expect(screen.queryByText('Login Page')).not.toBeInTheDocument();
  });
});
