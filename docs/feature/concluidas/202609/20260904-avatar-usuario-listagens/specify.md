# Specify — Avatar de Usuário nas Listagens

> Feature: usar a foto de perfil do usuário (quando cadastrada) em vez de só o círculo com iniciais, nos pontos onde o app identifica quem é o credor/participante de uma despesa. Pedido novo do usuário, sem task/feature anterior aplicável — investigado e confirmado nesta conversa antes de criar esta pasta.

Versão: 1.0 · Criado em: 20260904

---

## 1. Problema

Hoje o app identifica usuários (credor de despesa, membro em saldo/à pagar, participante ao criar despesa) só por nome em texto puro, ou por um `Avatar` do MUI com iniciais (`getInitials`) — nunca com a foto cadastrada em `Profile.tsx`. O backend já guarda e resolve essa foto (`avatar_url`, ver §2.2), mas as telas listadas em §2.3 não a recebem nem a exibem. O usuário pediu: usar a imagem quando existir, manter o círculo com inicial como fallback quando não existir, e mostrar o nome em tooltip ao passar o mouse sobre o avatar — nas 5 áreas de tela citadas em §2.3.

## 2. Achados confirmados

### 2.1 Não existe componente de avatar reutilizável com imagem+fallback+tooltip

Cada tela usa `<Avatar>` do MUI (`@mui/material`) diretamente com `children={getInitials(nome)}` (helper em `frontend/src/layouts/group/getInitials.ts`), sem `src` e sem `Tooltip`. Os únicos 2 pontos que já combinam `src`+fallback de iniciais são `frontend/src/layouts/group/GroupHeader.tsx:118-130` e `frontend/src/pages/Profile.tsx:190-192` — ambos exibem só o avatar do próprio usuário logado (dado vindo de `GET /api/me`), nenhum dos dois tem `Tooltip`.

### 2.2 O dado de imagem já existe no backend, mas não chega às telas-alvo

`backend/app/Models/User.php:66-73` define o accessor `getAvatarUrlAttribute`, que resolve por precedência: foto enviada pelo usuário (`photo_path`, servida por rota assinada) > `avatar_url` vindo do login Google > `null`. Por ser um accessor sobre uma coluna real (`avatar_url`), ele aparece automaticamente em qualquer serialização de um model `User` completo — inclusive `GET /api/groups/{groupId}/members` (`backend/app/Http/Controllers/GroupMemberController.php:17-26`, `Group::with('members')` sem `select` restrito), que **já devolve `avatar_url` hoje**, mesmo sem nenhum frontend consumir esse campo ainda.

Já o resumo do ciclo (`ExpenseController::computeCycleSummary`, `backend/app/Http/Controllers/ExpenseController.php:1115-1245` — usado por `GET /api/groups/{id}/expenses/summary`, fonte de dado de 4 das 5 áreas pedidas) achata tudo em campos soltos sem `avatar_url`: `expenses[].payerName` é string (linha 1132), `expenses[].participants` é `string[]` de nomes sem `id` (linha 1133), `balances[]` é `{user_id, name, balance}` (linha 1157) — nenhum carrega imagem.

### 2.3 As 5 áreas pedidas hoje não usam avatar com imagem

- **Despesas / credor**: `frontend/src/pages/ExpenseManager.tsx` (cards linhas 395-399, tabela desktop 585-595, modal de detalhe 666-676) — iniciais só, via `useGroupCycle`/`computeCycleSummary`. `frontend/src/pages/Payments.tsx:230-243` ("Despesas do ciclo") e `frontend/src/components/CycleDetailPanel.tsx:103-124` (Home do grupo) nem usam `Avatar` — é `Typography`/`ListItemText` puro "Credor/Pago por {nome}", mesma fonte de dado.
- **Saldo**: `frontend/src/components/BalanceCards.tsx:20-42` — iniciais só, consome `SummaryBalance[]` (mesmo `computeCycleSummary`).
- **À pagar**: `frontend/src/components/SettlementList.tsx:22-52` e `frontend/src/components/PayableSettlementList.tsx:58-136` — iniciais só; ambos resolvem nome (e resolveriam avatar) via um `Map` construído a partir de `balances`, já que `settlements` só traz `user_id`.
- **Pagamentos**: `frontend/src/pages/Payments.tsx` reaproveita os itens acima (lista de despesas do ciclo sem avatar, "Valores a pagar" via `PayableSettlementList`) — mesmas fontes de dado.
- **Entrada** (criar/editar despesa): `frontend/src/pages/ExpenseForm.tsx:214-244` (campo "Pagador" é `<TextField select>` de texto; participantes são `<Checkbox>` com `label={nome}`) e `frontend/src/pages/ExpenseView.tsx:330-354` (mesmo padrão, modo edição/leitura) — nenhum avatar/inicial hoje. Ambos consomem `GET /api/groups/{id}/members`, que **já** traz `avatar_url` (§2.2) — não precisa de mudança de backend aqui.

### 2.4 Tipos locais do frontend não carregam avatar nem id de participante

`SummaryExpense`/`SummaryBalance`/`SummarySettlement` (`frontend/src/hooks/useGroupCycle.ts:24-51`) não têm `avatarUrl`; `participants` é `string[]` (nem tem `id`). Tipos locais por tela (`GroupMember` em `ExpenseForm.tsx`/`ExpenseView.tsx`, `GroupMemberPix` em `Payments.tsx`) também não têm `avatarUrl`, mesmo já vindo da API (§2.2).

## 3. Fora de escopo desta feature

- Upload/edição de foto de perfil — já existe em `Profile.tsx`, não muda.
- Padronizar avatares fora das 5 áreas pedidas (ex.: `GroupMembersForm.tsx`, `Dashboard.tsx`/`AvatarGroup`, seletor de grupo do header, `GroupGrossDebtsPanel.tsx`) — fica como está.
- Expor `avatar_url` em `GET /api/groups` (`GroupController::index`) — não alimenta nenhuma das 5 áreas pedidas (confirmado em §2.3); mudança adiada até algo depender disso.
- Criar tipo `User`/`Member` central compartilhado no frontend — os ajustes ficam nos tipos locais das telas tocadas (§2.4), sem introduzir uma abstração nova.
- Endpoint legado `ExpenseController::indexByGroup` (listagem por ano/mês, linhas 30-110) — não é consumido por nenhuma das 5 áreas pedidas (confirmado: `ExpenseManager`/`Payments`/`CycleDetailPanel` usam `useGroupCycle`/`computeCycleSummary`).
