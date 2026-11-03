# Implementation — Convite Pendente com Aceite ou Recusa para Entrar no Grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261103

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio do `04-implementation.md`.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-566 | Concluída | 2026-11-03 | Claude (IA) | **TDD.** `php artisan test --filter GroupInvitationModelTest` primeiro **vermelho** (11 falhas, todas `Class "App\Models\GroupInvitation" not found`, depois de `migrate:fresh` só com as 35 migrations existentes) e depois **verde** (`php artisan migrate:fresh --env=testing --force` com a migration nova: `2026_11_03_000000_create_ex_group_invitations_table ... DONE`, saída 0; 11 passed, 19 assertions). **Esquema real conferido no MySQL** (`SHOW COLUMNS`, `SHOW INDEX` e `information_schema` por script no `tinker`): `expires_at` `datetime` NOT NULL, `status` `varchar(16)` com default `pending`, `unique(group_id, user_id)`, `index(user_id, status)`, FKs `group_id` e `user_id` em CASCADE e `invited_by` em SET NULL. **Mutation check** (10 mutantes aplicados um a um e revertidos por script, com os arquivos conferidos idênticos no fim e o banco remigrado): `>` virando `>=` no `open()`, sem filtro de status, sem filtro de validade, `invitee()` com a chave errada, `Group::invitations()` com a chave errada, unicidade só em `user_id`, sem unicidade, `invited_by` em cascata, FK do grupo sem cascata e `status` sem default: **10 de 10 pegos**. `./vendor/bin/pint --test` → PASS (209 arquivos). `git diff --stat -- backend/routes backend/app/Http backend/app/Support` vazio. `php artisan test --filter "GroupControllerTest\|GroupMemberControllerTest\|RoutesPointToExistingControllerMethodsTest\|NotifierTriggersTest\|GroupInvitationModelTest"` → 94 passed (386 assertions). `php artisan test` (suíte inteira) → **992 passed (6031 assertions)**, 204 s; antes eram 981 (6012): +11 testes e +19 asserções, todos do arquivo novo, nenhum teste existente editado. | Primeira task da feature: feita direto na branch da feature, sem sub-branch (`04-implementation.md` §1). Ambiente: contêiner `mysql-expense-test` (MySQL 8.0.17, `127.0.0.1:3307/ex-db`, alvo conferido com `tinker` antes de rodar) e `backend/.env.testing`, ignorado pelo git (`backend/.gitignore:13`), ambos recriados. **A migration só rodou no banco de teste local**: o gate humano da task (antes de rodar em banco compartilhado ou produção) segue de pé, e o deploy aplica migrations sozinho ao mergear em `main` (ADR-008). Nenhum comportamento muda ainda: o model, a tabela e o helper `BuildsGroupInvitations` não são usados por nenhuma rota. `expires_at` é `dateTime` (e não `timestamp`) e `status` é `string` (e não `enum`) pelos motivos do plan §1. A branch só existe localmente: nada foi publicado. |
