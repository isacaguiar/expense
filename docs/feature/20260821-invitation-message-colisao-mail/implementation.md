# Implementation — Invitation message colisão mail

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-129 | Implementada na branch da feature (feature completa nesta branch, task única) | 2026-08-21 | IA (Claude Code) | `cd backend && ./vendor/bin/pint --test app/Http/Controllers/InvitationController.php tests/Feature/InvitationControllerMailViewsTest.php resources/views/email/invitation.blade.php` — PASS; `php artisan test --filter=Invitation` — 10 passed (25 assertions), incluindo `test_invite_with_message_resolves_the_real_view` (antes falharia com 500, TypeError); `php artisan test` (suíte completa) — 99 passed (233 assertions) | Renomeia a chave `message`→`inviteMessage` em `InvitationController::invite` e na blade `email/invitation.blade.php`, eliminando a colisão com a variável `$message` injetada por `Mail::send()`. Substitui `test_invite_view_name_exists` (workaround que só checava a existência da view) por um teste de ponta a ponta real |
