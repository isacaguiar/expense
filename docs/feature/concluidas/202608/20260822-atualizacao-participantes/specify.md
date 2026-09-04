# Specify — Convite de Participante por E-mail

> Feature: unifica os dois fluxos de convite hoje duplicados/quebrados em um único fluxo de convite por e-mail (a partir de `POST /groups/{id}/members`), com token de 2 dias e página de criação de senha para o convidado. Pedido novo, absorvendo o achado técnico já registrado como `TASK-019` em `docs/sdd/03-tasks.md` (Épico D).

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

Hoje existem **dois** fluxos paralelos para convidar/ativar usuário, e nenhum dos dois funciona de ponta a ponta:

- **`GroupMemberController::store`** (`backend/app/Http/Controllers/GroupMemberController.php:27-82`) — é o fluxo realmente em uso: acionado pela tela "Adicionar novo membro" (`frontend/src/pages/GroupMembersForm.tsx:63-82`, rota `/groups/:id/members`, chamando `POST /api/groups/{id}/members`). Quando o e-mail informado não pertence a nenhum usuário existente, cria o `User` e dispara `UserInvitedMail` (`backend/app/Mail/UserInvitedMail.php:34-42`). Duas quebras conhecidas:
  - A view `emails.user_invited` referenciada em `UserInvitedMail::build()` **não existe** em `backend/resources/views` (só existe `email/invitation.blade.php`, `email/password-reset.blade.php`, `email/activation-confirmation.blade.php`, sob namespace `email` singular) — o envio de e-mail quebra em runtime.
  - O token do convite vem de `Password::getRepository()->create($user)` (`GroupMemberController.php:60`), cuja expiração é a config global `passwords.users.expire` (`backend/config/auth.php:37`) = 60 minutos, compartilhada com o fluxo de "esqueci minha senha" — não há como dar 2 dias só para convite sem mexer no reset de senha genérico.
  - O link do e-mail aponta para `/password/reset/{token}?email=...`, rota que **não existe** em `frontend/src/App.tsx`.

- **`InvitationController::invite`/`verify`/`forgotPassword`** (`backend/app/Http/Controllers/InvitationController.php`, rotas `POST /invitations`, `POST /invitations/verify`, `POST /forgot-password` em `backend/routes/api.php:15-16,27`) — fluxo paralelo e **totalmente órfão do frontend**: nenhuma tela chama nenhuma dessas três rotas hoje (confirmado por busca em `frontend/src`). O único ponto de UI relacionado é o link "Esqueci minha senha" em `frontend/src/pages/login/LoginFormCard.tsx:142-144`, que é `href="#"` — não dispara nada. O token de `invite()` (`InvitationController.php:23-24`) também não expira de verdade: é a própria senha temporária hasheada, validada via `Hash::check` em `verify()` (`InvitationController.php:61`).

Essa duplicação e as duas quebras acima já estavam registradas como achado técnico em `TASK-019` (`docs/sdd/03-tasks.md:44`, Épico D: *"Unificar os dois fluxos de convite (`InvitationController::invite` e `GroupMemberController::store`)"*). Esta feature parte desse achado e adiciona escopo de produto: copy explicativa no e-mail, expiração de 2 dias, e uma página de fato para o convidado criar senha (que não existe em nenhum dos dois fluxos atuais).

## 2. Requisitos

### 2.1 Fluxo único de convite

Consolidar em um único ponto de entrada: `POST /groups/{id}/members` (`GroupMemberController::store`) passa a ser o único fluxo de convite de participante novo. `InvitationController::invite` (rota `POST /invitations`) é removido — seguro de descontinuar, pois não tem nenhum caller no frontend hoje.

### 2.2 E-mail de convite com texto explicativo

O e-mail enviado ao novo participante deve conter texto explicando o convite (quem convidou, para qual grupo, e uma chamada à ação clara). Reaproveitar/adaptar `email/invitation.blade.php` (`backend/resources/views/email/invitation.blade.php`) como base — já existe, já renderiza `inviterName`, mensagem opcional e botão de ativação — em vez de depender da view inexistente `emails.user_invited`.

### 2.3 Token de convite com validade de 2 dias

O link enviado ao convidado deve carregar um token válido por 2 dias. Como a config atual (`passwords.users.expire`, `backend/config/auth.php:37`) é global e compartilhada com "esqueci minha senha", não pode ser reaproveitada como está — precisa de um mecanismo de token próprio para convite, com TTL de 2 dias, independente do reset de senha genérico. (Decisão de *como* implementar — tabela dedicada, cache com TTL, etc. — fica para `plan.md`.)

### 2.4 Página de criação de senha e validação do token

Deve existir uma página no frontend (hoje inexistente) para o convidado, a partir do link do e-mail: informar o token recebido (via URL) e definir uma senha. O backend deve validar o token (existência + não expirado) antes de aceitar a nova senha, reaproveitando a lógica já presente em `InvitationController::verify` (validação de token, `Hash::make` da senha nova, marca `email_verified_at`, envia e-mail de confirmação via `email.activation-confirmation`) como base do endpoint único de confirmação — adaptando a origem do token para o mecanismo definido em 2.3.

## 3. Fora de escopo desta feature

- `InvitationController::forgotPassword` (recuperação de senha de usuário **já existente**, rota `POST /forgot-password`) — caso de uso de login, não de onboarding de participante. Está órfão no frontend hoje (link "Esqueci minha senha" em `LoginFormCard.tsx:142-144` não dispara nada), mas religar/consertar esse link é outra frente, não esta feature.
- `GroupMemberController::destroy` (rota `DELETE /groups/{groupId}/members/{userId}`, hoje sem implementação) — é o achado já registrado como `TASK-017` (Épico C), sobre remoção de membro, tema diferente de convite/adição.
- Login social (Google/Microsoft) exibido em `LoginFormCard.tsx:169-188` — botões também sem ação (`href="#"`), não relacionados a convite.
