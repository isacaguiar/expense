# Specify — Cadastro de usuários

> Feature: permitir que uma pessoa crie a própria conta na plataforma, com o e-mail comprovadamente dela antes de a conta existir. Pedido novo do usuário (2026-09-19), não vem de épico de `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260919

---

## 1. Problema

Hoje **não existe tela de cadastro**. As únicas formas de entrar na plataforma são convite (`InvitationController`, `GroupMemberController` — `01-specify.md` §3.2) e Google OAuth. Quem chega sozinho no site institucional ou na tela de login não consegue criar conta.

O botão "Cadastre-se" existe em dois lugares e **nenhum leva a lugar nenhum**:

- `site/src/templates/nav.php:23`, `site/public/index.php:60` e `site/public/index.php:127` apontam para `$config['app_signup_url']`, fixado em `/app/` por `site/src/config.php:22` — com um comentário explícito no arquivo dizendo que registro ainda não existe.
- `frontend/src/pages/login/LoginFormCard.tsx:191` é um `<Link href="#">`. O comportamento de link morto está inclusive fixado por teste em `frontend/src/pages/LoginPage.test.tsx:128`.

Existe `POST /api/register` (`backend/app/Http/Controllers/AuthController.php:13`, registrado em `routes/api.php:17`, descrito em `01-specify.md` §3.1), público, mas ele **não serve** para este fluxo: cria o usuário na hora sem confirmar o e-mail, não aceita `password_confirmation`, não aceita telefone e não devolve token. Nenhum cliente o chama.

## 2. Requisitos

### 2.1 Página de cadastro alcançável pelos dois pontos de entrada

Uma página nova em `/cadastro` (que resolve para `/app/cadastro` por causa do `basename="/app"` em `frontend/src/main.tsx:13`) contendo **informações sobre o cadastro** e o **formulário**. Tanto o CTA do site institucional quanto o link "Ainda não tem uma conta? Cadastre-se" do card de login levam a ela.

### 2.2 Formulário com confirmação de e-mail e de senha

Campos: **nome**, **e-mail**, **confirmar e-mail**, **telefone** (opcional), **senha**, **confirmar senha**. E-mail e senha só são aceitos quando os dois campos de cada par conferem.

O telefone reusa a coluna `ex_users.whatsapp`, que já existe (migration `2026_08_22_193236_add_whatsapp_to_users_table.php`) e já alimenta o notificador WhatsApp, no mesmo formato já validado em `backend/app/Http/Controllers/UserController.php:47` (`(XX) 9XXXX-XXXX`). Não se cria um segundo campo de telefone.

### 2.3 Os dados ficam numa tabela temporária até o e-mail ser confirmado

Os dados preenchidos **não viram um `User`** na submissão do formulário. Vão para uma tabela temporária `ex_user_pre_create` (prefixo `ex_` — `00-constitution.md` §1.4) e só de lá viram um `User` real depois que a pessoa provar que o e-mail é dela.

### 2.4 Confirmação do e-mail por código

Ao submeter o formulário, um e-mail é enviado com um **código de 6 dígitos**, válido por 15 minutos. A pessoa digita o código na própria página de cadastro; código correto cria o `User` com `email_verified_at` preenchido. Há reenvio de código, com intervalo mínimo entre envios, e um limite de tentativas erradas.

### 2.5 Entrar direto após confirmar

Confirmado o código, a conta já nasce autenticada: o endpoint de confirmação devolve o mesmo payload de `POST /api/login` e o frontend segue para `/meus-grupos` sem pedir a senha de novo.

## 3. Fora de escopo desta feature

- **Depreciar ou remover `POST /register`.** Ele continua registrado e funcionando exatamente como hoje (`00-constitution.md` §4.1 proíbe alterar contrato de rota existente sem depreciação assistida). Decidir o futuro dele é item de backlog.
- **Exigir e-mail verificado no login.** `AuthController::login` não checa `email_verified_at` hoje, e contas antigas têm o campo `null` — gatear isso quebraria usuários existentes. Item de backlog.
- **Recuperação de senha ("Esqueci minha senha") e login social**, ambos ainda com `href="#"` no mesmo card de login. Já são itens próprios (`docs/backlog/login-social-google.md`).
- **Rotina de expurgo** das linhas expiradas de `ex_user_pre_create` — expurgo é hard delete, gate humano (`00-constitution.md` §5.2). Item de backlog.
