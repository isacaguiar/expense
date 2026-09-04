# Specify — Atualização da Página Minha Conta

> Feature: evolui a página Minha Conta (`/profile`) com um campo de WhatsApp e a possibilidade de vincular uma conta Google (usada como avatar). Pedido novo do usuário, sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

A página Minha Conta (`/profile`, `frontend/src/pages/Profile.tsx`) hoje só edita `name`, `email` e `pix` (`UserController::updateProfile`, `backend/app/Http/Controllers/UserController.php`, já concluído na feature `docs/feature/concluidas/202608/20260821-melhoria-menu-tela-grupos-perfil/`). Não existe campo de telefone/WhatsApp nem preferência de notificação em nenhum lugar do sistema.

O avatar do usuário é sempre renderizado com iniciais via `getInitials()` (`frontend/src/layouts/group/getInitials.ts`) — não há campo de foto de perfil no model `User` (mesma lacuna já registrada em `docs/backlog/avatar-foto-usuario.md`, ID 021).

Não existe hoje nenhuma integração real com o Google: o único vestígio é um botão cosmético em `frontend/src/pages/login/LoginFormCard.tsx:170-178` (`href="#"`, sem nenhuma chamada de backend). Não há `laravel/socialite` instalado (`backend/composer.json`), nem coluna `google_id`/`avatar_url` em `ex_users`, nem `password` nullable (hoje é `NOT NULL`).

## 2. Requisitos

### 2.1 Campo WhatsApp e opt-in de notificação

O formulário de `/profile` ganha um campo `whatsapp` (telefone, formato `(DD) 9XXXX-XXXX`) e um checkbox "Receber notificações pelo WhatsApp". Requer coluna nova em `ex_users` (nullable) e ajuste em `UserController::updateProfile`/validação. Nome exato da(s) coluna(s) (um campo de texto + um boolean, ou um único campo combinado) e formato de persistência do opt-in ficam para `plan.md`.

### 2.2 Vínculo de conta Google

Usuário autenticado pode, a partir de Minha Conta, vincular sua conta Google à conta já existente no sistema. Este é um fluxo distinto de "login com Google" (login com Google é outra feature, `docs/feature/20260821-login-social-google/`, ainda sem `plan.md`/`tasks.md`/código).

**Decisão de reaproveitamento (tomada com o usuário em 2026-08-22):** para não duplicar client OAuth nem criar duas colunas concorrentes indicando "conta Google vinculada", esta feature assume a construção de toda a infraestrutura Google OAuth compartilhada:
- Client OAuth Google via `laravel/socialite` (não instalado hoje).
- Coluna `google_id` (nullable, única) e `password` nullable em `ex_users` (`password` é `NOT NULL` hoje).
- Um único callback compartilhado, capaz de atender tanto "vincular conta já autenticada" (esta feature) quanto o futuro "logar via Google" (`login-social-google`) — decisão validada tecnicamente considerando que o grupo de middleware `api` (`backend/app/Http/Kernel.php:42-46`) não tem `StartSession`, e que o JWT do frontend vive em `localStorage` com header manual (`frontend/src/pages/Profile.tsx:29-33`), não em cookie — um redirect de página inteira para o Google nunca carrega esse header, então o "vínculo" precisa iniciar via chamada autenticada (XHR) antes do redirect.

A futura `plan.md` de `docs/feature/20260821-login-social-google/` deve reaproveitar essa infraestrutura (client, colunas, callback) em vez de recriá-la — ver nota adicionada em `docs/feature/20260821-login-social-google/specify.md`.

Detalhe de rotas, nome de migrations e estrutura de controller fica para o `plan.md` desta feature.

### 2.3 Avatar via foto do Google

Uma vez vinculada a conta Google (2.2), a foto de perfil retornada pelo Google é armazenada (`avatar_url`, nova coluna) e passa a substituir as iniciais no avatar da própria página Minha Conta.

## 3. Fora de escopo desta feature

- O fluxo de "login com Google" em si (botão da tela de login, `LoginFormCard.tsx`) — pertence a `docs/feature/20260821-login-social-google/`, que vai reaproveitar (não recriar) a infraestrutura desta feature.
- Aplicar o avatar do Google em componentes que mostram *outros* usuários (`GroupMembersForm.tsx`, `Dashboard.tsx`, `GroupHeader.tsx`, etc.) — só o avatar do próprio usuário logado, em Minha Conta, muda.
- Corrigir `UserController::changePassword` para o caso de usuário criado só via Google (senha `NULL`) — esse caso só pode ocorrer pelo fluxo de *login* com criação de conta nova (`login-social-google`), não pelo fluxo de *vínculo* desta feature (usuário já tem senha antes de vincular). Ajuste fica para o `plan.md` de `login-social-google`.
- Regra de força de senha, 2FA, ou qualquer política de segurança de conta além do já existente.
- Desvincular/remover a conta Google depois de vinculada — não pedido pelo usuário; se necessário, vira item de backlog ou requisito futuro.
