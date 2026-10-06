# Cadastro: erro de validação (422) vira redirecionamento bloqueado por CORS e a tela mostra "verifique sua conexão"

ID: 068
Origem: docs/feature/20261004-metricas-aquisicao-ativacao/implementation.md, TASK-395 (achado ao validar os fluxos no navegador)
Criado em: 2026-10-04
Prioridade: ALTA
Status: Resolvido pelo bugfix 20261006-cadastro-fetch-sem-accept-json

## Descrição
Os três `fetch` de `frontend/src/pages/RegisterPage.tsx` (`/api/pre-register`, `/verify` e `/resend`) mandam só `Content-Type: application/json`, sem `Accept: application/json`. Quando a validação do Laravel falha, a exceção só vira JSON se o request "espera JSON"; sem o `Accept`, o Laravel responde **302** para a URL anterior (o `Referer`, que o navegador reduz à origem do frontend). O navegador segue o redirecionamento para uma página sem cabeçalho CORS e bloqueia a resposta, então o `fetch` rejeita com `TypeError: Failed to fetch` e a página cai no ramo de erro de conexão, em vez de mostrar a mensagem de campo que a API mandou.

Reproduzido em 2026-10-04, no navegador, contra um backend local de teste (`php artisan serve --env=testing`):

- `POST /api/pre-register/verify` com código errado: console com `Access to fetch at 'http://localhost:3000/' (redirected from 'http://localhost:8000/api/pre-register/verify') … blocked by CORS policy`, e a tela mostra "Não foi possível confirmar o código. Verifique sua conexão e tente novamente." em vez de "Código inválido ou expirado…".
- Com `curl` sem `Accept`: `HTTP/1.1 302 Found` com `Location: http://localhost:3000/`. Com `Accept: application/json`: `HTTP/1.1 422` e o corpo JSON com `errors.code`.
- O mesmo vale para o 422 de campo do pré-cadastro (e-mail inválido ou já usado), que `RegisterPage` tenta mapear para o campo (`mapApiErrors`) mas nunca recebe.

O backend não força JSON em lugar nenhum (`grep` por `Accept`/`expectsJson` em `backend/app` só acha `Authenticate.php`), e produção usa o mesmo código, então o comportamento deve se repetir lá. Não foi reproduzido em produção.

Os testes não pegam isso porque simulam o `fetch` com `{ ok: false, json: … }` e nunca exercitam a resposta real do backend. As telas que usam `axios` (`AcceptInvitePage`, `GroupForm`, `ExpenseForm`) não são afetadas: o axios manda `Accept: application/json, text/plain, */*`.

## Por que importa
O cadastro é o funil que o piloto de divulgação (itens 061 a 066) vai exercitar. Quem digita o código errado, ou um e-mail já usado, vê uma mensagem de problema de conexão e não sabe corrigir o campo. Também distorce qualquer leitura do funil: a tentativa falha parece abandono.

Tipo sugerido: frontend

## Resolução
Concluído em: 2026-10-06
Bugfix: docs/bugfix/concluidos/202610/20261006-cadastro-fetch-sem-accept-json.md (fluxo BFF, sem feature nem tasks)
PRs: https://github.com/isacaguiar/expense/pull/206

Corrigido no cliente, como descrito: os três `fetch` de `RegisterPage.tsx` passam a mandar `Accept: application/json` (constante `JSON_HEADERS`), então a falha de validação chega como `422` e a tela mostra a mensagem do campo ou "Código inválido ou expirado…". Três testes de regressão, um por endpoint, falham sem a correção. Confirmado também no navegador contra um backend local de teste.
