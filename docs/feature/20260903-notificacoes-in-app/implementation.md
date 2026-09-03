# Implementation — Notificações in-app (e foto de perfil)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260903

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Segue `docs/sdd/04-implementation.md` §1 sem exceção (branch da feature `feature/20260903-notificacoes-in-app` a partir de `dev`, 1ª task direto nela, demais em sub-branch com `git merge --no-ff`, um único PR contra `dev` no fim).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-260 | Concluída | 2026-09-03 | IA | `php artisan migrate --force` → `2026_09_03_000000_create_ex_notifications_table` DONE (363ms). `./vendor/bin/pint --test` nos 3 arquivos novos → PASS. `php artisan test --filter=NotificationModelTest` → 4 passed (8 assertions). `php artisan test` (suíte completa) → 289 passed (856 assertions). | Feita direto na branch `feature/20260903-notificacoes-in-app` (1ª task, sem sub-branch). `./vendor/bin/pint --test` na base inteira acusa 8 issues **pré-existentes** em `dev` (`PixPayload.php`, `Expense.php`, `User.php`, migrations de 2025) — não tocados nesta task ("não corrigir de passagem", `06-context-backend.md`). |
