# Specify — Ajuste do Deploy Backend para Google OAuth

> Feature: ajusta o workflow de deploy do backend para gerar o `.env` de produção com as variáveis do vínculo de conta Google e com o domínio real do backend. Pedido novo do usuário, feito durante a configuração manual das credenciais Google OAuth (sessão de 2026-08-24), sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260824

---

## 1. Problema

A feature `docs/feature/concluidas/202608/20260822-atualizacao-minha-conta/` implementou o vínculo de conta Google (`GoogleAuthController`), que depende de `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (`backend/config/services.php:34-40`) e `FRONTEND_URL`/`FRONTEND_NETWORK_URL` (`backend/config/cors.php:9-12`) no `.env` de produção. O workflow `.github/workflows/deploy-backend.yml`, no passo "🔐 Gerar arquivo .env" (linhas 30-56), não escreve nenhuma dessas 5 variáveis — e usa `> .env` (sobrescreve do zero) a cada execução, não `>>`. Ou seja: qualquer configuração manual dessas variáveis feita direto no servidor via SSH é apagada no próximo deploy automático, quebrando o vínculo Google em produção sem aviso.

O mesmo bloco também tem `APP_URL=https://scd.novemax.com.br` (linha 37) e `MAIL_FROM_ADDRESS=no-reply@scd.novemax.com.br` (linha 52) fixos com o domínio antigo do backend — o domínio real definido nesta sessão é `expense-api.novemax.com.br` (já cadastrado como Authorized redirect URI no Google Cloud Console).

## 2. Achados confirmados

### 2.1 Variáveis do Google/frontend ausentes no workflow

`.github/workflows/deploy-backend.yml`, passo "🔐 Gerar arquivo .env" (linhas 30-56): não inclui `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `FRONTEND_URL`, `FRONTEND_NETWORK_URL`, apesar de `backend/config/services.php` e `backend/config/cors.php` já lerem essas chaves via `env()`.

### 2.2 Domínio hardcoded desatualizado

Mesmo passo, linha 37 (`APP_URL`) e linha 52 (`MAIL_FROM_ADDRESS`): usam `scd.novemax.com.br`, domínio antigo. O domínio de produção real do backend é `expense-api.novemax.com.br` (frontend fica em `expense-app.novemax.com.br`, subdomínio separado).

### 2.3 Secrets já cadastrados no GitHub

Os 5 secrets novos (`ENV_GOOGLE_CLIENT_ID`, `ENV_GOOGLE_CLIENT_SECRET`, `ENV_GOOGLE_REDIRECT_URI`, `ENV_FRONTEND_URL`, `ENV_FRONTEND_NETWORK_URL`) já existem no ambiente `PROD` do repositório GitHub (`isacaguiar/expense`), criados manualmente em 2026-08-24 — falta só o workflow referenciá-los, mesmo padrão dos secrets `ENV_*` já usados no passo.

## 3. Fora de escopo desta feature

- Criar o workflow de deploy do frontend (`expense-app.novemax.com.br`) — item de backlog separado (`docs/backlog/workflow-cicd-frontend.md`, ID 008).
- Adicionar `php artisan migrate`/`storage:link` ao workflow — gap já registrado no runbook de publicação HostGator, não pedido nesta feature.
- Rotacionar ou revisar qualquer secret já existente no ambiente PROD.
- Qualquer mudança em `GoogleAuthController` ou outro código de aplicação — esta feature mexe só no workflow de deploy.
