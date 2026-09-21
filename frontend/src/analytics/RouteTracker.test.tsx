import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useNavigate } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RouteTracker from './RouteTracker';

/*
 * Em desenvolvimento o `GA_MEASUREMENT_ID` é vazio e nada é medido — por isso o
 * ID fictício aqui. Nenhuma requisição sai: o jsdom não executa o script do
 * gtag, e o que estes testes leem é o `dataLayer`.
 */
vi.mock('../config', () => ({ GA_MEASUREMENT_ID: 'G-TESTE123', API_BASE_URL: 'http://localhost:8000' }));

function paginasMedidas(): Record<string, unknown>[] {
  return (window.dataLayer ?? [])
    .map((entry) => Array.from(entry as IArguments))
    .filter((args) => args[0] === 'event' && args[1] === 'page_view')
    .map((args) => args[2] as Record<string, unknown>);
}

const Atalhos = () => {
  const navigate = useNavigate();

  return (
    <>
      <button onClick={() => navigate('/meus-grupos')}>ir para grupos</button>
      <button onClick={() => navigate('/groups/42/expenses/1337')}>ir para despesa</button>
    </>
  );
};

async function montarComConsentimento(entrada: string) {
  document.cookie = 'scd_consent=granted; Path=/';

  const { initAnalytics } = await import('./consent');
  initAnalytics();

  return render(
    <MemoryRouter initialEntries={[entrada]}>
      <RouteTracker />
      <Atalhos />
    </MemoryRouter>
  );
}

describe('RouteTracker', () => {
  beforeEach(() => {
    window.dataLayer = [];
    window.SCD_GA_LOADED = false;
    document.querySelectorAll('script[src*="googletagmanager"]').forEach((s) => s.remove());
    document.cookie = 'scd_consent=; Max-Age=0; Path=/';
    vi.resetModules();
  });

  it('mede uma página por rota visitada, sem duplicar a primeira', async () => {
    const user = userEvent.setup();
    await montarComConsentimento('/');

    await user.click(screen.getByRole('button', { name: 'ir para grupos' }));
    await user.click(screen.getByRole('button', { name: 'ir para despesa' }));

    expect(paginasMedidas().map((p) => p.page_path)).toEqual([
      '/app/',
      '/app/meus-grupos',
      '/app/groups/:id/expenses/:id',
    ]);
  });

  it('nunca envia query string, nem em page_location', async () => {
    await montarComConsentimento('/aceitar-convite?email=convidado@exemplo.com&token=abc123');

    const medidas = paginasMedidas();

    expect(medidas).toHaveLength(1);
    expect(medidas[0].page_path).toBe('/app/aceitar-convite');
    expect(JSON.stringify(medidas[0])).not.toContain('?');
    expect(JSON.stringify(medidas[0])).not.toContain('convidado@exemplo.com');
    expect(JSON.stringify(medidas[0])).not.toContain('abc123');
  });

  it('não mede nada enquanto não há consentimento', async () => {
    const { initAnalytics } = await import('./consent');
    initAnalytics();

    render(
      <MemoryRouter initialEntries={['/meus-grupos']}>
        <RouteTracker />
      </MemoryRouter>
    );

    expect(paginasMedidas()).toHaveLength(0);
  });
});
