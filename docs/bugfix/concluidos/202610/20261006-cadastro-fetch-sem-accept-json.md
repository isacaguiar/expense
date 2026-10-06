# Bugfix — Cadastro: erro de validação (422) vira redirecionamento bloqueado por CORS

Versão: 1.0 · Criado em: 20261006 · Branch: `fix/20261006-cadastro-fetch-sem-accept-json`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.
>
> Origem: item 068 do backlog (`docs/backlog/cadastro-fetch-sem-accept-json-redireciona-422.md`, no PR #205 enquanto não for mergeado em `dev`), achado em 2026-10-04 durante a TASK-395 da feature `20261004-metricas-aquisicao-ativacao`.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a correção prevista é só de cliente: acrescentar `Accept: application/json` aos três `fetch` de `frontend/src/pages/RegisterPage.tsx`. Não toca rota, controller nem middleware, e nenhuma regra de autenticação ou autorização muda. Mesmo critério do bugfix vizinho `20260930-login-mensagem-email-nao-verificado` (tela de login, só frontend). **Condição de escalação:** se a correção escolhida passar a ser no servidor (por exemplo, um middleware que force JSON em `api/*`), isso mexe em middleware e no comportamento de todas as rotas: marcar esta caixa e a seguinte e abrir `/nova-feature`.
- [ ] **Migration ou contrato de API** — nenhum schema, rota, payload ou status code muda: o backend já devolve `422` com `{"message", "errors"}` para quem pede JSON (verificado com `curl`, §1); só o frontend passa a pedir. O corpo e o status que o frontend já espera (`RegisterPage.test.tsx` e `mapApiErrors`) são os que a API já manda.
- [ ] **Causa raiz obscura / correção ampla** — causa raiz confirmada e reproduzida (§1), em 1 arquivo de produção (`RegisterPage.tsx`, 3 chamadas). `LoginPage.tsx` também usa `fetch`, mas os dois endpoints dele (`/api/login` e `/api/auth/google/exchange`) não usam `FormRequest` e respondem JSON à mão, então não são afetados (conferido, §1).
- [ ] **Decisão de produto/arquitetura** — o comportamento correto já está decidido e implementado no cliente (a mensagem de campo do 422 via `mapApiErrors`, e "Código inválido ou expirado…" para o código errado); aqui só falta ele chegar à tela. Escolher entre pedir JSON no cliente e forçar JSON no servidor não é decisão nova de produto: o cliente é a correção mínima e o que as telas com `axios` já fazem.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** no cadastro, qualquer erro de validação da API (código de confirmação errado, e-mail inválido, e-mail já usado, senha curta) aparece como erro de conexão. No passo do código a tela mostra "Não foi possível confirmar o código. Verifique sua conexão e tente novamente." em vez de "Código inválido ou expirado…"; no passo do formulário, "Não foi possível iniciar o cadastro. Verifique sua conexão e tente novamente." em vez da mensagem do campo.
- **Reprodução:**
  1. Subir o backend (`php artisan serve`, porta 8000) e o frontend (`npm run dev`, porta 3000), com o `.env` do backend apontando para um banco migrado.
  2. Abrir `http://localhost:3000/app/cadastro`, preencher o formulário com dados válidos e enviar (a API aceita e devolve o passo do código).
  3. Digitar um código de 6 dígitos errado e clicar em "Confirmar e entrar".
  4. A tela mostra a mensagem de conexão e o console registra `Access to fetch at 'http://localhost:3000/' (redirected from 'http://localhost:8000/api/pre-register/verify') from origin 'http://localhost:3000' has been blocked by CORS policy`, seguido de `TypeError: Failed to fetch`.
  - Reproduzido em 2026-10-04 num navegador real contra um backend local. **Não foi reproduzido em produção**; ela roda o mesmo código e o backend não força JSON em lugar nenhum, então o comportamento deve se repetir lá.
  - Prova direta com `curl` (código errado), mesma rota: sem `Accept` → `HTTP/1.1 302 Found` com `Location: http://localhost:3000/`; com `Accept: application/json` → `HTTP/1.1 422` e o corpo `{"message":"Código inválido ou expirado…","errors":{"code":[…]}}`. O mesmo vale para `/api/pre-register` com e-mail inválido (302 sem `Accept`).
- **Esperado vs. atual:** esperado, o `422` chegar ao `RegisterPage` como `res.ok === false` com o corpo JSON, para a tela mostrar a mensagem do campo ou "Código inválido ou expirado…". Atual, o `fetch` rejeita (`TypeError`) e cai no `catch`, que mostra a mensagem de conexão.
- **Causa raiz:**
  - `frontend/src/pages/RegisterPage.tsx:89`, `:138` e `:175` (em `dev`): os três `fetch` (`/api/pre-register`, `/verify` e `/resend`) mandam só `headers: { 'Content-Type': 'application/json' }`, sem `Accept`.
  - Os três endpoints validam por `FormRequest` (`backend/app/Http/Requests/PreRegisterRequest.php`, `PreRegisterVerifyRequest.php`, `PreRegisterResendRequest.php`; rotas em `backend/routes/api.php:30-32`). Quando a validação falha e o request **não espera JSON** (sem `Accept: application/json` e sem `X-Requested-With`), o Laravel responde redirecionando para a URL anterior em vez de `422`. Cross-origin o `Referer` do navegador vem reduzido à origem do frontend, então o `Location` é `http://localhost:3000/`.
  - O navegador segue o redirecionamento para uma página sem cabeçalho CORS e bloqueia a resposta; o `fetch` rejeita e `RegisterPage` cai no `catch`. `backend/app/Exceptions/Handler.php` não sobrescreve esse comportamento e nenhum middleware força JSON (`git grep` por `Accept`, `expectsJson` e `ForceJson` em `backend/app` só acha `Authenticate.php`).
  - **Por que os testes não pegam:** `RegisterPage.test.tsx` simula o `fetch` com `{ ok: false, json: … }` e nunca exercita a resposta real do backend.
  - **Fora do escopo do bug, conferido:** as telas com `axios` (`AcceptInvitePage`, `GroupForm`, `ExpenseForm`) não são afetadas, porque o axios manda `Accept: application/json, text/plain, */*`. `LoginPage.tsx` (`/api/login` e a troca do Google) também não: `AuthController` e `GoogleAuthController` não usam `FormRequest` e respondem JSON à mão.

## 2. Correção

- **O que muda e por quê:** os três `fetch` de `RegisterPage.tsx` passam a usar uma constante `JSON_HEADERS` (`Content-Type: application/json` + `Accept: application/json`). Com o `Accept`, o Laravel trata o request como "espera JSON" e responde a falha de validação com `422` e o corpo `{message, errors}`, que é o que a página já sabia ler, em vez de redirecionar. Corrigido no cliente, e não com um middleware que force JSON no servidor, porque é a mudança mínima, é o que o `axios` das outras telas já faz sozinho e não altera o comportamento de nenhuma outra rota (a alternativa de servidor escalaria a Triagem, ver caixa 1).
- **Arquivos tocados:** `frontend/src/pages/RegisterPage.tsx` (a constante e as 3 trocas de `headers`), `frontend/src/pages/RegisterPage.test.tsx` (3 testes novos), e esta documentação (`docs/bugfix/`).
- **Teste de regressão:** 3 testes em `RegisterPage.test.tsx`, no bloco "validation errors from the API reach the user (regression: requests sent no Accept header)", um por endpoint (pré-cadastro com e-mail já usado, confirmação com código errado, reenvio recusado). Usam um dublê do backend que reproduz o comportamento provado com `curl`: sem `Accept: application/json` o `fetch` rejeita com `TypeError` (o que o navegador fez com o 302 bloqueado por CORS), com ele devolve o `422`. Falham sem a correção (`3 failed, 13 passed`) e passam com ela (`16 passed`); tirar o `Accept` de um endpoint por vez derruba só o teste daquele endpoint. Os mocks antigos respondiam `{ ok: false, json }` para qualquer request e por isso nunca pegaram o defeito.
- **Riscos / efeitos colaterais:** nenhum identificado. O caminho de sucesso não muda, o cabeçalho só altera como o servidor reporta falha de validação, e o backend não é tocado. A mesma armadilha vale para qualquer `fetch` novo contra um endpoint com `FormRequest`; o `axios` não tem o problema. A `ForgotPasswordPage` da feature de recuperação de acesso (ainda fora de `dev`) usa `axios` (conferido em `backend/20260930-recuperacao-acesso`), então não é afetada.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-10-06 | `curl -i -X POST .../api/pre-register/verify` com código errado, uma vez sem `Accept` e outra com `Accept: application/json` (backend local de teste) | Sem `Accept`: `HTTP/1.1 302 Found`, `Location: http://localhost:3000/`. Com `Accept: application/json`: `HTTP/1.1 422 Unprocessable Content`. Causa raiz confirmada, sem tocar o backend |
| 2026-10-06 | **RED** — `cd frontend && npx vitest run src/pages/RegisterPage.test.tsx`, com os 3 testes novos e `RegisterPage.tsx` ainda sem o `Accept` | `3 failed, 13 passed`. Os 3 que falham são os de regressão (um por endpoint), pelo motivo esperado: o dublê do backend rejeita o `fetch` sem `Accept` e a tela mostra "Verifique sua conexão" |
| 2026-10-06 | **GREEN** — o mesmo comando, depois de `JSON_HEADERS` nos 3 `fetch` | `16 passed (16)`. Repetido ao final, com o mesmo resultado |
| 2026-10-06 | Verificação por mutação: tirar o `Accept` de um endpoint por vez em `RegisterPage.tsx` e rodar `RegisterPage.test.tsx` | Cada mutação derruba só o teste do endpoint alterado; os outros dois seguem passando. Mutações revertidas, arquivo final igual ao do commit |
| 2026-10-06 | `cd frontend && npx tsc --noEmit` | Exit 0, sem erros. O diff não acrescenta `any` |
| 2026-10-06 | Bug reproduzido e conferido no navegador (painel do Claude), com `php artisan serve --env=testing` num MySQL descartável na porta 3307 e `npm run dev`: cadastro com código errado e cadastro com e-mail já usado | Passo do código, `POST /api/pre-register/verify` → 422: a tela mostra "Código inválido ou expirado…", sem mensagem de conexão. Passo do formulário, `POST /api/pre-register` → 422: o campo mostra "Este e-mail já está cadastrado.". Backend de teste, banco descartável e `.env.testing` removidos depois; `.claude/launch.json` restaurado com `git checkout` |
| 2026-10-06 | `cd frontend && npx vitest run` (suíte completa), 1ª rodada, com a saída cortada por `tail -8` | `Test Files 36 passed (36)`, `Tests 191 passed (191)`, **mas com `Errors 7 errors`**. Só 36 arquivos aparecem como executados e a 2ª rodada mostrou 43 no total; a soma sugere que os 7 "errors" são arquivos que não rodaram, mas o detalhe foi cortado pelo `tail` e não foi lido, então isso é inferência. Não vale como resultado da suíte |
| 2026-10-06 | `cd frontend && npx vitest run > vitest-full.log` (suíte completa, 2ª rodada, saída inteira) | `Test Files 1 failed \| 42 passed (43)`, `Tests 1 failed \| 293 passed (294)`. A falha é `AcceptInvitePage.test.tsx > shows the backend error message when the token is invalid or expired` (`mockNavigate` chamado com `"/"`). **Arquivo que este bugfix não toca** (`git diff dev` em `frontend/` só traz `RegisterPage.tsx` e `RegisterPage.test.tsx`). Todos os testes de `RegisterPage` passaram |
| 2026-10-06 | Diagnóstico da falha acima: leitura de `frontend/src/pages/AcceptInvitePage.tsx:79` e `npx vitest run src/pages/AcceptInvitePage.test.tsx` isolado, 3 vezes | Flake anterior ao bugfix: no sucesso a página agenda `setTimeout(() => navigate('/'), 2000)` e nunca o limpa. O teste de sucesso deixa o timer pendente e, com a máquina lenta (o arquivo levou 7,7 s para 5 testes), ele dispara dentro do teste seguinte, que exige `mockNavigate` não chamado. Isolado: 1ª vez `no tests` (o worker do vitest não subiu, instabilidade de infra já vista antes nesta máquina; repetir resolve), 2ª e 3ª `5 passed (5)`. Não corrigido aqui: fora do escopo do bug |

## Resolução
Concluído em: 2026-10-06
Branch: fix/20261006-cadastro-fetch-sem-accept-json
PR: https://github.com/isacaguiar/expense/pull/206
