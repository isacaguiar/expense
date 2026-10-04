import { consumeCampaign } from './campaign';
import { sanitizePath } from './sanitizePath';

/**
 * Monta o `page_location` enviado ao GA: `origin` + caminho normalizado, mais
 * a campanha UTM pendente (`campaign.ts`) no primeiro envio.
 *
 * O gtag, se deixado sozinho, usa `document.location`, que carrega a query
 * string inteira — na tela do convite, o e-mail da pessoa e um token válido
 * (`pages/AcceptInvitePage.tsx`). Por isso **todo** envio, `page_view` ou
 * evento de ação, sobrescreve esse parâmetro com o resultado desta função, e a
 * regra mora num lugar só: página e evento não podem divergir.
 *
 * Só chame quando o envio de fato vai acontecer: a chamada **consome** a
 * campanha pendente, então chamá-la antes de checar o consentimento perderia
 * a origem de quem ainda não aceitou o banner.
 *
 * Recebe o caminho já com o prefixo `/app` sob o qual o app é servido.
 */
export function buildPageLocation(caminhoComPrefixo: string): string {
  return `${window.location.origin}${sanitizePath(caminhoComPrefixo)}${consumeCampaign()}`;
}
