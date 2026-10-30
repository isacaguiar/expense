import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * ID fictício: com o real vazio em desenvolvimento nada seria medido. Nenhuma
 * requisição sai — o jsdom não executa o script do gtag, e o que estes testes
 * leem é o `dataLayer`.
 */
vi.mock('../config', () => ({ GA_MEASUREMENT_ID: 'G-TESTE123', API_BASE_URL: 'http://localhost:8000' }));

type Comando = unknown[];

function comandos(): Comando[] {
  return (window.dataLayer ?? []).map((entry) => Array.from(entry as IArguments));
}

function eventosDeAcao(): Comando[] {
  return comandos().filter((comando) => comando[0] === 'event' && comando[1] !== 'page_view');
}

function paginasMedidas(): Record<string, unknown>[] {
  return comandos()
    .filter((comando) => comando[0] === 'event' && comando[1] === 'page_view')
    .map((comando) => comando[2] as Record<string, unknown>);
}

function resetPage(): void {
  document.querySelectorAll('script[src*="googletagmanager"]').forEach((script) => script.remove());
  window.dataLayer = [];
  window.SCD_GA_LOADED = false;
  document.cookie = 'scd_consent=; Max-Age=0; Path=/';
  window.history.pushState({}, '', '/');
}

async function carregar(consentimento: 'granted' | null) {
  if (consentimento) {
    document.cookie = `scd_consent=${consentimento}; Path=/`;
  }

  const consent = await import('./consent');
  consent.initAnalytics();

  const { trackEvent } = await import('./trackEvent');
  const { trackPageView } = await import('./pageView');

  return { consent, trackEvent, trackPageView };
}

describe('trackEvent', () => {
  beforeEach(() => {
    resetPage();
    vi.resetModules();
  });

  // Pega a função que envia sem olhar o consentimento.
  it('não envia nada enquanto não há consentimento', async () => {
    const { trackEvent } = await carregar(null);

    trackEvent('group_created');

    expect(eventosDeAcao()).toHaveLength(0);
  });

  // Pega a função que enfileira o evento: ele seria processado no aceite seguinte.
  it('não enfileira o evento para enviar depois do aceite', async () => {
    const { consent, trackEvent } = await carregar(null);

    trackEvent('group_created');
    consent.setConsent('granted');

    expect(eventosDeAcao()).toHaveLength(0);
  });

  // Pega a função que ignora o gate de revogação (TASK-383).
  it('para de enviar depois que o consentimento é revogado', async () => {
    const { consent, trackEvent } = await carregar('granted');

    trackEvent('group_created');
    consent.setConsent('denied');
    trackEvent('expense_created');

    expect(eventosDeAcao()).toHaveLength(1);
  });

  it('envia o evento com os parâmetros permitidos e a tela atual', async () => {
    window.history.pushState({}, '', '/app/cadastro');
    const { trackEvent } = await carregar('granted');

    trackEvent('sign_up', { method: 'email' });

    expect(eventosDeAcao()).toEqual([
      ['event', 'sign_up', { method: 'email', page_location: `${window.location.origin}/app/cadastro` }],
    ]);
  });

  it('envia evento sem parâmetro só com a tela atual', async () => {
    window.history.pushState({}, '', '/app/groups/novo');
    const { trackEvent } = await carregar('granted');

    trackEvent('group_created');

    expect(eventosDeAcao()).toEqual([
      ['event', 'group_created', { page_location: `${window.location.origin}/app/groups/novo` }],
    ]);
  });

  // Pega a função que repassa o que receber: um e-mail ou valor chegaria ao Google.
  it('descarta parâmetro fora da lista permitida', async () => {
    const { trackEvent } = await carregar('granted');
    const comDadoPessoal = { method: 'email', email: 'ana@exemplo.com', valor: 150 } as { method: 'email' };

    trackEvent('sign_up', comDadoPessoal);

    expect(eventosDeAcao()[0][2]).toEqual({
      method: 'email',
      page_location: `${window.location.origin}/`,
    });
    expect(JSON.stringify(eventosDeAcao())).not.toContain('ana@exemplo.com');
    expect(JSON.stringify(eventosDeAcao())).not.toContain('150');
  });

  it('não envia um evento que não está na lista', async () => {
    const { trackEvent } = await carregar('granted');

    trackEvent('login' as never);

    expect(eventosDeAcao()).toHaveLength(0);
  });

  // Pega o gtag usando o endereço real do documento: a URL do convite carrega
  // o e-mail da pessoa e um token válido.
  it('na rota do convite, o evento não leva query, e-mail nem token', async () => {
    window.history.pushState({}, '', '/app/aceitar-convite?email=convidado@exemplo.com&token=abc123');
    const { trackEvent } = await carregar('granted');

    trackEvent('sign_up', { method: 'invite' });

    expect(eventosDeAcao()[0][2]).toEqual({
      method: 'invite',
      page_location: `${window.location.origin}/app/aceitar-convite`,
    });
    expect(JSON.stringify(eventosDeAcao())).not.toContain('?');
    expect(JSON.stringify(eventosDeAcao())).not.toContain('convidado@exemplo.com');
    expect(JSON.stringify(eventosDeAcao())).not.toContain('abc123');
  });

  it('IDs numéricos do caminho viram :id', async () => {
    window.history.pushState({}, '', '/app/groups/42/expenses/1337');
    const { trackEvent } = await carregar('granted');

    trackEvent('expense_created');

    expect(eventosDeAcao()[0][2]).toEqual({
      page_location: `${window.location.origin}/app/groups/:id/expenses/:id`,
    });
  });

  // Pega o montador duplicado: página e evento passariam a divergir.
  it('evento e page_view montam o mesmo page_location para a mesma tela', async () => {
    window.history.pushState({}, '', '/app/groups/42/expenses?filtro=pendentes');
    const { trackEvent, trackPageView } = await carregar('granted');

    trackPageView('/groups/42/expenses');
    trackEvent('expense_created');

    expect(paginasMedidas()[0].page_location).toBe(`${window.location.origin}/app/groups/:id/expenses`);
    expect((eventosDeAcao()[0][2] as Record<string, unknown>).page_location).toBe(
      paginasMedidas()[0].page_location
    );
  });
});
