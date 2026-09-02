# 💰 Controle de Despesas Compartilhadas

Aplicação web para grupos (família, república, amigos) registrarem despesas em
comum, dividirem os valores e **acertarem as contas com o menor número de
transferências possível** — o sistema calcula a liquidação mínima do ciclo, em
vez de cada pessoa pagar cada pessoa. Cobrança e pagamento por **Pix** dentro do
próprio app.

- **App:** <https://expense.novemax.com.br/app>
- **API:** <https://expense-api.novemax.com.br>
- **Manual do usuário:** [`MANUAL.md`](MANUAL.md) · [`MANUAL.pdf`](MANUAL.pdf)
- **Processo de desenvolvimento (SDD):** [`docs/sdd/README.md`](docs/sdd/README.md)

---

## ✨ Principais funcionalidades

- Grupos com membros convidados por e-mail (convite gera conta + define senha).
- Despesas **À Vista**, **Parceladas** (N meses) e **Fixas** (recorrentes a cada ciclo).
- Divisão **igualitária** por despesa, entre um subconjunto escolhido de participantes.
- **Ciclo de faturamento** por mês calendário ou por "dia de fechamento" do grupo.
- Saldo líquido por pessoa + **liquidação mínima** (quem paga a quem).
- Marcar despesa como paga com **comprovante**; confirmar pagamento de acerto (Pix).
- Geração de **QR Code / copia-e-cola Pix** para a chave do credor.
- Fechar / reabrir o mês; **histórico de ciclos fechados** (snapshots imutáveis).
- Login por e-mail/senha (JWT) ou **Google**; notificações opcionais por **WhatsApp**.

---

## 🧱 Tecnologias

### Backend — `backend/`
| Área | Stack |
|---|---|
| Linguagem / framework | **PHP 8.2** (mín. 8.1) · **Laravel 10** |
| Autenticação | `tymon/jwt-auth` (Bearer JWT) · `laravel/sanctum` · `laravel/socialite` (Google) |
| Pix | `endroid/qr-code` (QR + payload copia-e-cola) |
| Banco | **MySQL 8** · `doctrine/dbal` (migrations com alteração de coluna) |
| Documentação de API | `darkaonline/l5-swagger` + `zircote/swagger-php` (OpenAPI) |
| E-mail | Mailer do Laravel (convite, reset de senha, comprovantes) |
| Qualidade | `laravel/pint` (lint) · `phpunit` 10 · `mockery` |

### Frontend — `frontend/`
| Área | Stack |
|---|---|
| Base | **React 18** + **TypeScript 5** · **Vite 7** |
| UI | **MUI 7** (`@mui/material`, `@mui/icons-material`) + Emotion |
| Rotas / HTTP | `react-router-dom` 7 (servido sob `/app`) · `axios` |
| Testes | **Vitest 4** + Testing Library + jsdom |

### Site institucional — `site/`
PHP puro, sem build. `site/public/` é o docroot (raiz + subpasta `app/` com o
build do React); `site/src/` fica fora do docroot.

### Infra / DevOps
- **GitHub Actions**: `ci-frontend.yml` (roda os testes do front) e deploys via
  FTP — `deploy-backend.yml`, `deploy-frontend.yml`, `deploy-site.yml`
  (disparo em `push` para `main` + `workflow_dispatch`).
- **Docker Compose** para dependências locais: MySQL 8, Adminer (`:8081`) e
  Mailpit (`:8025`).
- Node 20 no CI/deploy.

---

## 📂 Estrutura do repositório

```
backend/    API Laravel (controllers, models, migrations, mails, jobs)
frontend/   SPA React + Vite (pages, components, layouts, hooks)
site/       Site institucional em PHP puro (raiz pública + /app)
docs/       SDD (docs/sdd), features, bugfix, backlog e ADRs
assets/     Bootstrap do banco e imagens
.claude/    Skills e agents específicos do projeto
docker-compose.yml   MySQL + Adminer + Mailpit para desenvolvimento
iniciar-projeto.bat  Atalho de subida do ambiente no Windows
```

---

## 🚀 Rodando localmente

### Pré-requisitos
PHP 8.2 + Composer · Node 20 + npm · Docker (ou um MySQL 8 próprio).

### 1. Dependências de infraestrutura
```bash
docker compose up -d   # MySQL 8 (:3306), Adminer (:8081), Mailpit (:8025)
```

### 2. Backend (`backend/`)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# ajuste DB_*, MAIL_*, PIX_KEY, GOOGLE_* e FRONTEND_URL no .env
php artisan migrate
php artisan serve            # http://localhost:8000
```
Variáveis relevantes do `.env` (ver `backend/.env.example` para a lista completa):
`DB_*`, `JWT_SECRET`, `PIX_KEY`, `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI`,
`MAIL_*`, `FRONTEND_URL`, `WHATSAPP_ENABLED` (+ `WHATSAPP_TOKEN`,
`WHATSAPP_PHONE_NUMBER_ID`, templates).

### 3. Frontend (`frontend/`)
```bash
cd frontend
npm install
echo "VITE_API_BASE_URL=http://localhost:8000" > .env
npm run dev                  # http://localhost:3000/app/
```

### Documentação de API
Com o backend no ar: `php artisan l5-swagger:generate` e acesse
`http://localhost:8000/api/documentation`.

---

## ✅ Testes e lint

```bash
# Backend
cd backend && php artisan test        # PHPUnit
vendor/bin/pint                       # formatação (--test para só checar)

# Frontend
cd frontend && npm test              # Vitest (npm run test:watch para modo watch)
```

---

## 📦 Deploy

`push` na branch `main` dispara os workflows: build do Laravel e publicação em
`expense-api.novemax.com.br` (docroot `.../api/public/`), build do Vite publicado
em `expense.novemax.com.br/app`, e o site na raiz do mesmo domínio. Segredos de
ambiente ficam em GitHub Environments (`PROD`). Detalhes em
[`docs/feature/20260829-deploy-topologia-unificada/`](docs/feature/20260829-deploy-topologia-unificada).

---

## 🧩 Modelo de domínio (resumo)

> Documento autoritativo e sempre atualizado: [`docs/sdd/01-specify.md`](docs/sdd/01-specify.md).

- **User** (`ex_users`) — nome, e-mail, senha (hash, opcional se só Google),
  `pix`, `whatsapp`, `google_id`, `invited_by`.
- **Group** (`ex_groups`) — nome, descrição, `closing_day` (dia de fechamento
  opcional), `created_by`, soft delete. N:N com `User` via `ex_groups_members`.
- **Expense** (`ex_expenses`) — descrição, `total_value`, `expense_type`
  (`IN_CASH` | `IN_INSTALLMENTS` | `FIXED`), `installments`, `date_payment`,
  `group_id`, `user_creator_id`, `user_payer_id` (credor). Participantes da
  divisão em `ex_expenses_payers` (N:N direto na despesa).
- **Quota** (`ex_quotas`) — parcela da despesa: `number`, `value_quota`,
  `date_expected`, `paid`, `paid_at`/`paid_by`, `payment_proof_path`.
- **GroupCycleSnapshot** (`ex_group_cycle_snapshots`) — retrato imutável de um
  ciclo fechado (totais, participantes, `settlements`, marcações de
  fechamento/reabertura manual).
- **SettlementConfirmation** (`ex_settlement_confirmations`) — comprovante do
  acerto par-a-par enviado pelo devedor.
- **Participation** (`ex_participations`) — modelo existente, hoje não populado
  (o "quem deve a quem" é calculado em memória a cada requisição).

```mermaid
erDiagram
    USER ||--o{ GROUP_MEMBER : participa
    GROUP ||--o{ GROUP_MEMBER : possui
    GROUP ||--o{ EXPENSE : contem
    USER ||--o{ EXPENSE : "cria / paga"
    EXPENSE ||--o{ EXPENSE_PAYER : "divide entre"
    USER ||--o{ EXPENSE_PAYER : participa
    EXPENSE ||--|{ QUOTA : gera
    GROUP ||--o{ CYCLE_SNAPSHOT : "fecha ciclo"
    GROUP ||--o{ SETTLEMENT_CONFIRMATION : registra

    EXPENSE {
        string description
        decimal total_value
        enum expense_type "IN_CASH|IN_INSTALLMENTS|FIXED"
        int installments
        date date_payment
        fk group_id
        fk user_creator_id
        fk user_payer_id
    }
    QUOTA {
        int number
        decimal value_quota
        date date_expected
        bool paid
        datetime paid_at
        string payment_proof_path
    }
    CYCLE_SNAPSHOT {
        date cycle_start
        date cycle_end
        json settlements
        datetime closed_manually_at
        datetime reopened_at
    }
```

---

## 🛠️ Como o projeto é desenvolvido

Spec-Driven Development com human-in-the-loop: specs, planos e código em branch
são livres; ações irreversíveis ou que tocam produção/segredos exigem aprovação
humana. Correções de defeito seguem o fluxo BFF (`docs/bugfix/`). Comece por
[`docs/sdd/README.md`](docs/sdd/README.md) e [`CLAUDE.md`](CLAUDE.md).
