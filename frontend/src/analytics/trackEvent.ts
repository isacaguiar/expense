import { gtag, isAnalyticsActive } from './consent';
import { buildPageLocation } from './pageLocation';

export type SignUpMethod = 'email' | 'google' | 'invite';

/**
 * Eventos de ação medidos e, para cada um, os únicos parâmetros que podem sair.
 * Lista fechada de propósito: nenhum evento leva e-mail, nome, valor, token nem
 * ID de grupo ou de despesa. Para medir uma ação nova, ela entra aqui, na
 * assinatura abaixo e em `docs/analytics/README.md`.
 */
const PARAMETROS_PERMITIDOS: Record<string, readonly string[]> = {
  sign_up: ['method'],
  group_created: [],
  expense_created: [],
};

const temPropria = (objeto: object, chave: string): boolean =>
  Object.prototype.hasOwnProperty.call(objeto, chave);

export function trackEvent(name: 'sign_up', params: { method: SignUpMethod }): void;
export function trackEvent(name: 'group_created' | 'expense_created'): void;

/**
 * Envia um evento de ação ao GA.
 *
 * Só envia com o consentimento atual `granted` e o gtag carregado. **Não**
 * enfileira quando inativo: um evento enfileirado seria processado assim que
 * alguém aceitasse o banner, medindo retroativamente o que aconteceu antes do
 * consentimento.
 *
 * Chame depois de confirmado o sucesso da API e dentro do handler da ação,
 * nunca em efeito de montagem — recarregar a tela não deve repetir o evento.
 */
export function trackEvent(name: string, params: Record<string, unknown> = {}): void {
  if (!isAnalyticsActive() || !temPropria(PARAMETROS_PERMITIDOS, name)) {
    return;
  }

  const permitidos: Record<string, unknown> = {};

  for (const chave of PARAMETROS_PERMITIDOS[name]) {
    if (temPropria(params, chave)) {
      permitidos[chave] = params[chave];
    }
  }

  gtag('event', name, {
    ...permitidos,
    // O gtag usaria o endereço real da página: ver `pageLocation.ts`.
    page_location: buildPageLocation(window.location.pathname),
  });
}
