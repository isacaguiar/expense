# Plan — Invitation message colisão mail

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Remover a colisão de chave (specify §2.1)

- `backend/app/Http/Controllers/InvitationController.php:36`: trocar a chave `'message' => $request->message` por `'inviteMessage' => $request->message` no array passado a `Mail::send('email.invitation', [...], $callback)`.
- `backend/resources/views/email/invitation.blade.php:55-56`: trocar `@if($message)` por `@if($inviteMessage)` e `{{ $message }}` por `{{ $inviteMessage }}`.
- Não renomear a variável de validação (`$request->message`/`'message' => 'nullable|string|max:1000'` no `$request->validate()`) — o payload da API continua com o campo `message` (contrato externo inalterado); só a chave interna repassada para a view muda, para não colidir com a variável reservada `$message` que `Mail::send()` injeta.
- Nenhuma migration, nenhuma mudança de contrato de request/response.

## 2. Teste de ponta a ponta (specify §2.2)

- Em `backend/tests/Feature/InvitationControllerMailViewsTest.php`: substituir o teste `test_invite_view_name_exists` (e o comentário que documenta o bug, linhas 45-48) por um teste que efetivamente chama `POST /api/invitations` autenticado, com `message` preenchido, e espera `200` — cobrindo o caminho real que antes quebrava, não só a existência da view. Seguir o padrão de autenticação já usado em `InvitationControllerValidationTest.php` (`withToken(auth('api')->login($inviter))`).
- Manter os testes de `forgotPassword`/`verify` do mesmo arquivo inalterados (não fazem parte do bug).

## 3. Ordem de execução

Sem dependência técnica entre os dois itens — a correção da view (§1) é o que faz o teste de §2 passar, então §1 vem antes de §2 na prática, mas cabem na mesma task por serem uma unidade atômica (renomear a chave dos dois lados + provar que funciona com um teste é uma única entrega, não duas independentes). Uma task só, ver `tasks.md`.
