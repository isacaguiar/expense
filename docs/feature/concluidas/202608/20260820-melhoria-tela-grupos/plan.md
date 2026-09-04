# Plan — Melhoria da Tela de Grupos

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260820

---

## 1. Limite de criação de grupos por usuário (specify §2.1)

- Migration nova, aditiva e local (`add_created_by_to_ex_groups_table`): adiciona `created_by` (`unsignedBigInteger`, nullable, `after('id')`) em `ex_groups`, com `foreign('created_by')->references('id')->on('ex_users')->nullOnDelete()` — mesmo padrão já usado em `2025_06_13_004311_add_invited_by_to_users_table.php:10-16`. Nullable porque grupos já existentes não têm criador registrado e o backfill é fora de escopo (specify §3).
- `Group.php`: adiciona `created_by` ao `$fillable` (linha 11-17) e a relação `creator(): belongsTo(User::class, 'created_by')`.
- `GroupController@store` (`backend/app/Http/Controllers/GroupController.php:35-56`): antes de `Group::create($data)`, conta `Group::where('deleted', 0)->where('created_by', auth()->id())->count()`; se `>= 3`, retorna `response()->json(['message' => '...'], 422)` sem criar o grupo. `$data['created_by'] = auth()->id()` é setado junto com `create_date`/`deleted` (linha 45-46).
- `GroupController@index` (linha 11-33): adiciona `with('creator:id,email')` na query — já entrega o responsável junto no mesmo payload, servindo também o requisito 2.3 sem endpoint novo.
- Frontend `GroupForm.tsx` (`handleSubmit`, linha 58-90): o `catch` genérico (linha 85-87, hoje só seta "Erro ao salvar grupo. Tente novamente.") passa a preferir `err.response?.data?.message` quando existir, com o texto genérico como fallback — assim a mensagem de limite do backend chega ao usuário sem precisar de um tratamento especial só para esse erro.
- Frontend `Dashboard.tsx`: busca `currentUserId` via `GET /api/me` (endpoint já usado em `ExpenseForm.tsx:88`), calcula `myGroupsCount = groups.filter(g => g.created_by === currentUserId).length` e desabilita o botão "Novo grupo" (linha 81-83) com texto explicando o limite quando `myGroupsCount >= 3`. É reforço de UX, não a validação — o backend (item acima) continua sendo a fonte de verdade.

## 2. Participação sem limite via convite (specify §2.2)

- Sem mudança de código de produção — é uma garantia de não regressão do fluxo existente (`POST /api/groups/{id}/members`, `routes/api.php:29-36`), que já não tem limite hoje.
- Ação: teste backend novo que cria um usuário já no limite de 3 grupos como `created_by` (item 1) e confirma que ele continuar podendo ser **adicionado como membro** de um 4º/5º grupo criado por outro usuário, sem 422 nem qualquer bloqueio — prova que o limite de criação não vaza para o fluxo de convite/adição de membro.

## 3. Exibição do responsável pelo grupo (specify §2.3)

- Backend: além de `index` (item 1), `GroupController@show` (linha 58-64) também ganha `with('creator:id,email')` — necessário para a tela de membros, que busca o grupo via `GET /api/groups/:id` (`GroupMembersForm.tsx:48`).
- Frontend `Dashboard.tsx`: o type `Group` (linha 23-28) ganha `created_by: number | null` e `creator?: { id: number; email: string } | null`; cada `Card` (linha 94-117) exibe uma linha `Responsável: {group.creator?.email ?? '—'}` (fallback cobre grupos antigos sem criador, já que a coluna é nullable).
- Frontend `GroupMembersForm.tsx`: o type `Group` (linha 24) ganha o mesmo campo `creator`; exibido junto ao nome/descrição já existentes (bloco `{group && (...)}`, linha 96-102).

## 4. "Grupos" como submenu de "Configurações" (specify §2.4)

- `Sidebar.tsx` (`frontend/src/layouts/Sidebar.tsx:38-73`): o tipo `GroupNavItem` (`group/GroupSidebar.tsx:14-18`) ganha um campo opcional `children?: GroupNavItem[]`. Item com `children` passa a renderizar como `ListItemButton` expansível (estado local `open`, ícone de seta, `Collapse` do MUI) contendo uma sublista indentada, em vez de link direto — item sem `children` mantém o comportamento atual (link ou placeholder `href="#"`).
- `simpleNavItems.ts` (linha 9-16): remove "Meus Grupos" do nível raiz e adiciona como filho de "Configurações": `{ label: 'Configurações', icon: SettingsOutlinedIcon, children: [{ label: 'Grupos', icon: HomeOutlinedIcon, to: '/dashboard' }] }`.
- `SimpleShellLayout.tsx`: a derivação do título do header (hoje `simpleNavItems().find(item => item.to === location.pathname)`) passa a procurar também dentro de `children` (flatten antes do `.find`), para que a rota `/dashboard` continue resolvendo o título "Grupos" mesmo estando aninhada.
- Sem mudança no menu com grupo selecionado (`groupNavItems`, `GroupSidebar.tsx`), conforme specify §3 — o "Configurações" desse menu continua sendo link direto para `/groups/:id/edit`, não ganha `children`.

## N. Ordem de execução

Item 1 (limite de criação) é pré-requisito técnico do item 3 (exibição do responsável) — ambos dependem da mesma coluna `created_by`/relação `creator`; fazer 1 antes de 3. Item 2 (teste de não-regressão do convite) depende do item 1 já estar implementado, senão não há o que testar. Item 4 (submenu) é tecnicamente independente dos outros três — não compartilha coluna, model ou componente — pode ser feito em qualquer momento; a ordem em `tasks.md` o coloca por último por ser puramente de navegação/UI, sem dependência de dado novo.
