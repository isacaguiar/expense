# Plan — Melhoria do Menu, Tela de Grupos e Perfil

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Menu lateral unificado (specify §2.1)

- Novo arquivo `frontend/src/layouts/accountSettingsNavItems.ts`, exportando `accountSettingsNavItems(): GroupNavItem[]` = `[{ label: 'Meus Grupos', to: '/dashboard' }, { label: 'Minha Conta', to: '/profile' }, { label: 'Alterar Senha', to: '/change-password' }]` — evita duplicar a mesma lista entre os dois arquivos de navegação abaixo.
- `simpleNavItems.ts` (`frontend/src/layouts/simpleNavItems.ts:9-21`): renomeia o item "Resumo" para "Home" (mesmo `to: '/summary'`, mesmo comportamento); adiciona item "Relatórios" sem `to` (mesmo padrão placeholder já usado em "Participantes"/"Pagamentos"); "Configurações" passa a usar `children: accountSettingsNavItems()` em vez do atual `children: [{ label: 'Grupos', to: '/dashboard' }]`.
- `groupNavItems(groupId)` (`frontend/src/layouts/group/GroupSidebar.tsx:21-30`): renomeia "Resumo" para "Home"; "Configurações" deixa de ter `to: '/groups/${groupId}/edit'` e passa a ter `children: accountSettingsNavItems()` — os mesmos 3 itens do outro shell, já que não dependem de `groupId`.
- Sem mudança em `Sidebar.tsx` — já suporta itens com `children` desde a feature anterior (`melhoria-tela-grupos`, TASK-109).

## 2. "Home" e "Despesas" abrem o grupo com atividade mais recente (specify §2.2)

- `GroupController@index` (`backend/app/Http/Controllers/GroupController.php:11-34`): adiciona `->withMax('expenses', 'date_payment')` à query (a relação `Group::expenses()` já existe, `Group.php:27-30`) — expõe `expenses_max_date_payment` (`date` ou `null`) em cada grupo do JSON retornado por `GET /api/groups`.
- `SummaryEntry.tsx` e `ExpensesEntry.tsx`: a condição de redirecionamento automático muda de "só quando `res.data.length === 1`" para "sempre que `res.data.length >= 1`": ordenar os grupos recebidos por `expenses_max_date_payment` desc (grupos sem nenhuma despesa, `null`, ficam por último; desempate por `create_date` desc) e navegar direto para o primeiro da lista ordenada. A tela de escolha (grid de cards) deixa de ser exibida quando há múltiplos grupos. O estado vazio (`length === 0`, "Você ainda não participa de nenhum grupo.") continua igual.
- Troca manual de grupo continua possível pelo seletor já existente no `GroupHeader` — fora de escopo alterar esse componente.

## 3. CRUD de grupos: listagem em tabela com avatares (specify §2.3)

- `GroupController@index`: além do `withMax` do item 2, adiciona `->with('members:id,name,email')` (junto do `with('creator:id,email')` já existente da feature anterior) — expõe a lista de integrantes de cada grupo no mesmo payload de `GET /api/groups`.
- `Dashboard.tsx`: troca o `Grid`/`Card` atual por `Table`/`TableRow` do MUI (colunas: Nome, Responsável, Integrantes, Ações). A coluna "Integrantes" renderiza um `AvatarGroup` com um `Avatar`+`getInitials(member.email)` por membro — mesmo padrão já usado em `GroupMembersForm.tsx:118-129`. A coluna "Ações" mantém os 3 ícones existentes (editar/participantes/despesas) e ganha um 4º ícone de excluir, reaproveitando o padrão de diálogo de confirmação + `Snackbar` de sucesso já usado em `ExpenseManager.tsx` (feature anterior, TASK-099) chamando `DELETE /api/groups/:id` (já implementado, soft delete — `GroupController::destroy`, linhas 82-90).
- Busca, botão "Novo grupo" e o limite de 3 grupos criados (feature anterior, `melhoria-tela-grupos`) continuam funcionando sobre a nova estrutura — é só mudança de layout (cards → tabela), a lógica de filtro/limite não muda.

## 4. Página de Perfil / Minha Conta (specify §2.4)

- Backend: novo método `UserController@updateProfile` — valida `name` (`required|string|max:255`), `email` (`required|email|unique:ex_users,email,{id}` ignorando o próprio usuário), `pix` (`nullable|string|max:100`, mesma regra de `atualizarPix`); atualiza os 3 campos e salva. Nova rota `PUT /api/user/profile`, mesmo agrupamento de `/user/pix` (`routes/api.php:22`).
- Frontend: nova página `Profile.tsx`, rota `/profile` dentro do mesmo grupo de rotas de `SimpleShellLayout` que já contém `/dashboard`/`/expenses` (conceito de conta, não de grupo — deve funcionar independente de haver grupo selecionado). Busca dados iniciais via `GET /api/me` (já existe, `AuthController::me`); formulário com os 3 campos; submete para `PUT /api/user/profile`.

## 5. Alterar Senha (specify §2.5)

- Backend: novo método `UserController@changePassword` — valida `current_password` (`required`, comparado com `Hash::check($request->current_password, $user->password)`, retorna 422 se não bater), `new_password` (`required|string|min:8|confirmed`, exige `new_password_confirmation` no payload, convenção padrão do Laravel); atualiza `password` com `Hash::make($request->new_password)`. Nova rota `PUT /api/user/password`.
- Frontend: nova página `ChangePassword.tsx`, rota `/change-password`, mesmo grupo de rotas de `SimpleShellLayout` do item 4. Formulário com 3 campos (senha atual, nova senha, confirmar nova senha).

## 6. Botão "Sair" no menu (specify §2.6)

- `GroupNavItem` (`frontend/src/layouts/group/GroupSidebar.tsx:14-19`) ganha um 4º campo opcional, `onAction?: () => void`, alternativo a `to`/`children` — item de ação, não de navegação.
- `Sidebar.tsx`/`SidebarNavItem`: novo branch de renderização para item com `onAction` (sem `to`, sem `children`) — `ListItemButton` com `onClick={item.onAction}` em vez de `component={RouterLink}`, mesmo `sx`/ícone dos demais itens.
- `simpleNavItems.ts` e `groupNavItems(groupId)`: ambos ganham um item final "Sair" com `onAction` chamando uma função `logout(navigate)` extraída para um helper compartilhado (`frontend/src/auth/logout.ts`, por exemplo) que faz `POST /api/logout` (endpoint já existe, `AuthController::logout`, invalida o JWT no servidor), limpa `localStorage` e navega para `/` — corrige a lacuna do `Navbar.tsx` legado, que só limpava o `localStorage` sem chamar o backend.
- Como `Sidebar` é um componente compartilhado, `onAction` precisa de acesso a `useNavigate()` — resolvido no nível de `simpleNavItems.ts`/`groupNavItems`, que já são funções (não componentes), recebendo `navigate` como parâmetro extra e passando adiante para o helper de logout.

## N. Ordem de execução

Item 1 (menu) não é pré-requisito técnico dos itens 4 e 5 — as rotas `/profile` e `/change-password` podem ser implementadas e testadas diretamente por URL antes do menu apontar para elas — mas só ficam alcançáveis pela navegação depois que o item 1 existir, então faz sentido implementá-lo primeiro. Itens 2 e 3 tocam o mesmo método (`GroupController@index`) e evoluem naturalmente juntos, mas não dependem um do outro (2 é sobre Home/Despesas, 3 é sobre a listagem de grupos). Itens 4 e 5 são independentes entre si e do resto — funcionalidade de conta isolada, sem relação com grupos. Item 6 (Sair) depende tecnicamente do item 1 já ter mudado a assinatura de `simpleNavItems()`/`groupNavItems()` para aceitar `navigate` — faz mais sentido implementá-lo logo depois do item 1, antes de 2-5. Ordem em `tasks.md`: 1 primeiro (estrutura de navegação), 6 logo em seguida (mesma mudança de assinatura das funções de nav), depois 2 e 3 (mesmo arquivo backend, mais próximas do que já existe hoje), depois 4 e 5 (funcionalidade nova de conta, maior esforço por exigirem endpoint novo).
