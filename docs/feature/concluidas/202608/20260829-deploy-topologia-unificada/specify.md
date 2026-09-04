# Specify — Topologia de deploy unificada (site + /app + /api)

> Feature: reorganiza o deploy dos três frontes num modelo "uma cara pública". `expense.novemax.com.br` passa a servir o **site institucional na raiz** e o **app React em `/app`** (mesmo docroot); o backend fica em subdomínio próprio com a pasta renomeada de `backend` para `api`. Pedido novo, discutido diretamente com o usuário em 2026-08-29 (Caminho A do brainstorming). **Supersede** a feature `20260829-deploy-site` (que assumia subdomínio próprio para o site e nunca foi mergeada).

Versão: 1.0 · Criado em: 20260829

---

## 1. Problema

A sessão de 2026-08-29 entregou `deploy-frontend.yml` (app em `expense.novemax.com.br`, FTP `/frontend/`), corrigiu o `deploy-backend.yml` (`server-dir` → `/backend/`) e desenhou um `deploy-site.yml` para o site em subdomínio próprio. Ao revisar, o dono decidiu outra topologia:

- Uma "cara pública" única: `expense.novemax.com.br/` mostra o **site** (marketing + páginas legais); `expense.novemax.com.br/app` abre a **tela de login** e é onde o app roda.
- `/api` em vez de `/backend` como nome da pasta/rota do backend — `backend` é jargão de repo, `api` é o que se espera numa URL.

O modelo atual não faz isso: o app está na raiz de `expense.novemax.com.br`, o site não tem lar, e a pasta do backend é `backend`.

Restrições herdadas que moldam a solução:

- O `site/` é PHP puro sem build, com `site/public/` (docroot) e `site/src/` (templates, **fora** do docroot). Todo `site/public/*.php` faz `require __DIR__ . '/../src/...'` — `public/` e `src/` têm que ficar irmãos, `src/` um nível acima do docroot. O site usa links `.php` literais (`privacidade.php`, `termos.php`), então **não precisa de rewrite/`.htaccess` na raiz**.
- O frontend React usa `BrowserRouter` sem `basename` e o Vite sem `base`. Servir em `/app` exige os dois ajustes + fallback SPA escopado a `/app`.
- O backend Laravel quer um docroot dedicado apontando pro `public/` dele. Mantê-lo em subdomínio próprio evita ter que hospedá-lo num subpath (que nesse host exigiria wrapper de `index.php` + proteção de `.env`). Por isso o CORS existente entre `expense.novemax.com.br` e `expense-api.novemax.com.br` **continua** e não é alterado.

## 2. Requisitos

### 2.1 Estado alvo

| Subdomínio | Document root | Serve |
|---|---|---|
| `expense.novemax.com.br` | `/expense/www/` | `index.php`/`privacidade.php`/`termos.php`/`assets/` (site) na raiz **+** subpasta `app/` com o build do React |
| `expense-api.novemax.com.br` | `/expense/api/public/` | Laravel (inalterado) |

FTP (a conta cai em `/expense/`): `deploy-site` escreve em `/www/` e `/src/`; `deploy-frontend` em `/www/app/`; `deploy-backend` em `/api/`.

### 2.2 Backend: `/backend` → `/api`

- `.github/workflows/deploy-backend.yml`: `server-dir: /backend/` → `server-dir: /api/`. Única mudança no arquivo.
- **Sem mudança no código Laravel.** `APP_URL` continua `https://expense-api.novemax.com.br`; `URL::temporarySignedRoute('proofs.show', …)` e `GOOGLE_REDIRECT_URI` inalterados; `config/cors.php` inalterado.
- `frontend` `VITE_API_BASE_URL` continua `https://expense-api.novemax.com.br`.
- Ação de infra (fora do workflow): document root de `expense-api.novemax.com.br` → `/expense/api/public/`.

### 2.3 Site na raiz do docroot compartilhado

`.github/workflows/deploy-site.yml` publica em **dois destinos** (a conta FTP entra em `/expense/`):

- `site/public/` → `server-dir: /www/`
- `site/src/` → `server-dir: /src/`

Assim `/expense/www/index.php` acha `/expense/src/...` via `__DIR__ . '/../src/'`, e `site/src/` fica fora do docroot (`/expense/www/`) — não servível por URL.

Trigger `push` para `main` + `workflow_dispatch`. Sem `setup-node`/`npm` (PHP puro). `FTP-Deploy-Action@v4.3.5`, secrets `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS`.

### 2.4 Site aponta para o app

`site/src/config.php`: `app_login_url` passa de `'#'` para `'/app/'`. `app_signup_url`: `'/app/'` também, **ou** remover o botão "Cadastre-se" do `nav.php` (o frontend não tem página de cadastro — ver `docs/backlog/`). Decisão registrada no `plan.md`.

### 2.5 App em `/app`

- `frontend/vite.config.js`: `base: '/app/'` (assets passam a resolver como `/app/assets/...`).
- `frontend/src/main.tsx`: `<BrowserRouter basename="/app">`.
- `frontend/src/App.tsx`: remover o import morto `BrowserRouter as Router` (linha 2 importa mas não usa) — confirmar antes de remover.
- `frontend/public/.htaccess`: `RewriteBase /app/` e fallback para `/app/index.html`.
- `.github/workflows/deploy-frontend.yml`: `server-dir: /frontend/` → `/www/app/`. O build já usa o `base` do `vite.config.js`, sem flag extra.
- Ajustar testes de frontend que dependam de path absoluto de rota, se houver.

### 2.6 Pendências manuais de infra (documentadas, não automatizáveis)

Registrar em `implementation.md` §1 e avisar o dono:

1. Document root de `expense.novemax.com.br` → `/expense/www/`.
2. Document root de `expense-api.novemax.com.br` → `/expense/api/public/` (renomear/apontar a partir de `/expense/backend/`).
3. Se `/expense/frontend/` tiver conteúdo de deploy anterior, é lixo depois desta feature — remoção manual.

## 3. Fora de escopo desta feature

- Servir o backend na mesma origem (`expense.novemax.com.br/api`) — é o "Caminho B" do brainstorming, custo/risco alto nesse host; fica para depois se desejado.
- Criar página de cadastro no frontend (`app_signup_url`).
- DNS / criação de subdomínio / configuração de painel — ações manuais do dono (§2.6).
- Qualquer mudança no código de negócio do Laravel ou do React além dos ajustes de roteamento/base acima.
- `deploy-site` como feature separada — descontinuada, substituída por esta.
