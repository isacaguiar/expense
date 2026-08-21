# Implementation — Melhoria da Tela de Grupos

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260820

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-102 | Concluída | 20260820 | IA | `php artisan migrate` — 1 migration, DONE 371ms. `php artisan migrate:rollback --step=1` — DONE 223ms, reaplicado em seguida (`php artisan migrate` — DONE 503ms). `./vendor/bin/pint --test app/Models/Group.php database/migrations/2026_08_20_010000_add_created_by_to_ex_groups_table.php` — 2 issues encontrados, corrigidos com `./vendor/bin/pint` (sem `--test`), depois `--test` limpo (2 files, PASS). `php artisan tinker` — criou grupo com `created_by`, confirmou `$g->creator->email` retorna o e-mail correto; grupo de teste removido em seguida. | Migration aditiva local (`created_by` nullable + FK `nullOnDelete` em `ex_groups`, mesmo padrão de `invited_by` em `ex_users`) — sem gate humano necessário (Constitution §5.2, migration aditiva local é autônoma). |
| TASK-103 | Concluída | 20260820 | IA | `./vendor/bin/pint --test app/Http/Controllers/GroupController.php tests/Feature/GroupControllerTest.php` — PASS, 2 files. `php artisan test --filter=GroupControllerTest` — 13 passed (32 assertions), incluindo os 3 testes novos (`test_store_sets_created_by_to_authenticated_user`, `test_store_blocks_fourth_group_created_by_same_user`, `test_store_ignores_deleted_groups_when_counting_limit`). `php artisan test` (suíte completa) — 63 passed (150 assertions). | Limite (`MAX_GROUPS_CREATED_PER_USER = 3`) conta só grupos com `deleted = 0`; retorna 422 com mensagem clara, sem criar registro. |
| TASK-104 | Concluída | 20260820 | IA | `./vendor/bin/pint --test app/Http/Controllers/GroupController.php tests/Feature/GroupControllerTest.php` — PASS, 2 files. `php artisan test --filter=GroupControllerTest` — 15 passed (36 assertions), incluindo `test_show_includes_creator_email` e `test_index_includes_creator_email`. | `with('creator:id,email')` em `index` e `show`; sem endpoint novo. |
| TASK-105 | Concluída | 20260820 | IA | `./vendor/bin/pint --test tests/Feature/GroupMemberControllerTest.php` — PASS, 1 file. `php artisan test --filter=GroupMemberControllerTest` — 1 passed (5 assertions). `php artisan test` (suíte completa) — 66 passed (159 assertions). | Arquivo de teste novo (`GroupMemberControllerTest.php`, não existia antes). Prova que usuário no limite de criação (3 grupos) continua sendo adicionado com sucesso como membro de um 4º grupo criado por outra pessoa — nenhuma mudança de código de produção, só teste de não-regressão. |
| TASK-106 | Concluída | 20260820 | IA | `npx vitest run src/pages/GroupForm.test.tsx` — 3 passed (1 file), incluindo o teste novo `shows the backend error message when creation is rejected`. `npx tsc --noEmit` — limpo. | Achado de segurança tangencial encontrado durante TASK-105 (`GroupMemberController@store` sem checagem de membership) foi sinalizado como task separada fora desta feature, não corrigido aqui. |
| TASK-107 | Concluída | 20260820 | IA | `npx vitest run src/pages/Dashboard.test.tsx` — 9 passed (1 file), incluindo os 4 testes novos (Responsável exibido, fallback "—", botão desabilitado no limite, botão habilitado abaixo do limite). `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 56 passed (13 files). | Testes existentes precisaram trocar de `mockResolvedValueOnce` único para `mockImplementation` por URL, já que `Dashboard.tsx` agora faz 2 chamadas `axios.get` (`/api/me` e `/api/groups`) no mesmo efeito. Botão "Novo grupo" é `component={Link}` (renderiza `<a>`, role "link", não "button") — testes usam `aria-disabled` em vez de `toBeDisabled()`. |
| TASK-108 | Concluída | 20260821 | IA | `npx vitest run src/pages/GroupMembersForm.test.tsx` — 2 passed (1 file), incluindo a asserção nova de "Responsável: dono@example.com" no teste existente. `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 56 passed (13 files). | Sem teste novo dedicado — a asserção de Responsável foi adicionada ao teste de renderização já existente, já que é o mesmo carregamento de dado (`GET /api/groups/:id`). |
