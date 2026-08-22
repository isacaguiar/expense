# Implementation — Convite de Participante por E-mail

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

Feature segue `04-implementation.md` sem exceção. Branch da feature: `backend/20260822-atualizacao-participantes` (nasceu de `dev` atualizada; TASK-189 implementada direto nela, por ser a primeira task).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-189 | Concluída | 2026-08-22 | isacaguiar (IA) | `GroupMemberController::store` passa a gerar token via `Cache::put('invitation-token:'.$email, $token, now()->addDays(2))` (`bin2hex(random_bytes(32))`), substituindo `Password::getRepository()->create($user)`. Teste novo `tests/Feature/GroupMemberInvitationTokenTest.php` (viagem no tempo com `travelTo` confirma o token válido pouco antes de 2 dias e expirado pouco depois, e confirma `ex_password_reset_tokens` sem linha para o novo e-mail). `./vendor/bin/pint --test app/Http/Controllers/GroupMemberController.php tests/Feature/GroupMemberInvitationTokenTest.php` — PASS (2 files). `php artisan test --filter="GroupMemberInvitationTokenTest\|GroupMemberControllerTest"` — 4 passed (15 assertions). `php artisan test` (suíte completa) — 182 passed (566 assertions). | E-mail de convite (`UserInvitedMail`) continua com a view quebrada — corrigido só na TASK-190, propositalmente fora do escopo desta task; teste novo usa `Mail::fake()` para isolar. |
