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

function paginasMedidas(): Record<string, unknown>[] {
  return comandos()
    .filter((comando) => comando[0] === 'event' && comando[1] === 'page_view')
    .map((comando) => comando[2] as Record<string, unknown>);
}

function eventosDeAcao(): Comando[] {
  return comandos().filter((comando) => comando[0] === 'event' && comando[1] !== 'page_view');
}

function resetPage(): void {
  document.querySelectorAll('script[src*="googletagmanager"]').forEach((script) => script.remove());
  window.dataLayer = [];
  window.SCD_GA_LOADED = false;
  document.cookie = 'scd_consent=; Max-Age=0; Path=/';
  window.localStorage.clear();
  window.sessionStorage.clear();
  window.history.pushState({}, '', '/');
}

/** Abre o app em `url` (como se a pessoa tivesse clicado num link) e inicializa o analytics. */
async function abrir(url: string, consentimento: 'granted' | null) {
  window.history.pushState({}, '', url);

  if (consentimento) {
    document.cookie = `scd_consent=${consentimento}; Path=/`;
  }

  const consent = await import('./consent');
  consent.initAnalytics();

  const { trackEvent } = await import('./trackEvent');
  const { trackPageView } = await import('./pageView');

  return { consent, trackEvent, trackPageView };
}

const origem = () => window.location.origin;

describe('campanha UTM', () => {
  beforeEach(() => {
    resetPage();
    vi.resetModules();
  });

  // Pega o código que não captura a campanha, ou que a repete em todo envio.
  it('anexa os UTM válidos ao primeiro page_view medido e a nenhum seguinte', async () => {
    const { trackPageView } = await abrir(
      '/app/cadastro?utm_source=facebook&utm_medium=comunidade&utm_campaign=piloto-30d&utm_content=post-01',
      'granted'
    );

    trackPageView('/cadastro');
    trackPageView('/meus-grupos');

    expect(paginasMedidas().map((p) => p.page_location)).toEqual([
      `${origem()}/app/cadastro?utm_source=facebook&utm_medium=comunidade&utm_campaign=piloto-30d&utm_content=post-01`,
      `${origem()}/app/meus-grupos`,
    ]);
  });

  // Pega o filtro frouxo: um valor livre na URL viraria dado enviado ao Google.
  it('descarta só o parâmetro cujo valor é inválido', async () => {
    const campanhaLonga = 'a'.repeat(65);
    const contentNoLimite = 'a'.repeat(64);
    const { trackPageView } = await abrir(
      `/app/cadastro?utm_source=Face%20book!&utm_medium=comunidade&utm_campaign=${campanhaLonga}&utm_content=${contentNoLimite}`,
      'granted'
    );

    trackPageView('/cadastro');

    expect(paginasMedidas()[0].page_location).toBe(
      `${origem()}/app/cadastro?utm_medium=comunidade&utm_content=${contentNoLimite}`
    );
  });

  it('sem nenhum UTM válido não sobra "?" no page_location', async () => {
    const { trackPageView } = await abrir('/app/cadastro?utm_source=Inválido!&utm_term=nao-vale', 'granted');

    trackPageView('/cadastro');

    expect(paginasMedidas()[0].page_location).toBe(`${origem()}/app/cadastro`);
  });

  // Pega a leitura da URL só na hora de enviar: quem chega por um link de
  // campanha ainda não aceitou o banner, e a rota seguinte já não tem a query.
  it('a campanha capturada antes do aceite sai no primeiro envio depois dele, e nada sai antes', async () => {
    const { consent, trackPageView } = await abrir('/app/cadastro?utm_source=facebook&utm_medium=comunidade', null);

    trackPageView('/cadastro');
    expect(paginasMedidas()).toHaveLength(0);

    consent.setConsent('granted');
    trackPageView('/meus-grupos');
    trackPageView('/groups');

    expect(paginasMedidas().map((p) => p.page_location)).toEqual([
      `${origem()}/app/meus-grupos?utm_source=facebook&utm_medium=comunidade`,
      `${origem()}/app/groups`,
    ]);
  });

  // O GA4 deriva a campanha da sessão do primeiro hit, seja ele página ou evento.
  it('quando o primeiro envio é um evento de ação, ele leva a campanha e o page_view seguinte não', async () => {
    const { trackEvent, trackPageView } = await abrir(
      '/app/cadastro?utm_source=instagram&utm_medium=social',
      'granted'
    );

    trackEvent('sign_up', { method: 'email' });
    trackPageView('/meus-grupos');

    expect(eventosDeAcao()[0][2]).toEqual({
      method: 'email',
      page_location: `${origem()}/app/cadastro?utm_source=instagram&utm_medium=social`,
    });
    expect(paginasMedidas()[0].page_location).toBe(`${origem()}/app/meus-grupos`);
  });

  // Pega o montador que passasse a repassar a query inteira ao ganhar suporte a UTM.
  it('e-mail, token e google_code nunca chegam ao page_location, mesmo junto de UTM', async () => {
    const { trackPageView } = await abrir(
      '/app/aceitar-convite?email=convidado@exemplo.com&token=abc123&google_code=zzz999&utm_source=email&utm_medium=convite',
      'granted'
    );

    trackPageView('/aceitar-convite');

    expect(paginasMedidas()[0].page_location).toBe(`${origem()}/app/aceitar-convite?utm_source=email&utm_medium=convite`);
    expect(JSON.stringify(comandos())).not.toContain('convidado@exemplo.com');
    expect(JSON.stringify(comandos())).not.toContain('abc123');
    expect(JSON.stringify(comandos())).not.toContain('zzz999');
  });

  // Pega quem "ajuda" guardando a campanha em storage ou cookie antes do consentimento.
  it('não grava a campanha no aparelho', async () => {
    await abrir('/app/cadastro?utm_source=facebook&utm_medium=comunidade', null);

    expect(window.localStorage.length).toBe(0);
    expect(window.sessionStorage.length).toBe(0);
    expect(document.cookie).not.toContain('utm');
    expect(document.cookie).not.toContain('facebook');
  });
});
