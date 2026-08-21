# Tasks — Melhoria da Tela de Grupos

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-102` — maior ID já usado no projeto antes desta feature: `TASK-101` (`docs/feature/20260820-atualizacao-layout-paginas/tasks.md`).

Versão: 1.0 · Criado em: 20260820

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-102 | Migration aditiva `created_by` em `ex_groups` (FK nullable para `ex_users`, `nullOnDelete`) + `Group.php` (fillable + relação `creator()`) | backend | plan.md §1 | nenhum | Concluída |
| TASK-103 | `GroupController@store`: validar limite de 3 grupos criados (não deletados) por usuário antes de criar, e setar `created_by` | backend | plan.md §1 | nenhum | Concluída |
| TASK-104 | `GroupController@index` e `@show`: eager-load `creator:id,email` na resposta | backend | plan.md §1, §3 | nenhum | Concluída |
| TASK-105 | Teste backend: usuário já no limite de 3 grupos criados continua podendo ser adicionado como membro de um grupo de outra pessoa | backend | plan.md §2 | nenhum | Concluída |
| TASK-106 | `GroupForm.tsx`: exibir a mensagem de erro retornada pelo backend (ex.: limite atingido) em vez do texto genérico fixo | frontend | plan.md §1 | nenhum | Concluída |
| TASK-107 | `Dashboard.tsx`: desabilitar "Novo grupo" ao atingir o limite (com base em `created_by` + `GET /api/me`) e exibir "Responsável" em cada card | frontend | plan.md §1, §3 | nenhum | Concluída |
| TASK-108 | `GroupMembersForm.tsx`: exibir o responsável (criador) do grupo | frontend | plan.md §3 | nenhum | Concluída |
| TASK-109 | `Sidebar.tsx`: suportar item de menu expansível (`children`) com `Collapse` do MUI, mantendo o comportamento atual para itens sem `children` | frontend | plan.md §4 | nenhum | Concluída |
| TASK-110 | `simpleNavItems.ts` + `SimpleShellLayout.tsx`: mover "Grupos" para dentro de "Configurações" como submenu, e ajustar a derivação do título do header para itens aninhados | frontend | plan.md §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-102**: `php artisan migrate` local aplica a coluna sem erro; `php artisan migrate:rollback` reverte limpo; `Group::create([...])->creator` resolve a relação corretamente em `tinker`/teste.
- **TASK-103**: teste automatizado cria 3 grupos com o mesmo usuário autenticado (sucesso) e confirma que a 4ª chamada a `POST /api/groups` retorna 422 sem criar registro; grupo criado tem `created_by` igual ao usuário autenticado.
- **TASK-104**: `GET /api/groups` e `GET /api/groups/:id` retornam o objeto `creator` (`id`, `email`) no JSON, confirmado por teste automatizado ou `read_network_requests` na tela.
- **TASK-105**: teste automatizado novo, verde — usuário com 3 grupos criados é adicionado com sucesso (sem 422) como membro de um 4º grupo criado por outro usuário.
- **TASK-106**: forçar o erro 422 (usuário no limite) e confirmar na tela que a mensagem exibida é a do backend, não o texto genérico fixo anterior.
- **TASK-107**: com um usuário no limite de 3 grupos criados, o botão "Novo grupo" aparece desabilitado com texto explicativo; cada card de grupo mostra a linha "Responsável: <e-mail>"; grupo sem `creator` (dado antigo) mostra fallback sem quebrar a tela.
- **TASK-108**: tela de membros do grupo mostra o responsável (nome/e-mail) junto ao nome/descrição do grupo já exibidos.
- **TASK-109**: item de menu com `children` renderiza expansível (abre/fecha ao clicar, ícone de seta muda de estado); item sem `children` continua navegando como link normal, sem regressão (`GroupSidebar`/`groupNavItems` inalterados).
- **TASK-110**: menu sem grupo selecionado não lista mais "Grupos" no nível raiz; expandir "Configurações" mostra "Grupos" apontando para `/dashboard`; navegar para `/dashboard` mostra o título "Grupos" no header mesmo com o item aninhado.
