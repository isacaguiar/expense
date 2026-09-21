export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000';

/**
 * Propriedade do Google Analytics. O default é vazio de propósito: sem a
 * variável definida — `npm run dev`, `vite preview`, build de alguém testando
 * — o analytics fica desligado e não suja a propriedade de produção com
 * tráfego de desenvolvimento. Quem define o valor real é o
 * `deploy-frontend.yml`.
 *
 * O site institucional usa a MESMA propriedade, por `ga_measurement_id` em
 * `site/src/config.php`. Trocar de propriedade exige mudar nos dois lugares:
 * são deploys separados, sem código compartilhado.
 *
 * Nada aqui carrega o GA — o carregamento depende do consentimento, ver
 * `src/analytics/consent.ts`.
 */
export const GA_MEASUREMENT_ID: string = import.meta.env.VITE_GA_MEASUREMENT_ID ?? '';
