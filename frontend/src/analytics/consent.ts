import { GA_MEASUREMENT_ID } from '../config';

/**
 * Consentimento de cookies e carregamento condicional do Google Analytics.
 *
 * Sem um "aceitar" explícito, nenhum script do Google entra no DOM e nenhum
 * cookie de analytics é criado. O estado padrão do Consent Mode v2 é negado e
 * é publicado por `initAnalytics()`, antes de qualquer carregamento.
 *
 * O cookie `scd_consent` é o MESMO do site institucional, que roda na mesma
 * origem (`site/public/assets/consent.js`). Quem aceitou no site não é
 * perguntado de novo no app, e vice-versa. Mudar o nome do cookie ou os
 * valores aceitos aqui exige mudar lá também: são deploys separados, sem
 * código compartilhado.
 *
 * Com `GA_MEASUREMENT_ID` vazio (desenvolvimento), nada é carregado em nenhuma
 * hipótese — ver `src/config.ts`.
 */

export type ConsentDecision = 'granted' | 'denied';

const COOKIE = 'scd_consent';

/** 180 dias: consentimento renovável, não eterno. */
const MAX_AGE = 60 * 60 * 24 * 180;

type GtagCommand =
  | ['consent', 'default' | 'update', Record<string, string>]
  | ['js', Date]
  | ['config', string, Record<string, unknown>?]
  | ['event', string, Record<string, unknown>?];

declare global {
  interface Window {
    dataLayer: unknown[];
    /** Evita injetar o gtag duas vezes se o usuário aceitar mais de uma vez. */
    SCD_GA_LOADED?: boolean;
  }
}

/*
 * O snippet oficial do Google empurra o objeto `arguments`, não um array — é o
 * formato que o processamento do dataLayer espera. O cast mantém isso e ainda
 * dá tipo aos comandos na chamada.
 */
const gtag = function gtagCommand(this: unknown) {
  window.dataLayer = window.dataLayer || [];
  // eslint-disable-next-line prefer-rest-params
  window.dataLayer.push(arguments);
} as (...args: GtagCommand) => void;

export function getConsent(): ConsentDecision | null {
  const match = document.cookie.match(/(?:^|;\s*)scd_consent=(granted|denied)/);

  return match ? (match[1] as ConsentDecision) : null;
}

function rememberConsent(decision: ConsentDecision): void {
  // `Secure` só em https: em desenvolvimento o app roda em http e a escolha
  // precisa sobreviver do mesmo jeito.
  const secure = window.location.protocol === 'https:' ? '; Secure' : '';

  document.cookie = `${COOKIE}=${decision}; Path=/; Max-Age=${MAX_AGE}; SameSite=Lax${secure}`;
}

function loadAnalytics(): void {
  if (!GA_MEASUREMENT_ID || window.SCD_GA_LOADED) {
    return;
  }

  window.SCD_GA_LOADED = true;

  gtag('consent', 'update', { analytics_storage: 'granted' });

  const script = document.createElement('script');
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(GA_MEASUREMENT_ID)}`;
  document.head.appendChild(script);

  gtag('js', new Date());

  // `send_page_view: false` porque quem dispara `page_view` é o `RouteTracker`,
  // a cada mudança de rota. Sem isto, o primeiro acesso contaria duas vezes.
  gtag('config', GA_MEASUREMENT_ID, { send_page_view: false });
}

/** Já existe consentimento concedido e o gtag foi carregado nesta sessão? */
export function isAnalyticsActive(): boolean {
  return Boolean(window.SCD_GA_LOADED);
}

/**
 * Publica o estado padrão (negado) e, se já houver consentimento gravado,
 * carrega o analytics. Chamado uma vez, no `main.tsx`, antes de renderizar.
 */
export function initAnalytics(): void {
  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
  });

  if (getConsent() === 'granted') {
    loadAnalytics();
  }
}

/** Grava a escolha do usuário e aplica o efeito imediatamente. */
export function setConsent(decision: ConsentDecision): void {
  rememberConsent(decision);

  if (decision === 'granted') {
    loadAnalytics();

    return;
  }

  // Numa primeira visita isto é redundante (o padrão já é negado), mas é o que
  // faz a revogação valer na hora, sem esperar o próximo carregamento.
  gtag('consent', 'update', { analytics_storage: 'denied' });
}

export { gtag };
