# Implementation — Quem paga se declara e o credor recusa o comprovante

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261110

---

## 1. Desvios do fluxo padrão (se houver)

Decisões tomadas na execução, cada uma com o que custa se estiver errada:

- **Parar após cada task.** A skill `superpowers:executing-plans` pede execução contínua, mas a regra dele (memória do projeto, `tasks.md` e a skill `promover-backlog`) é parar após cada task e perguntar se segue; vale a dele. Custo se errado: uma pergunta a mais por task.
- **O registro é este arquivo**, e não o ledger `.superpowers/sdd/` da skill: o log do projeto mora aqui (`CLAUDE.md`, item 5). Custo se errado: nenhum.
- **Commit por task direto na branch da feature, sem sub-branches** (`...-TASK-0xx` com merge `--no-ff` do ADR-003). As tasks são sequenciais, o PR é um só e a feature anterior fez igual. Custo se errado: o histórico não tem um merge por task; a divisão continua visível nos commits, que citam a task.
- **A D7-A (exclusão física do próprio comprovante) foi aprovada com aval de hard delete** em 2026-11-10 (specify §2.8 e §3.4). O aval vale só para o próprio comprovante, enquanto não aceito e com o ciclo não encerrado, e **deve ser citado no corpo do PR** (TASK-600). Custo se errado: a TASK-600 cairia para "só reenviar" (D7-B) e a rota de excluir deixaria de existir.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-594 | Concluída | 2026-11-10 | Claude (com o usuário) | **RED (executado):** `./vendor/bin/phpunit tests/Feature/MemberResponsibleCreatedByTest.php` antes do código. A 1ª versão deu `Tests: 4, Failures: 2`: os 2 testes de coluna **passavam sem a coluna**, porque um atributo inexistente lê como `null` e `created_by` fora do `$fillable` é descartado em silêncio. Reforcei os dois para olhar o banco direto (`Schema::hasColumn` e `DB::table(...)->value('created_by')`) → `Tests: 4, Assertions: 4, Errors: 1, Failures: 3` (coluna ausente e autoria não gravada). **GREEN:** migration + `$fillable` → restam as 2 falhas do `replace`; `replace()` grava `created_by` = o devedor → `OK (4 tests, 10 assertions)`. `--filter='MemberResponsibleServiceTest\|GroupMemberResponsibleControllerTest\|GroupMemberResponsibleMembersTest\|ResponsibleAuthorizationMatrixTest\|SettlementConfirmationOnBehalfTest'` → `OK (72 tests, 293 assertions)` **sem edição**. **Migration:** `migrate:rollback --step=1` removeu a coluna e `migrate` a recriou; a chave `ex_group_member_responsibles_created_by_foreign` tem 47 caracteres e regra `SET NULL` (conferido em `information_schema`). **Mutation check (3 mutantes, todos mortos):** sem `created_by` no `create` (testes 1 e 2), autor errado `$responsibleId` (1 e 2) e fora do `$fillable` (1, 2 e 4); arquivos restaurados e conferidos com `cmp`. A 1ª rodada do script não restaurava entre os mutantes e só o M1 valeu; corrigi e refiz os três. **Suíte:** `./vendor/bin/phpunit` (banco zerado e a migration) → `OK (1199 tests, 7082 assertions)`, 4:10 (1195 + 4 novos); `./vendor/bin/pint --test` → `PASS 234 files`. | Um teste a mais que os 3 de `tasks.md`: `test_created_by_is_nulled_when_that_user_is_deleted`, que prova o `nullOnDelete`. **Gate humano:** a migration roda em produção no deploy ao mergear em `main` (ADR-008, INFO-1). |
