# Specify — Invitation message colisão mail

> Feature: corrigir a colisão entre a chave `'message'` (mensagem personalizada do convite) e a variável `$message` que `Mail::send()` legado injeta automaticamente na view. Promovida do item 028 do backlog (`docs/backlog/invitation-invite-message-key-colisao-mail.md`), achado ao testar TASK-125 (`docs/feature/20260821-recuperacao-senha-login/plan.md §7`) e já documentado como bug conhecido em `backend/tests/Feature/InvitationControllerMailViewsTest.php:45-52`.

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

`InvitationController::invite` (`backend/app/Http/Controllers/InvitationController.php:34-40`) chama `Mail::send('email.invitation', ['inviterName' => ..., 'message' => $request->message, 'activationLink' => ...], $callback)`. A API legada `Mail::send()` do Laravel injeta automaticamente, antes de renderizar a view, uma variável `$message` com a instância de `Illuminate\Mail\Message` (o builder usado dentro do `$callback` para definir `to`/`subject`) — sobrescrevendo qualquer chave `'message'` já presente no array de dados passado pelo desenvolvedor.

A view `backend/resources/views/email/invitation.blade.php:55-57` usa `@if($message) ... {{ $message }} ...`, então tenta renderizar o objeto `Mail\Message` (não a string do convite) via `htmlspecialchars()`, lançando `TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, Illuminate\Mail\Message given`. Como não há `try/catch` ao redor do `Mail::send()` em `invite()` (diferente de `forgotPassword`, que já tem um desde a TASK-125), a exceção propaga e a requisição retorna `500` — mas o `User::create()` (`InvitationController.php:26-32`) já rodou antes do `Mail::send()`, então o usuário convidado fica criado no banco mesmo com o convite falhando por completo.

Qualquer convite que inclua uma mensagem personalizada (`message` no payload, campo `nullable`) quebra dessa forma; convite sem `message` não aciona o bug — por isso nunca foi notado antes da TASK-125.

## 2. Requisitos

### 2.1 Remover a colisão de chave

Renomear a chave de dados passada para a view (`'message'` → `'inviteMessage'`) tanto em `InvitationController::invite` (`backend/app/Http/Controllers/InvitationController.php:36`) quanto na blade `backend/resources/views/email/invitation.blade.php:55-56` (`@if($message)` → `@if($inviteMessage)`, `{{ $message }}` → `{{ $inviteMessage }}`) — mesma correção já sugerida no item de backlog.

### 2.2 Teste de ponta a ponta cobrindo o caso que hoje quebra

`backend/tests/Feature/InvitationControllerMailViewsTest.php:45-52` documenta o bug e explicitamente evita testar `invite()` com `message` preenchido "fora do escopo desta feature" (referência à feature anterior). Depois da correção, adicionar um teste que envia `POST /api/invitations` com `message` preenchido e espera `200` (não mais `500`) — fechando a lacuna de cobertura que o comentário deixou registrada.

## 3. Fora de escopo desta feature

- Reordenar `User::create()` para depois do envio do e-mail, ou adicionar `try/catch` em `invite()` para não deixar o usuário convidado "órfão" no banco se o e-mail falhar por outro motivo (ex.: SMTP fora do ar) — mesmo formato de problema já resolvido para o fluxo de recuperação de senha (item 011 do backlog, TASK-122 a TASK-125), mas aqui é um achado tangencial, não o bug relatado no item 028. Fica registrado aqui; se quiser tratar, vira um novo item de backlog depois desta feature.
- Qualquer mudança na API legada `Mail::send()` para `Mail::to()->send(new Mailable)` (mailable class) — troca maior de padrão, não necessária só para resolver a colisão de nome.
