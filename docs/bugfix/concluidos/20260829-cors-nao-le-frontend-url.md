# Bugfix — CORS não permite o frontend de produção (config não lê FRONTEND_URL)

Versão: 1.0 · Criado em: 20260829 · Branch: `fix/20260829-cors-nao-le-frontend-url`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**

- [ ] **Auth / autorização / dado sensível** — rotas/controllers/middleware de auth, Pix, grupos, despesas, usuários.
- [ ] **Migration ou contrato de API** — altera schema do banco (mesmo aditivo) ou muda resposta/rota/status/payload que `frontend`/`app` consomem.
- [ ] **Causa raiz obscura / correção ampla** — causa não clara após timebox, ou > ~3 arquivos / vários módulos.
- [ ] **Decisão de produto/arquitetura** — depende de comportamento novo, ou contradiz a Constitution.

Nenhuma marcada → segue no BFF.

**Justificativa da Triagem:** correção de 1 linha em `backend/config/cors.php` — acrescenta `env('FRONTEND_URL')` à lista de origens permitidas, um valor que o `deploy-backend.yml` já grava no `.env` (linha 61) e que o próprio arquivo claramente pretendia usar (já lê o irmão `FRONTEND_NETWORK_URL`). Não toca rota/controller/middleware de auth, não altera lógica de autenticação/autorização, não abre CORS para origem arbitrária — só inclui a origem do próprio frontend de produção do projeto. Sem migration, sem contrato de API. A origem (`expense.novemax.com.br`) já era decisão fechada, carregada pelo secret `ENV_FRONTEND_URL`. *(Nota: é config de CORS, um limite de segurança do browser — se preferir, o agent `security-reviewer` pode olhar, mas o trigger dele é `routes/api.php`/`Http/Controllers`/`Http/Middleware`, não `config/`.)*

## 1. Problema

- **Sintoma:** ao logar em `https://expense.novemax.com.br/app`, o browser bloqueia a chamada:
  ```
  Access to fetch at 'https://expense-api.novemax.com.br/api/login' from origin
  'https://expense.novemax.com.br' has been blocked by CORS policy: Response to
  preflight request doesn't pass access control check: No
  'Access-Control-Allow-Origin' header is present on the requested resource.
  ```
- **Reprodução:** abrir `https://expense.novemax.com.br/app`, preencher e-mail/senha, "Entrar" → o preflight `OPTIONS /api/login` volta sem `Access-Control-Allow-Origin`. Nenhuma rota `/api/*` é chamável a partir dessa origem.
- **Esperado vs. atual:**
  - Esperado: `https://expense.novemax.com.br` está entre as origens permitidas (é o frontend de produção; o `deploy-backend.yml` grava `FRONTEND_URL` no `.env` justamente pra isso).
  - Atual: não está — o preflight falha.
- **Causa raiz:** `backend/config/cors.php:10-13`:
  ```php
  'allowed_origins' => array_filter([
      'http://localhost:3000',
      env('FRONTEND_NETWORK_URL'),
  ]),
  ```
  A lista tem `localhost:3000` hardcoded e lê **só** `env('FRONTEND_NETWORK_URL')`. Nunca lê `env('FRONTEND_URL')`, apesar de o `.github/workflows/deploy-backend.yml:61` gravar `FRONTEND_URL=${{ secrets.ENV_FRONTEND_URL }}` no `.env` de produção. Resultado: em produção `allowed_origins` = `['http://localhost:3000', <FRONTEND_NETWORK_URL>]` — sem a origem do frontend de produção. Antes desta sessão o frontend só rodava local (`localhost:3000`) ou na rede local (`FRONTEND_NETWORK_URL`), então o buraco nunca aparecia; o deploy do frontend em `expense.novemax.com.br` (feature `20260829-deploy-topologia-unificada`) é o primeiro caso em que a origem de produção chama a API de produção.

## 2. Correção

- **O que muda e por quê:** `backend/config/cors.php` acrescenta `env('FRONTEND_URL')` ao `array_filter` de `allowed_origins`, entre `'http://localhost:3000'` e `env('FRONTEND_NETWORK_URL')`. Passa a permitir a origem que o `deploy-backend.yml` já injeta no `.env` de produção. `array_filter` continua descartando os `env()` nulos (ambiente sem a var).
- **Arquivos tocados:** `backend/config/cors.php`, `backend/tests/Feature/CorsConfigTest.php` (novo).
- **Teste de regressão:** `CorsConfigTest::test_frontend_url_env_is_an_allowed_cors_origin` — seta `FRONTEND_URL` no ambiente, faz `require base_path('config/cors.php')` e assere que a origem está em `allowed_origins`. Sem a correção, falha (o arquivo não lia essa var). Segundo teste garante que `FRONTEND_NETWORK_URL` continua valendo.
- **Riscos / efeitos colaterais:** baixo — só amplia `allowed_origins` para incluir a origem do próprio frontend de produção do projeto; não abre para origem arbitrária. `supports_credentials: true` já estava ligado. **Não basta o merge**: o secret `ENV_FRONTEND_URL` do repositório precisa valer `https://expense.novemax.com.br` e o `deploy-backend.yml` precisa rodar de novo (regenera o `.env` + `config:cache`) para a correção fazer efeito em produção — ação do dono, fora deste PR.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-08-29 | `cd backend && ./vendor/bin/pint --test config/cors.php tests/Feature/CorsConfigTest.php` | limpo |
| 2026-08-29 | `cd backend && php artisan test --filter=CorsConfigTest` | 2 passed (2 assertions) |
| 2026-08-29 | `cd backend && php artisan test` | 239 passed (722 assertions) |

## Resolução

Concluído em: 2026-08-29
Branch: `fix/20260829-cors-nao-le-frontend-url`
PR: #94 (mergeado em `dev`; promovido a `main` no PR #97)

`config/cors.php` passou a incluir `env('FRONTEND_URL')` em `allowed_origins`. Correção de código encerrada.

Pendente fora do código (gate humano, ação do dono): setar o secret `ENV_FRONTEND_URL = https://expense.novemax.com.br` e re-rodar `deploy-backend.yml` (regenera `.env` + `config:cache`) para a correção valer em produção. Esbarra no `ECONNREFUSED` de modo passivo do FTP — ver `concluidos/20260829-deploy-backend-ftp-texto-puro.md`.
