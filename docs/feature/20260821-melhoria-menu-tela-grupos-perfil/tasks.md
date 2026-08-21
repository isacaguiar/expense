# Tasks — Melhoria do Menu, Tela de Grupos e Perfil

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-111` — maior ID já usado no projeto antes desta feature: `TASK-110` (`docs/feature/20260820-melhoria-tela-grupos/tasks.md`).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-111 | Criar `accountSettingsNavItems.ts`; aplicar em `simpleNavItems.ts` (renomear Resumo→Home, adicionar Relatórios, Configurações vira submenu) e `groupNavItems` (renomear Resumo→Home, Configurações deixa de ser link de edição do grupo e vira o mesmo submenu) | frontend | plan.md §1 | nenhum | Pendente |
| TASK-112 | `Sidebar.tsx`/`SidebarNavItem`: suportar item de ação (`onAction`), sem `to` nem `children`, renderizado como `ListItemButton` com `onClick` | frontend | plan.md §6 | nenhum | Pendente |
| TASK-113 | Criar helper de logout (`POST /api/logout` + limpar `localStorage` + navegar para `/`); `simpleNavItems()`/`groupNavItems()` passam a receber `navigate` e ganham item final "Sair" usando o helper | frontend | plan.md §6 | nenhum | Pendente |
| TASK-114 | `GroupController@index`: adicionar `withMax('expenses', 'date_payment')` e `with('members:id,name,email')` na query | backend | plan.md §2, §3 | nenhum | Pendente |
| TASK-115 | `SummaryEntry.tsx` e `ExpensesEntry.tsx`: redirecionar automaticamente para o grupo com `expenses_max_date_payment` mais recente mesmo havendo múltiplos grupos, em vez de mostrar a tela de escolha | frontend | plan.md §2 | nenhum | Pendente |
| TASK-116 | `Dashboard.tsx`: trocar `Grid`/`Card` por `Table`, coluna de integrantes com `AvatarGroup`/`getInitials`, preservando busca/"Novo grupo"/limite/responsável já existentes | frontend | plan.md §3 | nenhum | Pendente |
| TASK-117 | `Dashboard.tsx`: adicionar ícone de excluir grupo na tabela (diálogo de confirmação + `Snackbar` de sucesso, mesmo padrão de `ExpenseManager.tsx`), chamando `DELETE /api/groups/:id` | frontend | plan.md §3 | nenhum | Pendente |
| TASK-118 | `UserController@updateProfile` (valida `name`/`email`/`pix`, atualiza os 3 campos) + rota `PUT /api/user/profile` | backend | plan.md §4 | nenhum | Pendente |
| TASK-119 | Página `Profile.tsx` em `/profile` — formulário pré-preenchido via `GET /api/me`, submete para `PUT /api/user/profile` | frontend | plan.md §4 | nenhum | Pendente |
| TASK-120 | `UserController@changePassword` (valida `current_password` contra o hash atual, `new_password` com `confirmed`) + rota `PUT /api/user/password` | backend | plan.md §5 | nenhum | Pendente |
| TASK-121 | Página `ChangePassword.tsx` em `/change-password` — formulário com senha atual/nova/confirmação, submete para `PUT /api/user/password` | frontend | plan.md §5 | nenhum | Pendente |

## Critérios de aceite

- **TASK-111**: os dois shells (`SimpleShellLayout`, `GroupShellLayout`) mostram exatamente os mesmos 6 itens de primeiro nível (Home, Despesas, Participantes, Pagamentos, Relatórios, Configurações); expandir "Configurações" em qualquer um dos dois mostra os mesmos 3 filhos (Meus Grupos, Minha Conta, Alterar Senha) com os mesmos `href`; "Configurações" do menu com grupo selecionado não navega mais direto para `/groups/:id/edit`.
- **TASK-112**: item com `onAction` renderiza como botão clicável (não `<a>`, sem `href`); clicar chama a função passada; item com `to`/`children` mantém o comportamento anterior sem regressão.
- **TASK-113**: clicar em "Sair" em qualquer um dos dois shells dispara `POST /api/logout` (`read_network_requests` confirma), limpa `accessToken` do `localStorage` e navega para `/`; acessar qualquer rota protegida depois disso redireciona para login.
- **TASK-114**: `GET /api/groups` retorna `expenses_max_date_payment` (data ou `null`) e `members` (array de `{id, name, email}`) para cada grupo, confirmado por teste automatizado (`assertJsonStructure`/`assertJsonFragment`).
- **TASK-115**: usuário com 2+ grupos, um deles com despesa mais recente que o outro, é redirecionado automaticamente (sem tela de escolha) para o grupo com a despesa mais recente ao acessar `/summary` e `/expenses`; grupo sem nenhuma despesa nunca é escolhido se houver outro com despesa.
- **TASK-116**: `/dashboard` mostra uma tabela (não grid de cards) com nome, responsável, avatares dos integrantes (iniciais) e ícones de ação por linha; busca e botão "Novo grupo" (com limite de 3) continuam funcionando exatamente como antes.
- **TASK-117**: ícone de excluir aparece em cada linha; clicar abre diálogo de confirmação mostrando o nome do grupo; confirmar dispara `DELETE /api/groups/:id` e mostra `Snackbar` de sucesso; grupo excluído (soft delete) some da listagem.
- **TASK-118**: `PUT /api/user/profile` com `name`/`email`/`pix` válidos atualiza os 3 campos (`assertDatabaseHas`); e-mail duplicado de outro usuário retorna 422; e-mail igual ao do próprio usuário (sem mudança) não é rejeitado.
- **TASK-119**: `/profile` mostra formulário pré-preenchido com os dados de `GET /api/me`; salvar dispara `PUT /api/user/profile` e reflete o novo valor na tela.
- **TASK-120**: `PUT /api/user/password` com `current_password` errado retorna 422 sem alterar a senha; com `current_password` certo e `new_password`/`new_password_confirmation` válidos, a senha é atualizada (login com a senha antiga passa a falhar, com a nova passa a funcionar).
- **TASK-121**: `/change-password` mostra os 3 campos; senha atual incorreta mostra a mensagem de erro do backend; sucesso mostra confirmação visual.
