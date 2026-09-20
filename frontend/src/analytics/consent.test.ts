import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * Com o `GA_MEASUREMENT_ID` real vazio em desenvolvimento (ver `src/config.ts`),
 * o caminho "aceitou → carrega o GA" não roda no dev server: é aqui que ele é
 * exercitado, com um ID de teste que nunca sai para a rede — o `<script>` é
 * inspecionado no DOM do jsdom, não executado.
 */
vi.mock('../config', () => ({ GA_MEASUREMENT_ID: 'G-TESTE123', API_BASE_URL: 'http://localhost:8000' }));

function googleScripts(): HTMLScriptElement[] {
  return Array.from(document.querySelectorAll('script')).filter((script) =>
    script.src.includes('googletagmanager.com')
  );
}

function resetPage(): void {
  googleScripts().forEach((script) => script.remove());
  window.dataLayer = [];
  window.SCD_GA_LOADED = false;
  document.cookie = 'scd_consent=; Max-Age=0; Path=/';
}

describe('consentimento e carga do Google Analytics', () => {
  beforeEach(() => {
    resetPage();
    vi.resetModules();
  });

  it('publica o estado padrão negado e não carrega nada sem decisão', async () => {
    const { initAnalytics } = await import('./consent');

    initAnalytics();

    expect(googleScripts()).toHaveLength(0);
    expect(document.cookie).not.toContain('scd_consent');
    expect(Array.from(window.dataLayer[0] as IArguments)).toEqual([
      'consent',
      'default',
      {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
      },
    ]);
  });

  it('aceitar grava o cookie e injeta o gtag com send_page_view desligado', async () => {
    const { initAnalytics, setConsent, isAnalyticsActive } = await import('./consent');

    initAnalytics();
    setConsent('granted');

    expect(document.cookie).toContain('scd_consent=granted');
    expect(isAnalyticsActive()).toBe(true);

    const scripts = googleScripts();
    expect(scripts).toHaveLength(1);
    expect(scripts[0].src).toBe('https://www.googletagmanager.com/gtag/js?id=G-TESTE123');

    const comandos = window.dataLayer.map((entry) => Array.from(entry as IArguments));
    expect(comandos).toContainEqual(['consent', 'update', { analytics_storage: 'granted' }]);
    // Sem isto o primeiro acesso contaria duas vezes: uma pelo gtag, outra pelo RouteTracker.
    expect(comandos).toContainEqual(['config', 'G-TESTE123', { send_page_view: false }]);
  });

  it('recusar grava o cookie e não injeta nada', async () => {
    const { initAnalytics, setConsent, isAnalyticsActive } = await import('./consent');

    initAnalytics();
    setConsent('denied');

    expect(document.cookie).toContain('scd_consent=denied');
    expect(isAnalyticsActive()).toBe(false);
    expect(googleScripts()).toHaveLength(0);

    const comandos = window.dataLayer.map((entry) => Array.from(entry as IArguments));
    expect(comandos).toContainEqual(['consent', 'update', { analytics_storage: 'denied' }]);
  });

  it('consentimento já gravado carrega o analytics sem perguntar de novo', async () => {
    document.cookie = 'scd_consent=granted; Path=/';

    const { initAnalytics, getConsent } = await import('./consent');

    initAnalytics();

    expect(getConsent()).toBe('granted');
    expect(googleScripts()).toHaveLength(1);
  });

  it('não injeta o gtag duas vezes se o aceite se repetir', async () => {
    const { initAnalytics, setConsent } = await import('./consent');

    initAnalytics();
    setConsent('granted');
    setConsent('granted');

    expect(googleScripts()).toHaveLength(1);
  });
});
