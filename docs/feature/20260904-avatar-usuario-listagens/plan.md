# Plan — Avatar de Usuário nas Listagens

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260904

---

## 1. Backend — expor avatar no resumo do ciclo (specify §2.2)

Mudança aditiva (Constitution §4.1) em `ExpenseController::computeCycleSummary` (`backend/app/Http/Controllers/ExpenseController.php:1115-1245`), sem migration (coluna/accessor `avatar_url` já existem):

- `expenses[]` (linha ~1123-1141): acrescentar `payerAvatarUrl` (string|null), sibling de `payerName`, resolvido de `$entry['expense']->payer->avatar_url`. Acrescentar `participantDetails` (`{id, name, avatarUrl}[]`), sibling de `participants` — **não remove nem muda o tipo de `participants`** (continuaria `string[]`), só adiciona o campo novo já com `id` (hoje ausente) e `avatarUrl`, resolvido de `$entry['expense']->payers->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'avatarUrl' => $p->avatar_url])`.
- `balances[]` (linha ~1155-1158, 1201-1208): acrescentar `avatarUrl` a cada entrada, resolvido de `$member->avatar_url` no mesmo loop que já monta `user_id`/`name`. Como `SettlementList`/`PayableSettlementList` já resolvem nome via um `Map` construído a partir de `balances` (specify §2.3), isso cobre **Saldo e À pagar** sem tocar `settlements`.
- Nenhuma mudança em `settlements[]`, `totals`, `cycle` — fora do escopo do pedido.
- `GroupMemberController::index` (`GET /api/groups/{id}/members`) e o model `User` **não mudam** — já devolvem `avatar_url` hoje (specify §2.2).

## 2. Frontend — componente `UserAvatar` reutilizável + tipos (specify §2.1, §2.4)

- Criar `frontend/src/components/UserAvatar.tsx`: `Avatar` do MUI envolvido em `Tooltip title={name}`, `src={avatarUrl ?? undefined}`, fallback `children={getInitials(name)}` (reaproveita `frontend/src/layouts/group/getInitials.ts`, sem duplicar). Props: `{ name: string; avatarUrl?: string | null; size?: number; sx?: SxProps<Theme> }` — `sx` mescla com o estilo padrão (`bgcolor: brandColors.primaryLight`, `color: brandColors.primary`, `fontSize: '0.85rem'`, mesmo padrão já usado em `BalanceCards`/`SettlementList`/`PayableSettlementList`) para as telas que precisarem de tamanho/posição diferente.
- Atualizar `frontend/src/hooks/useGroupCycle.ts`: `SummaryExpense` ganha `payerAvatarUrl: string | null` e `participantDetails: { id: number; name: string; avatarUrl: string | null }[]`; `SummaryBalance` ganha `avatarUrl: string | null`. `SummarySettlement` não muda (specify §2.3 — resolve via `balances`).
- Atualizar tipos locais que já recebem `avatar_url` da API mas não o declaram: `GroupMember` em `ExpenseForm.tsx:24-27` e `ExpenseView.tsx:28` ganha `avatarUrl` (mapeado de `avatar_url` na resposta); `GroupMemberPix` em `Payments.tsx:43` idem.

## 3. Frontend — aplicar em Despesas / Credor (specify §2.3)

- `ExpenseManager.tsx`: trocar `<Avatar>{getInitials(exp.payerName || '-')}</Avatar>` por `<UserAvatar name={exp.payerName || '-'} avatarUrl={exp.payerAvatarUrl} />` nos 3 pontos (cards 395-399, tabela 585-595, modal 666-676).
- `Payments.tsx:230-243` e `CycleDetailPanel.tsx:103-124`: acrescentar `UserAvatar` ao lado do texto "Credor/Pago por" (hoje só `Typography`/`ListItemText`, sem `Avatar` nenhum — precisa de ajuste de layout, não só troca de componente).

## 4. Frontend — aplicar em Saldo e À pagar (specify §2.3)

- `BalanceCards.tsx:25-27`: trocar `<Avatar>{getInitials(balance.name)}</Avatar>` por `<UserAvatar name={balance.name} avatarUrl={balance.avatarUrl} />`.
- `SettlementList.tsx` e `PayableSettlementList.tsx`: os dois já resolvem nome via `Map` a partir de `balances` (`nameById`); acrescentar `avatarById` do mesmo jeito (`new Map(balances.map(b => [b.user_id, b.avatarUrl]))`) e trocar os 2 `<Avatar>{getInitials(nameFor(...))}</Avatar>` de cada arquivo (from/to) por `<UserAvatar name={nameFor(...)} avatarUrl={avatarFor(...)} />`.

## 5. Frontend — aplicar em Pagamentos (diálogo Pix) e Entrada (specify §2.3)

- `Payments.tsx`: diálogo de Pix usa `GroupMemberPix` (já com `avatarUrl` via item 2) — acrescentar `UserAvatar` onde hoje só mostra nome/e-mail do credor.
- `ExpenseForm.tsx:214-226` (campo "Pagador") e `ExpenseView.tsx:330-336`: trocar o `<TextField select>`/`<MenuItem>` de texto puro por `MenuItem` com `UserAvatar` + nome lado a lado (MUI permite `Avatar` dentro de `MenuItem`/`Select` via `renderValue`/conteúdo customizado).
- `ExpenseForm.tsx:228-244` (participantes) e `ExpenseView.tsx:338-354`: acrescentar `UserAvatar` ao lado de cada `Checkbox`+`label` de participante.
- `ExpenseView.tsx:389` (modo leitura, "Credor: {creditorName}" em texto puro): acrescentar `UserAvatar` junto ao texto.

## N. Ordem de execução

Sem dependência circular. `§1` (backend) e `§2` (componente `UserAvatar` + tipos) não dependem um do outro — podem ser feitos em paralelo ou em qualquer ordem. `§3` e `§4` dependem de **ambos** `§1` e `§2` (precisam do campo novo do backend E do componente/tipo novos). `§5` depende só de `§2` — a parte de Entrada já tem `avatarUrl` disponível hoje via `/members` (specify §2.2), sem depender de `§1`. Ordem sugerida em `tasks.md`: TASK-001 (§1) e TASK-002 (§2) primeiro (em qualquer ordem), depois TASK-003 (§3), TASK-004 (§4), TASK-005 (§5).
