import React, { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { trackPageView } from './pageView';

/**
 * Mede cada mudança de rota do app.
 *
 * Existe porque o `gtag('config', ...)` dispara `page_view` só no carregamento
 * do documento: numa SPA, trocar de tela não geraria nada. O `config` vai com
 * `send_page_view: false` (ver `consent.ts`) e a contagem passa a sair daqui,
 * uma vez por rota.
 *
 * Não renderiza nada — precisa apenas estar dentro do Router para enxergar a
 * localização atual.
 *
 * Em desenvolvimento o `React.StrictMode` executa efeitos duas vezes, então a
 * mesma rota aparece duplicada no `dataLayer` local. No build de produção isso
 * não acontece, e em desenvolvimento nada é enviado de qualquer forma, porque
 * `GA_MEASUREMENT_ID` é vazio.
 */
const RouteTracker: React.FC = () => {
  const { pathname } = useLocation();

  useEffect(() => {
    trackPageView(pathname);
  }, [pathname]);

  return null;
};

export default RouteTracker;
