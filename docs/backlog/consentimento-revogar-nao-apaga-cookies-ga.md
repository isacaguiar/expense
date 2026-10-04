# Revogar o consentimento não apaga os cookies `_ga*` do navegador

ID: 069
Origem: docs/feature/20261004-metricas-aquisicao-ativacao/implementation.md, TASK-395 (achado ao validar a revogação no navegador)
Criado em: 2026-10-04
Prioridade: BAIXA
Status: Aberto

## Descrição
`setConsent('denied')` (`frontend/src/analytics/consent.ts`) só emite `gtag('consent','update',{analytics_storage:'denied'})`, e o `consent.js` do site faz o mesmo. Depois de aceitar e revogar, o envio de `page_view` e eventos é interrompido (conferido no navegador com um ID fictício: a contagem de eventos não mudou ao navegar), mas os cookies `_ga` e `_ga_<ID>` criados enquanto havia consentimento continuam no navegador até expirarem. O script do Google também continua carregado na página até a próxima recarga, já sem enviar nada.

## Por que importa
Os cookies ficam inertes, então não há medição indevida, e a política de privacidade só promete que "a medição deixa de acontecer a partir daquele momento" (`site/src/legal/privacidade.php`, item 6). Mesmo assim, quem recusa depois de ter aceitado costuma esperar que os cookies saiam, e apagá-los deixa o comportamento coerente com a escolha. Vale decidir junto com o item 067 (re-aceite no site), que mexe no mesmo trecho do `consent.js`.

Tipo sugerido: frontend
