# Plan — Convite de Participante por E-mail

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Fluxo único de convite (specify §2.1)

- Remover `InvitationController::invite()` (`backend/app/Http/Controllers/InvitationController.php:14-43`) e a rota `POST /invitations` (`backend/routes/api.php:27`) — sem caller no frontend, seguro de descontinuar.
- Manter `InvitationController::forgotPassword()` e a rota `POST /forgot-password` intocados (fora de escopo, specify §3).
- `GroupMemberController::store` (`backend/app/Http/Controllers/GroupMemberController.php:27-82`) continua o único ponto de entrada de convite — sem extrair serviço novo: hoje só há esse chamador, então a lógica de criação de usuário + geração de token + disparo de e-mail permanece inline no controller (evita abstração sem segundo uso, conforme convenção do projeto).

## 2. E-mail com texto explicativo (specify §2.2)

- Corrigir `UserInvitedMail::build()` (`backend/app/Mail/UserInvitedMail.php:31-42`) para renderizar `email.invitation` (view existente, `backend/resources/views/email/invitation.blade.php`) em vez da view inexistente `emails.user_invited`.
- Estender o construtor de `UserInvitedMail` para receber também o usuário que convidou (`$inviter`), já disponível em `GroupMemberController::store` via `auth()->user()`.
- Variáveis passadas à view: `inviterName` (nome de quem convidou), `groupName` (nome do grupo — variável nova), `inviteMessage` (permanece `null`; `store()` não coleta mensagem customizada) e `activationLink` apontando para `/aceitar-convite?email={email}&token={token}` (nova página, item 4).
- Ajustar o texto de `email/invitation.blade.php` para citar o grupo (hoje é só "convidou você para participar da nossa plataforma"; passa a mencionar `{{ $groupName }}") — pequeno ajuste de copy, sem redesenhar o template.
- Assunto do e-mail passa a citar o grupo, reaproveitando o padrão já usado em `UserInvitedMail::build()` (`"Você foi convidado para o grupo “{$this->group->name}”"`).

## 3. Token de convite com validade de 2 dias (specify §2.3)

- Não reaproveitar `Password::getRepository()` (`GroupMemberController.php:60`) — sua expiração é a config global `passwords.users.expire` (`backend/config/auth.php:37`), hoje 60 min, e é infraestrutura genérica de "esqueci senha" (mesmo que hoje só `GroupMemberController::store` a use na prática).
- Gerar token dedicado seguindo o mesmo padrão já usado em `InvitationController::forgotPassword` (`backend/app/Http/Controllers/InvitationController.php:106-108`, `Cache::put` com TTL): `Cache::put('invitation-token:'.$user->email, $token, now()->addDays(2))`, com `$token = bin2hex(random_bytes(32))`. `CACHE_DRIVER=file` no `.env` — mesma garantia de persistência entre requests que o fluxo de reset de senha já usa em produção.
- Token de uso único: removido do cache (`Cache::forget`) assim que consumido com sucesso pelo endpoint de confirmação (item 4).

## 4. Endpoint de confirmação + página de criação de senha (specify §2.4)

- Backend: adaptar `InvitationController::verify()` (`backend/app/Http/Controllers/InvitationController.php:46-86`) — mesma rota pública `POST /invitations/verify` — trocando a checagem `Hash::check($request->token, $user->password)` (mecanismo antigo do `invite()` removido) pela checagem do novo cache `invitation-token:{email}` (item 3). Mantém intacta a checagem `isResetTokenValid` (`password-reset-token:{email}`), pois `verify()` continua sendo também o endpoint de confirmação do `forgotPassword` (fora de escopo, não alterar esse ramo).
- Frontend: nova página `AcceptInvitePage` (`frontend/src/pages/AcceptInvitePage.tsx`), rota pública `/aceitar-convite` em `frontend/src/App.tsx` (ao lado de `/`, fora do bloco `RequireAuth`, mesma posição da rota `/`). Lê `email` e `token` da query string, formulário de senha/confirmação reaproveitando o padrão visual de `frontend/src/pages/ChangePassword.tsx` (Card/TextField/Snackbar) e o padrão de chamada HTTP de `frontend/src/pages/GroupMembersForm.tsx` (axios + `API_BASE_URL`, sem header de auth pois a rota é pública). Envia `POST /api/invitations/verify` com `{ email, token, password, password_confirmation }`; sucesso navega para `/` (login) com mensagem de confirmação.

## N. Ordem de execução

Há dependência técnica parcial — a ordem recomendada para `tasks.md`:

1. Backend: token dedicado (item 3) + correção do e-mail (item 2) + `GroupMemberController::store` passa a gerar/usar esse token — sem isso não há link válido para testar o resto.
2. Backend: adaptar `InvitationController::verify()` (item 4, metade backend) — depende do item 1 (precisa do novo cache key existir para validar).
3. Backend: remover `InvitationController::invite()` e rota `POST /invitations` (item 1, limpeza) — independente dos itens 1-2, pode entrar em qualquer ponto, mas fica por último para não misturar remoção com a funcionalidade nova na mesma revisão.
4. Frontend: `AcceptInvitePage` + rota `/aceitar-convite` (item 4, metade frontend) — depende do endpoint do passo 2 estar pronto para integrar de verdade.
