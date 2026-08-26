# Plan — Grid de Pagamentos com Pix (QR Code + Copia e Cola)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260825

---

## 1. Buscar membros do grupo com email/pix (specify §2.3)

- `Payments.tsx` ganha um `useEffect` que busca `GET /groups/{groupId}/members` (mesmo padrão de `ExpenseForm.tsx:79-85`), tipado localmente como `type GroupMemberPix = { id: number; name: string; email: string; pix: string | null }`.
- Guardado em estado (`members: GroupMemberPix[]`) e indexado por `id` (`Map<number, GroupMemberPix>`) pra resolver, a partir de `settlement.to_user_id`, se o credor tem `pix` e qual o `email`.

## 2. Região B — "Valores a pagar por pessoa" (specify §R1-B, §R2, §R3)

- Novo componente `frontend/src/components/PayableSettlementList.tsx`, variação de `SettlementList.tsx` (mesmo dado — `settlements`/`balances` — mesma visual base) que aceita um `onSelect(settlement: SummarySettlement) => void` e chama `onSelect` ao clicar no card inteiro (`CardActionArea`, como já usado em `ExpenseManager.tsx` pros cards de despesa antes da migração pra tabela — padrão já conhecido no código).
- Por que um componente novo e não alterar `SettlementList.tsx`: `SettlementList` é usado hoje em `SummarySidePanel` (`GroupSummary.tsx`/`ExpenseManager.tsx`) só como exibição — torná-lo clicável ali também não foi pedido e mudaria comportamento fora do escopo desta feature (specify §4). Um componente irmão evita side effect nas outras 2 telas.
- `Payments.tsx` decide, ao receber o clique (`onSelect`), se o credor (achado 2.3, via o `Map` do item 1) tem `pix`: se sim, abre o diálogo do item 3 com `email`/`valor` do settlement; se não, mostra um `Snackbar`/mensagem "Fulano ainda não cadastrou uma chave Pix." (specify §R3) sem chamar a API.

## 3. Diálogo de Pix — QR Code + copia-e-cola (specify §R2, §R4)

- Novo componente `frontend/src/components/PixPaymentDialog.tsx`: props `{ open, onClose, targetEmail, targetName, amount }`. Ao abrir (`useEffect` em `open`/`targetEmail`/`amount`), chama `GET /pix/generate?email=<targetEmail>&valor=<amount>` e guarda `{qrcode, copiacola}` ou erro.
- Estados: carregando (spinner), sucesso (`<img src={qrcode}>` + `TextField` read-only com `copiacola` + botão "Copiar" usando `navigator.clipboard.writeText`, com feedback de "Copiado!"), erro (mensagem da API — `err.response?.data?.message` — ou genérica).
- `Payments.tsx` guarda só `pixDialogTarget: { email: string; name: string; amount: number } | null` — o diálogo é montado/desmontado por esse estado, sem duplicar lógica de fetch no componente pai.

## 4. Grid e tema (specify §R1, §R5, §R6)

- `Payments.tsx` envolve o retorno com `DespesasThemeScope` (mesmo componente de `docs/feature/20260825-redesign-visual-despesas/`, sem alteração nele) — reaproveita o tema já existente, sem criar um tema novo.
- Estrutura do grid: `<Grid container spacing={3}>` com `<Grid size={{ xs: 12, md: 7 }}>` (região A, despesas — mesmo conteúdo/comportamento de hoje) e `<Grid size={{ xs: 12, md: 5 }}>` (região B, `PayableSettlementList`) — mesma convenção de breakpoints (`xs`/`md`/`lg`) já usada em `ExpenseManager.tsx`/`GroupSummary.tsx`.
- Antes de codar o grid em si, carregar a skill `impeccable` (pedido explícito do usuário) pra decidir hierarquia visual, espaçamento e estados vazios das duas regiões — seguindo o modo "Operate" (tela de tarefa, não marketing) já implícito no resto do app.
- Título/cabeçalho de cada região (`Typography` "Despesas do ciclo" / "Valores a pagar") — texto novo, não existia antes (a tela não tinha seções nomeadas).

## N. Ordem de execução

Sem dependência circular. Ordem: (1) buscar membros com email/pix → (2) `PixPaymentDialog` (isolado, não depende do grid) → (3) `PayableSettlementList` (usa o resultado de 1) → (4) grid final em `Payments.tsx` conectando os três + tema. Cada item é testável isoladamente antes do próximo.
