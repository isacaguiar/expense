# Specify — CI (verificação) para o backend

> Feature: cria um workflow de CI para o backend (Pint + PHPUnit em Pull Request), equivalente ao que já existe para o frontend (`.github/workflows/ci-frontend.yml`). Origem: item de backlog 034, achado ao promover o item de backlog 008 (CI do frontend).

Versão: 1.0 · Criado em: 20260922

---

## 1. Problema

`.github/workflows/deploy-backend.yml` é só um workflow de **deploy**: dispara em `push` para `main` e publica via SSH/rsync, sem rodar `./vendor/bin/pint --test` nem `php artisan test` em nenhum momento. O frontend já tem essa checagem (`ci-frontend.yml`, dispara em Pull Request contra `dev`/`main`, roda `tsc --noEmit` + `vitest` + `build`); o backend não tem equivalente. Hoje um PR de backend pode ser mergeado com Pint sujo ou PHPUnit quebrado sem nenhuma checagem automática do GitHub.

## 2. Achados confirmados e requisitos

### 2.1 Banco de dados de teste no runner (achado técnico, validado nesta promoção)

O item de backlog original deixava em aberto "SQLite em memória vs. MySQL via serviço", porque `phpunit.xml:24-25` tem `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` comentados (não em uso) e os testes atuais usam `DatabaseTransactions` contra MySQL real. Testei as duas pontas antes de decidir:

- Subi um container `mysql:8.0.17` (mesma imagem do `docker-compose.yml` do projeto) **vazio, sem volume prévio**, rodei `php artisan migrate:fresh --force` (as 30 migrations de `backend/database/migrations/` aplicaram sem erro) e depois `php artisan test` com um `.env` mínimo (`APP_KEY`, `DB_*`, `JWT_SECRET`, `APP_URL`/`FRONTEND_URL` com valores distintos — ver §2.3) — **373 passed (1231 assertions)**, sem seed, sem dump externo.
- Não testei SQLite in-memory porque a linha comentada em `phpunit.xml` já é sinal de que foi abandonado propositalmente (provavelmente por incompatibilidade de sintaxe SQL entre MySQL e SQLite em alguma migration/query) — re-habilitar teria custo de investigação sem necessidade, já que a alternativa MySQL via serviço já comprovadamente funciona com a suíte atual.

**Requisito**: o job de CI usa um serviço `mysql:8.0.17` (`services:` do GitHub Actions), roda `php artisan migrate --force` antes de `php artisan test`, sem depender de nenhum dump/seed externo.

### 2.2 Dois comandos de verificação, mesmo padrão do `ci-frontend.yml`

- `./vendor/bin/pint --test` (não corrige, só falha se houver estilo fora do padrão).
- `php artisan test` (PHPUnit).

**Requisito**: o job falha (e bloqueia o PR) se qualquer um dos dois comandos falhar.

### 2.3 `.env` do runner é gerado inline no workflow, sem depender de `.env.example`

`backend/.env.example` está no `.gitignore` (item de backlog 056, não corrigido aqui — ver §3) — não existe no repositório para o runner copiar. `deploy-backend.yml` já resolve isso pro deploy gerando o `.env` inline via `echo` a partir de secrets do GitHub. Segui o mesmo padrão para o CI, mas com valores fixos de teste (não secrets — nada aqui é credencial real):

- Validado nesta promoção: `FRONTEND_URL` e `APP_URL` **precisam ter valores que não sejam prefixo um do outro** (ex.: `http://localhost` como `APP_URL` e `http://localhost:3000` como `FRONTEND_URL` quebra 2 testes — `GroupMemberInvitationMailTest` e `InvitationControllerForgotPasswordTest` —, porque ambos afirmam `str_starts_with($link, frontend_url) && ! str_starts_with($link, app.url)`, e `"http://localhost:3000/..."` começa com a substring `"http://localhost"`). Usar `APP_URL=http://127.0.0.1:8000` e `FRONTEND_URL=http://localhost:5173` (portas diferentes, sem relação de prefixo) evita a colisão.
- `MAIL_MAILER`, `CACHE_DRIVER`, `SESSION_DRIVER`, `QUEUE_CONNECTION` **não precisam** ser setados no `.env` do CI — `phpunit.xml:20-28` já força `array`/`sync` para o ambiente de teste independentemente do `.env`.
- Nenhuma integração externa (Google OAuth, Pix, WhatsApp, e-mail SMTP real) precisa de credencial real: os 373 testes passaram com esses valores ausentes do `.env` — o que é consistente com a suíte usar `Mail::fake()`/`Mail::shouldReceive()`/mocks em todo teste que tocaria essas integrações.

**Requisito**: o workflow gera um `.env` de teste inline (não copia `.env.example`, não usa secret de produção) com `APP_KEY` (gerado via `php artisan key:generate` no próprio job), `DB_*` apontando pro serviço MySQL do job, `JWT_SECRET` (qualquer string fixa, não é credencial real), `APP_URL` e `FRONTEND_URL` com valores distintos conforme achado acima.

## 3. Fora de escopo desta feature

- Corrigir o item de backlog 056 (`backend/.env.example` gitignorado) — o CI não depende dele (gera `.env` inline, mesmo padrão do `deploy-backend.yml`).
- Reabilitar SQLite in-memory em `phpunit.xml` — decisão já tomada em §2.1 (usar MySQL via serviço, igual à suíte já validada).
- Qualquer mudança em `deploy-backend.yml` (o workflow de deploy continua como está; CI é um workflow novo e separado, disparado por Pull Request, não por `push` em `main`).
- Cobertura de código (`--coverage`) ou relatório de cobertura no PR — não pedido pelo item de backlog, e `ci-frontend.yml` também não tem.
