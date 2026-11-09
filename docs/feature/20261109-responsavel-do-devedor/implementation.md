# Implementation — Responsável do devedor

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261109

---

## 1. Desvios do fluxo padrão (se houver)

Decisões tomadas na execução, cada uma com o que custa se estiver errada:

- **Parar após cada task.** A skill `superpowers:executing-plans` pede execução contínua, mas a regra do usuário (memória do projeto e `tasks.md`) é parar após cada task e perguntar se segue; vale a dele. Custo se errado: uma pergunta a mais por task.
- **O registro é este arquivo**, e não o ledger `.superpowers/sdd/` da skill: o log do projeto mora aqui (`CLAUDE.md`, item 5). Custo se errado: nenhum.
- **D9 e D10 aprovadas como recomendado (A e A).** O usuário respondeu "siga" depois da minha recomendação; tratei como aprovação e registrei no specify, no plan e no tasks. Custo se errado: só a D10 mexe em código, e mudaria a TASK-583 (`ended_at` e índice único sobre vínculos ativos).
- **TASK-583: `MemberResponsibleRefused` estende `DomainException`, não `RuntimeException`** (plan §2). É o padrão de `PurchaseUndoRefused`; o controller captura pela classe, então o tipo pai não muda nada. Custo se errado: zero.
- **TASK-583: um teste a mais** que os 14 de `tasks.md`, `test_the_unique_index_rejects_a_duplicate_link`. O F1 (dois `PUT` simultâneos) depende do índice único, e o teste fixa o nome explícito que cabe nos 64 caracteres do MySQL. Custo se errado: um teste.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-583 | Concluída | 2026-11-09 | Claude (com o usuário) | **RED:** `./vendor/bin/phpunit --filter=MemberResponsibleServiceTest` falhou com `Class "App\Services\MemberResponsibleService" does not exist`. **GREEN:** o mesmo comando → `OK (15 tests, 40 assertions)`. **Mutation check:** sem a conferência de membro dos responsáveis → 2 falhas (`replace refuses a responsible who is not a member`, `replace changes nothing when any id is refused`); sem a recusa do próprio devedor → 1 falha (`replace refuses the debtor as their own responsible`); código restaurado e `grep "false &&"` vazio. **Suíte inteira:** `./vendor/bin/phpunit` → `OK (1138 tests, 6819 assertions)`. **Estilo:** `./vendor/bin/pint --test` → `PASS 225 files`. **Migration:** `migrate:rollback --step=1`, `migrate` e `migrate:fresh --env=testing --force` → `DONE`, exit 0. | A suíte inteira por `php artisan test --env=testing` dá **535 falhas** (`429 Too Many Requests` e `LogTransport::messages()` indefinido): o `.env.testing` da receita local tem `CACHE_DRIVER=file` e `MAIL_MAILER=log`, o `artisan test` repassa isso ao PHPUnit e o `phpunit.xml` (`array`) não o sobrescreve. Não é do código desta task: pelo `phpunit` direto a suíte é verde. **Rodar a suíte inteira sempre por `./vendor/bin/phpunit`.** Gate pendente (dele): rodar a migration em banco compartilhado ou produção. |
