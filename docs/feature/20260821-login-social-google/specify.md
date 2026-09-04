# Specify — Login social via Google

> Feature: permitir que o usuário entre no sistema autenticando com sua conta Google, além do login por e-mail/senha já existente. Origem: item 014 do backlog (`docs/backlog/login-social-google.md`), promovido após decisão de produto que resolve a `TASK-021` (`docs/sdd/03-tasks.md:46`).

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

A tela de login (feature `docs/feature/concluidas/202608/20260819-novo-layout-tela-login/`) já tem um botão "Google" no card de login (`frontend/src/pages/login/LoginFormCard.tsx:170-178`), mas ele é só um link visual (`href="#"`) sem nenhuma integração — o usuário clica e nada acontece. Isso é uma promessa de UI não cumprida.

A `TASK-021` registrava uma decisão de produto pendente: implementar o login social de fato, ou remover as referências. Decisão tomada em 2026-08-21 (conversa com o usuário): **implementar, apenas para Google** (Microsoft segue fora de escopo, backlog item 015 continua aberto).

## 2. Requisitos

### 2.1 Escopo do provedor

Apenas Google OAuth 2.0 nesta feature. O botão "Microsoft" (`frontend/src/pages/login/LoginFormCard.tsx:179-187`) permanece como está (fora de escopo — ver §3).

### 2.2 Credenciais

Já existe um client-id/secret do Google **vazado em texto puro** no repositório (`README.md` raiz e `client_secret_*.json`, registrado em `00-constitution.md` §5.3). Essas credenciais **nunca** devem ser reaproveitadas.

Decisão: o usuário (dono do projeto) cria um client-id/secret **novos** no Google Cloud Console e os fornece via `.env` local do backend (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`) quando a implementação chegar no ponto de precisar deles. Nenhum segredo real é escrito em código, migration, teste ou documentação — apenas os nomes das variáveis de ambiente.

### 2.3 Fluxo técnico

Backend redirect clássico via Laravel Socialite (`laravel/socialite`, ainda não instalado — não há `socialite` em `backend/composer.json`):

1. Frontend chama uma rota backend que redireciona para o consentimento do Google (`Socialite::driver('google')->redirect()`).
2. Google redireciona de volta para uma rota de callback no backend (`Socialite::driver('google')->user()`).
3. Backend identifica/cria o usuário e emite o mesmo JWT já usado no login por e-mail/senha (mesmo padrão de `AuthController::login`, `backend/app/Http/Controllers/AuthController.php:31-55`, via `Auth::guard('api')`).
4. Backend redireciona para o frontend com o token (nenhum outro fluxo — ex. token no frontend validado no backend via Google Identity Services — foi descartado nesta decisão).

### 2.4 Vínculo com conta existente

Se o e-mail retornado pelo Google já existe em `ex_users` (conta criada via e-mail/senha), a conta é **vinculada automaticamente** ao Google (sem exigir senha nem confirmação adicional) — decisão tomada assumindo que o Google só retorna e-mails verificados.

### 2.5 Modelo de dados

Hoje `ex_users.password` é `NOT NULL` (`backend/database/migrations/2014_10_12_000000_create_users_table.php:19`) e `User::$fillable` não tem nenhum campo de provedor social (`backend/app/Models/User.php:24-29`). Um usuário criado só via Google (sem nunca ter definido senha local) precisa de:
- `password` nullable (migration aditiva), e
- alguma forma de registrar que a conta tem Google vinculado (ex.: `google_id` nullable) — detalhamento de coluna(s) fica para `plan.md`.

### 2.6 Frontend

O botão "Google" (`frontend/src/pages/login/LoginFormCard.tsx:170-178`) troca `href="#"` por um redirecionamento real para a rota de início do fluxo OAuth do backend.

### 2.7 Nota de sequenciamento (adicionada em 2026-08-22)

A infraestrutura de OAuth Google (client `laravel/socialite`, colunas `google_id`/`avatar_url`, `password` nullable em `ex_users`, callback compartilhado) passou a ser construída primeiro por `docs/feature/concluidas/202608/20260822-atualizacao-minha-conta/` (§2.2 daquele `specify.md`), decisão tomada com o usuário para evitar duplicar client OAuth ou reabrir código já mergeado depois. Quando esta feature (`login-social-google`) ganhar seu `plan.md`, ele deve **reaproveitar** essa infraestrutura — apenas adicionar o redirect público de login (§2.6) e o ajuste em `UserController::changePassword` para o caso de senha `NULL` (usuário criado só via Google) — não recriar client, colunas ou callback já existentes.

## 3. Fora de escopo desta feature

- Login social via Microsoft (backlog item 015, `docs/backlog/login-social-microsoft.md` — segue "Aberto", nenhuma decisão de produto tomada para esse provedor ainda).
- Fluxo de login social no app Expo/React Native em migração (`app/`) — o redirect OAuth clássico do backend não serve diretamente para mobile; quando o app novo avançar, isso exige sua própria decisão técnica (ex.: deep link de retorno, ou trocar para o fluxo de token validado no backend).
- Rotação das credenciais Google órfãs já vazadas (`00-constitution.md` §5.3) — permanece como debt separado, gate humano à parte; esta feature cria credenciais **novas**, não mexe nas antigas.
- Qualquer mudança no fluxo de "Esqueci minha senha" para contas que só têm login social (sem senha local) — se necessário, vira task/feature própria depois que este fluxo básico existir.
