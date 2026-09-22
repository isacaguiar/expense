# Specify — Link de e-mail (convite de grupo e recuperação de senha) aponta pro domínio errado

> Feature: corrigir os links de `UserInvitedMail` (convite de grupo) e `InvitationController::forgotPassword` (recuperação de senha), que hoje usam `url()` (domínio da API, `APP_URL`) em vez de `FRONTEND_URL`, chegando mortos. Achado durante a investigação de `docs/bugfix/20260921-cadastro-codigo-email-nao-chega.md`; escalado do BFF para feature porque toca controller/Mailable de auth (Triagem, caixa 1).

Versão: 1.0 · Criado em: 20260921

---

## 1. Problema

Os e-mails de convite de grupo e de recuperação de senha usam o helper `url()` do Laravel para montar o link que a pessoa clica. `url()` resolve a partir de `APP_URL`, que em produção é `https://expense-api.novemax.com.br` — o domínio da **API**, sem frontend nenhum servido ali (`.github/workflows/deploy-backend.yml:34`: `echo "APP_URL=https://expense-api.novemax.com.br" >> .env`). Quem clica cai numa rota que não existe na API e nunca chega à tela que deveria abrir.

O projeto já resolve esse tipo de link corretamente em outro lugar: `config/services.php:40` expõe `frontend_url` a partir de `FRONTEND_URL` (com default `http://localhost:3000`), e `GoogleAuthController.php:49` já usa `config('services.frontend_url')` para montar o redirect pós-login OAuth. Em produção `FRONTEND_URL` já está configurada corretamente (`deploy-backend.yml`, secret `ENV_FRONTEND_URL`, atualizado em 2026-09-01) — não é preciso mexer em infraestrutura, só trocar qual config cada link usa.

Os dois pontos afetados têm o mesmo defeito raiz, mas gravidade diferente:

### 1.1 Convite de grupo — domínio errado, rota existe

`UserInvitedMail.php:33`:
```php
$activationLink = url("/aceitar-convite?email={$this->user->email}&token={$this->token}");
```
Disparado por `GroupMemberController::store` (`GroupMemberController.php:66-68`) quando um e-mail sem conta é adicionado a um grupo. A rota `/aceitar-convite` **existe** no frontend (`frontend/src/App.tsx:43` → `AcceptInvitePage`), que posta para `POST /api/invitations/verify` — só o domínio do link está errado.

### 1.2 Recuperação de senha — domínio errado **e** rota inexistente

`InvitationController.php:84`:
```php
$resetLink = url("/recuperar-senha?email={$user->email}&token={$token}");
```
Disparado por `forgotPassword` (`InvitationController.php:62-98`). Além do domínio errado, **`/recuperar-senha` não está registrada em nenhum lugar do frontend** — `frontend/src/App.tsx` não tem essa rota. Mesmo corrigindo só o domínio, o link cairia na rota coringa (`App.tsx:76`, `"404: Página não encontrada"`).

`InvitationController::verify` (o mesmo método que `AcceptInvitePage` chama) já aceita indistintamente um `invitation-token` ou um `password-reset-token` — são dois cache keys diferentes checados na mesma validação (`InvitationController.php:29-35`). `AcceptInvitePage` não tem nada específico de "convite": só lê `email`/`token` da query string e posta em `/api/invitations/verify` (`frontend/src/pages/AcceptInvitePage.tsx:19-20,35`). Ou seja, a página já funciona para os dois fluxos — falta só a rota `/recuperar-senha` apontar pra ela (ou o backend gerar o link com o path `/aceitar-convite`, que já existe).

### 1.3 Achado à parte: `01-specify.md` §3.2 item 2 está desatualizado

`docs/sdd/01-specify.md:43` descreve o fluxo de convite de grupo dizendo que ele "gera token via `Password::getRepository()`" — mas o código atual (`GroupMemberController.php:60-63`) usa um cache key dedicado (`invitation-token:`, TTL de 2 dias), com um comentário explícito no próprio arquivo dizendo que **não** usa `Password::getRepository()`. A baseline ficou desatualizada em relação ao código real. Não é corrigida nesta feature (é o documento `01-specify.md`, fora do escopo de uma feature específica) — registrada como achado em `docs/backlog/` para alguém revisar a seção inteira.

## 2. Requisitos

### 2.1 O link do convite de grupo aponta para o frontend

`UserInvitedMail` monta `activationLink` a partir de `config('services.frontend_url')` (mesmo padrão do `GoogleAuthController`), não de `url()`/`APP_URL`.

### 2.2 O link de recuperação de senha aponta para o frontend **e resolve numa página real**

`InvitationController::forgotPassword` monta `resetLink` a partir de `config('services.frontend_url')`. Precisa também existir, do lado do frontend, uma rota que trate esse link e funcione de ponta a ponta — reaproveitando `AcceptInvitePage`/`POST /api/invitations/verify` (que já são genéricos o bastante para os dois tokens), não duplicando lógica. A decisão de *como* (nova rota `/recuperar-senha` apontando pro mesmo componente, vs. o backend passar a gerar o link com `/aceitar-convite`) é do `plan.md`.

### 2.3 Cobertura de teste que prove o domínio certo

Hoje não há teste que verifique o host do link gerado (os testes existentes de `InvitationController`/`GroupMemberController` cobrem side-effects como `View::exists`, não o conteúdo do link — achado do backlog item 028/`docs/feature/concluidas/202608/20260821-recuperacao-senha-login/implementation.md`). Precisa de ao menos um teste por Mailable/fluxo que afirme que o link gerado começa com `FRONTEND_URL`, não `APP_URL`.

## 3. Fora de escopo desta feature

- **Unificar os dois fluxos de convite** (`POST /invitations` vs. `POST /groups/{id}/members`) — divergência já registrada em `01-specify.md:46` como conhecida e deliberadamente fora de escopo aqui; mexer nos dois de uma vez seria uma feature bem maior.
- **Deliverability/SMTP** (e-mail não sair do servidor) — já é o escopo do bug irmão `docs/bugfix/20260921-cadastro-codigo-email-nao-chega.md` (PR #190), tratado à parte.
- **Corrigir `01-specify.md:43`** — registrado como achado (§1.3), não corrigido nesta feature.
- **Endurecer enumeração de e-mail** em `forgotPassword`/convite — já é o backlog item 045 (`pre-cadastro-oraculo-e-mailable-queueable.md`), decisão consciente de tratar toda a superfície de auth junto, não isolado aqui.
