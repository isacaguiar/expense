import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConsentBanner from './ConsentBanner';
import Profile from '../pages/Profile';

vi.mock('axios');

/*
 * A tela de perfil exige autenticação, então a reabertura do banner por ela não
 * é verificável no navegador sem credenciais reais. Aqui `Profile` e
 * `ConsentBanner` são montados juntos — que é como vivem no app, o banner
 * acima de `<Routes>` e o perfil dentro — e a fiação é exercitada por clique.
 */
function montarAppComPerfil() {
  return render(
    <MemoryRouter initialEntries={['/profile']}>
      <ConsentBanner />
      <Profile />
    </MemoryRouter>
  );
}

describe('reabertura do consentimento pelo perfil', () => {
  beforeEach(() => {
    document.cookie = 'scd_consent=; Max-Age=0; Path=/';
    localStorage.setItem('accessToken', 'um-token');
    vi.mocked(axios.get).mockResolvedValue({
      data: {
        name: 'QA',
        email: 'qa@example.com',
        pix: null,
        whatsapp: null,
        notify_whatsapp: false,
        avatar_url: null,
      },
    });
  });

  it('mostra a entrada de preferências e reabre o banner ao clicar', async () => {
    document.cookie = 'scd_consent=granted; Path=/';
    const user = userEvent.setup();

    montarAppComPerfil();

    // Com escolha já gravada, o banner começa fechado.
    await waitFor(() => expect(screen.getByText('Privacidade')).toBeInTheDocument());
    expect(screen.queryByText(/Usamos cookies do Google Analytics/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Preferências de cookies' }));

    expect(await screen.findByText(/Usamos cookies do Google Analytics/)).toBeInTheDocument();
    // A escolha atual fica visível ao reabrir.
    expect(screen.getByText('analytics ativado')).toBeInTheDocument();
  });

  it('trocar a escolha no banner reaberto sobrescreve o cookie', async () => {
    document.cookie = 'scd_consent=granted; Path=/';
    const user = userEvent.setup();

    montarAppComPerfil();

    await waitFor(() => expect(screen.getByText('Privacidade')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: 'Preferências de cookies' }));
    await user.click(await screen.findByRole('button', { name: 'Recusar' }));

    expect(document.cookie).toContain('scd_consent=denied');
    await waitFor(() =>
      expect(screen.queryByText(/Usamos cookies do Google Analytics/)).not.toBeInTheDocument()
    );
  });
});
