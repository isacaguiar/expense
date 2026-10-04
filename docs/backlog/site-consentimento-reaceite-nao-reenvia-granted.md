# Banner de consentimento do site não reenvia `granted` ao aceitar de novo na mesma página

ID: 067
Origem: docs/feature/20261004-metricas-aquisicao-ativacao/plan.md §1 (achado ao ler `site/public/assets/consent.js` durante o Tech Plan)
Criado em: 2026-10-04
Prioridade: BAIXA
Status: Aberto

## Descrição
Em `site/public/assets/consent.js`, `loadAnalytics()` sai cedo quando `window.SCD_GA_LOADED` já é `true` (`:52`), antes de emitir `gtag('consent','update',{analytics_storage:'granted'})` (`:58`). Se a pessoa aceita, depois revoga em "Preferências de cookies" e aceita de novo sem recarregar a página, o `update granted` da segunda vez nunca sai e o GA continua com o armazenamento de analytics negado até o próximo carregamento. O app React tinha o mesmo defeito em `frontend/src/analytics/consent.ts:67`, corrigido na feature de métricas (TASK de gate de consentimento).

## Por que importa
Só subconta medição de quem muda de ideia na mesma página; não expõe dado. Não há prazo natural, mas o contrato do consentimento do site e do app deve ser o mesmo, já que os dois leem o mesmo cookie `scd_consent`.

Tipo sugerido: frontend
