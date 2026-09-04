# Convite por e-mail quebra por colisão entre a chave 'message' e a variável $message do Mail::send legado

ID: 028
Origem: docs/feature/concluidas/202608/20260821-recuperacao-senha-login/plan.md §7 (achado ao testar TASK-125)
Criado em: 2026-08-21
Prioridade: MEDIA
Status: Promovido para TASK-129

## Descrição

`InvitationController::invite` (`backend/app/Http/Controllers/InvitationController.php`) chama a API legada `Mail::send('email.invitation', ['inviterName' => ..., 'message' => $request->message, 'activationLink' => ...], $callback)`. Essa API do Laravel injeta automaticamente, antes de renderizar a view, a variável `$message` com a instância de `Illuminate\Mail\Message` (o builder usado no `$callback` para definir `to`/`subject`) — sobrescrevendo qualquer chave `'message'` já presente no array de dados passado pelo desenvolvedor. A view `resources/views/email/invitation.blade.php:56` usa `@if($message) ... {{ $message }} ...`, então acaba tentando renderizar o objeto `Mail\Message` (não a string do convite) via `htmlspecialchars()`, que lança `TypeError`.

Confirmado ao rodar `POST /api/invitations` com um `message` de convite preenchido: `500`, log mostra `TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, Illuminate\Mail\Message given`.

## Por que importa

Qualquer convite que inclua uma mensagem personalizada (`message` no payload) quebra o envio com 500 — o texto do convite nunca aparece no e-mail, e a requisição falha por completo (o usuário convidado nunca é criado nem recebe e-mail, já que a exceção interrompe o método antes do `return`). Convite sem `message` (campo é `nullable`) não dispara isso, então o bug só aparece quando a funcionalidade de mensagem personalizada é usada — provavelmente por isso nunca foi notado antes.

Correção sugerida (para quando isso virar task): renomear a chave de dados (`'message'` → ex. `'inviteMessage'`) tanto no controller quanto na blade view (`resources/views/email/invitation.blade.php`), evitando a colisão com a variável reservada `$message` do `Mail::send()` legado.

Tipo sugerido: backend

## Resolução

Concluído em: 2026-08-21
Feature: docs/feature/concluidas/202608/20260821-invitation-message-colisao-mail/
Tasks: TASK-129
PRs: https://github.com/isacaguiar/expense/pull/40 (mergeado em `dev`)
