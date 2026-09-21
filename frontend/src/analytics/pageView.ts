import { gtag, isAnalyticsActive } from './consent';
import { sanitizePath } from './sanitizePath';

/**
 * Prefixo sob o qual o app é servido. `useLocation()` entrega o caminho **sem**
 * ele, por causa do `basename="/app"` em `main.tsx`; recolocá-lo faz as telas
 * do app e as páginas do site conviverem legíveis no mesmo relatório — que é
 * consequência de compartilharem a propriedade do GA.
 */
const BASENAME = '/app';

/**
 * Mede uma tela.
 *
 * Só envia com consentimento concedido e gtag carregado. **Não** enfileira no
 * `dataLayer` quando inativo: eventos enfileirados seriam processados assim que
 * alguém aceitasse, medindo retroativamente telas visitadas antes do
 * consentimento — exatamente o que esta feature existe para impedir.
 *
 * `page_location` é montado a partir do caminho já normalizado em vez de
 * deixar o gtag usar `document.location`, que carregaria a query string do
 * convite (e-mail e token) para dentro do evento.
 */
export function trackPageView(pathname: string): void {
  if (!isAnalyticsActive()) {
    return;
  }

  const caminho = BASENAME + sanitizePath(pathname);

  gtag('event', 'page_view', {
    page_path: caminho,
    page_location: `${window.location.origin}${caminho}`,
  });
}
