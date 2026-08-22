# Tasks — Meus Grupos: renomear rota e exclusão de grupo com preservação de histórico

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-181` — maior ID já usado no projeto antes desta feature: `TASK-180` (`docs/feature/20260821-melhoria-despesas/tasks.md`).

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-181 | `GroupMemberController@store`: rejeitar com 404 a adição de membro a grupo com `deleted=true` | backend | plan.md §3 | nenhum | Implementada na branch da feature |
| TASK-182 | `ExpenseController@store`, `@close`, `@reopen`: rejeitar com 404 quando o grupo tem `deleted=true` | backend | plan.md §3 | nenhum | Implementada na branch da feature |
| TASK-183 | `Group.php`: relação `cycleSnapshots()`; `GroupController@destroy`: ramificar exclusão física (sem `ex_group_cycle_snapshots` para o grupo, apaga em cascata dentro de transação) vs. lógica (mantém `deleted=true`) | backend | plan.md §2 | nenhum | Implementada na branch da feature |
| TASK-184 | `GroupController@index`: expor `cycle_snapshots_exists` via `withExists('cycleSnapshots')` | backend | plan.md §2 | nenhum | Pendente |
| TASK-185 | `Dashboard.tsx`: tipo `Group` ganha `cycle_snapshots_exists`; texto do modal de confirmação varia entre aviso de exclusão irreversível e aviso de preservação de histórico | frontend | plan.md §2 | nenhum | Pendente |
| TASK-186 | `App.tsx`: rota principal passa a ser `/meus-grupos`; `/dashboard` vira redirect (`<Navigate replace>`) para `/meus-grupos`, sem duplicar página | frontend | plan.md §1 | nenhum | Pendente |
| TASK-187 | Atualizar referências internas para `/meus-grupos` (`accountSettingsNavItems.ts`, `LoginPage.tsx`, `GroupForm.tsx`, `GroupMembersForm.tsx`) | frontend | plan.md §1 | nenhum | Pendente |
| TASK-188 | Atualizar testes que fixam `/dashboard` como rota real (`SimpleShellLayout.test.tsx`, `RequireAuth.test.tsx`, `GroupShellLayout.test.tsx`) e adicionar teste do redirect `/dashboard` → `/meus-grupos` | frontend | plan.md §1 | nenhum | Pendente |

## Critérios de aceite

- **TASK-181**: `POST /api/groups/{id}/members` num grupo com `deleted=true` retorna `404`, sem criar linha nova em `ex_groups_members` nem usuário novo em `ex_users` (mesmo com e-mail de usuário inexistente no payload). Grupo com `deleted=false` continua funcionando como hoje (regressão coberta). Teste automatizado novo/estendido em `backend/tests/Feature/GroupMemberControllerTest.php`.
- **TASK-182**: `POST /api/expenses` com `group_id` de um grupo `deleted=true` retorna `404`, sem criar despesa. `POST /api/groups/{id}/expenses/close` e `POST /api/groups/{id}/expenses/reopen` num grupo `deleted=true` também retornam `404`, sem criar/alterar linha em `ex_group_cycle_snapshots`. Grupo com `deleted=false` continua funcionando como hoje. Testes automatizados estendidos em `backend/tests/Feature/ExpenseControllerStoreTest.php`, `ExpenseControllerCloseTest.php` e `ExpenseControllerReopenTest.php`.
- **TASK-183**: `DELETE /api/groups/{id}` de um grupo **sem** nenhuma linha em `ex_group_cycle_snapshots` remove fisicamente o grupo e todas as despesas, quotas, participações e vínculos de membro associados (`assertDatabaseMissing` para cada tabela). `DELETE /api/groups/{id}` de um grupo **com** ao menos uma linha em `ex_group_cycle_snapshots` mantém `deleted=true` e todas as linhas relacionadas intactas (`assertDatabaseHas`), igual ao comportamento atual. Ambos os casos exigem que o usuário autenticado seja membro do grupo (404 caso contrário, comportamento já existente preservado). Teste automatizado novo em `backend/tests/Feature/GroupControllerTest.php` (ou arquivo dedicado `GroupDeletionTest.php`) cobrindo os dois ramos.
- **TASK-184**: `GET /api/groups` retorna, para cada grupo, o campo `cycle_snapshots_exists` (`true` para grupo com ao menos um snapshot de fechamento, `false` caso contrário), confirmado por teste automatizado em `backend/tests/Feature/GroupControllerTest.php`.
- **TASK-185**: com `cycle_snapshots_exists=false`, o modal de exclusão exibe texto de ação irreversível; com `cycle_snapshots_exists=true`, exibe texto de preservação de histórico. Verificado navegando a tela (ou teste de componente em `frontend/src/pages/Dashboard.test.tsx`) nos dois cenários.
- **TASK-186**: navegar para `/meus-grupos` renderiza a listagem de grupos com o título "Meus Grupos"; navegar para `/dashboard` redireciona para `/meus-grupos` (URL muda no browser, mesma tela é exibida, sem duplicar componente).
- **TASK-187**: login bem-sucedido navega para `/meus-grupos` (não mais `/dashboard`); o item de menu "Meus Grupos" aponta para `/meus-grupos`; cancelar/salvar em `GroupForm.tsx` e voltar em `GroupMembersForm.tsx` navegam para `/meus-grupos`.
- **TASK-188**: suíte de testes frontend (`npm test` ou equivalente) passa após a troca de `/dashboard` por `/meus-grupos` nos arquivos listados; novo teste cobrindo o redirect (`/dashboard` → `/meus-grupos`) está verde.
