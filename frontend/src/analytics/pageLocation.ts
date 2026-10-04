import { sanitizePath } from './sanitizePath';

/**
 * Monta o `page_location` enviado ao GA: `origin` + caminho normalizado.
 *
 * O gtag, se deixado sozinho, usa `document.location`, que carrega a query
 * string inteira — na tela do convite, o e-mail da pessoa e um token válido
 * (`pages/AcceptInvitePage.tsx`). Por isso **todo** envio, `page_view` ou
 * evento de ação, sobrescreve esse parâmetro com o resultado desta função, e a
 * regra mora num lugar só: página e evento não podem divergir.
 *
 * Recebe o caminho já com o prefixo `/app` sob o qual o app é servido.
 */
export function buildPageLocation(caminhoComPrefixo: string): string {
  return `${window.location.origin}${sanitizePath(caminhoComPrefixo)}`;
}
