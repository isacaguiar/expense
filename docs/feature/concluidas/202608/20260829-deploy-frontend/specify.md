# Specify — Deploy do frontend (GitHub Actions → FTP)

> Feature: cria o deploy automático do `expense/frontend` (Vite+React) para o host `expense.novemax.com.br` via GitHub Actions + FTP, no mesmo modelo do `deploy-backend.yml`, e corrige de passagem o `server-dir` do deploy do backend. Pedido novo, discutido diretamente com o usuário em 2026-08-29.

Versão: 1.0 · Criado em: 20260829

---

## 1. Problema

Hoje o `expense/frontend` não tem deploy nenhum. Existem só:

- `.github/workflows/deploy-backend.yml` — deploy do Laravel (push em `main` → build → FTP para `scd.novemax.com.br`).
- `.github/workflows/ci-frontend.yml` — só verificação em PR (`tsc` + `vitest` + `vite build`), não publica nada.
- `.github/workflows/deploy-site.yml` — arquivo **não versionado** no working tree local, rascunho de deploy do site institucional (exclui `frontend/`), fora do escopo desta feature.

Publicar o app web em produção é, portanto, manual e não documentado.

Ao levantar o alvo do FTP, apareceu uma inconsistência no deploy existente: a conta FTP já conecta dentro de `/expense/` (host `expense.novemax.com.br`), mas o `deploy-backend.yml` usa `server-dir: /expense/backend/` — o que resolve para `/expense/expense/backend/` (caminho duplicado). O correto é `server-dir: /backend/`.

## 2. Requisitos

### 2.1 Workflow de deploy do frontend

Novo `.github/workflows/deploy-frontend.yml`:

- Trigger: `push` para `main` (mesmo gatilho do backend) e `workflow_dispatch` (re-disparo manual).
- Node 20, `actions/setup-node@v4` com cache npm em `frontend/package-lock.json` (igual `ci-frontend.yml`).
- Passos, dentro de `frontend/`:
  1. `npm ci`.
  2. `npm run build` com `VITE_API_BASE_URL=https://expense-api.novemax.com.br` no ambiente (variável lida em build time por `frontend/src/config.ts`).
- Publicação: `SamKirkland/FTP-Deploy-Action@v4.3.5` (mesma versão do backend), `protocol: ftp`, `port: 21`, secrets `SFTP_HOST` / `SFTP_USER` / `SFTP_PASS` (os mesmos já usados pelo `deploy-backend.yml`), `local-dir: frontend/dist/`, `server-dir: /frontend/`.
- Sem passo de teste no deploy — a verificação já roda no `ci-frontend.yml` em PR, antes do merge em `main`.

### 2.2 Fallback de rota SPA

O frontend usa `BrowserRouter` (URLs limpas como `/meus-grupos`). Servido em Apache (shared hosting, como o backend), rotas de cliente precisam cair em `index.html`.

- Novo `frontend/public/.htaccess` com `RewriteEngine` redirecionando qualquer path que não seja arquivo/diretório existente para `/index.html`.
- O Vite copia `public/` para a raiz do `dist/` no build, então o `.htaccess` sobe junto no deploy sem passo extra.

### 2.3 Correção do `server-dir` do deploy do backend

`.github/workflows/deploy-backend.yml`: `server-dir: /expense/backend/` → `server-dir: /backend/`, para bater com a raiz real da conta FTP (`/expense/`).

## 3. Fora de escopo desta feature

- `deploy-site.yml` (site institucional) — arquivo local não versionado, deploy próprio, decisão à parte.
- Deploy do app Expo (`expense/app`).
- Criar/rotacionar secrets — os `SFTP_*` já existem e são reaproveitados; nenhum segredo novo.
- Migrar `VITE_API_BASE_URL` para secret — vai hardcoded no workflow (é URL pública, não segredo); virar secret depois é trivial se necessário.
- Limpar a pasta `/expense/expense/backend/` que o `server-dir` errado possa ter criado no host — ação manual no servidor, fora do que um workflow faz.
- Configurar HTTPS/domínio/docroot no host — assume-se que `expense.novemax.com.br` já serve o conteúdo de `/frontend/`.
