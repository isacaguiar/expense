# Implementation — Home pública do Shared Expense

Versão: 1.0 · Criado em: 20261113

| Task ID | Status | Data | Comandos executados / resultado | Observações |
|---|---|---|---|---|
| TASK-614 | Implementada | 20261113 | PHP 8.4 (WASM): render da home, Termos e Privacidade com exit 0. Asserções no HTML renderizado: título, descrição, canonical, CTAs, valores, limite e ausência de AdSense. Chromium headless: 320×700, 360×800, 390×844, 768×900 e 1440×900 sem rolagem horizontal. | CTA acima do banner em todas as resoluções. Em 320×700 o exemplo fica abaixo da primeira dobra; em 360×800 e 390×844 seu título fica acima do banner. Capturas conferidas em celular e desktop. |
| TASK-615 | Implementada | 20261113 | Teste novo falhou pelo título antigo antes da correção. `npm test -- src/pages/RegisterPage.test.tsx`: 23/23. `npx tsc --noEmit`: exit 0. `npm run build`: exit 0, aviso de tamanho de chunk. | Links do rodapé compartilhado abrem na origem do site em nova aba para não perder o formulário. Sem alteração nas chamadas de cadastro. |
