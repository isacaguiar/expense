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

## 5. Confirmação de pagamento do settlement pelo devedor (specify §2.7, §R7, §R8)

- **Migration** `ex_settlement_confirmations` (aditiva, tabela nova — mesmo padrão de `ex_group_cycle_snapshots`): `group_id` (FK `ex_groups`), `cycle_start`/`cycle_end` (date), `from_user_id`/`to_user_id` (FK `ex_users`), `amount` (decimal, snapshot do valor no momento da confirmação), `proof_path` (string, obrigatório — diferente de `Quota.payment_proof_path`, que é opcional), `confirmed_at` (timestamp). `unique(['group_id', 'cycle_start', 'from_user_id', 'to_user_id'])` — uma confirmação por par por competência; reenviar substitui (`updateOrCreate`), cobrindo troca de comprovante sem precisar de "unconfirm" (specify §4).
- **Model** `SettlementConfirmation` (mesmo padrão de `Quota`): `$fillable` os campos acima, `$appends = ['proof_url']` com o mesmo accessor `Storage::disk('public')->url($this->proof_path)`.
- **Endpoint novo** `POST /groups/{groupId}/settlements/confirm` em `ExpenseController` (mesmo controller que já computa settlements, evita duplicar a lógica de `computeCycleSummary`): recebe `to_user_id` + `comprovante` (`required|image|max:5120` — obrigatório aqui, ao contrário do opcional em `pay()`, porque o comprovante É o conteúdo da ação, não um anexo opcional). Calcula a competência vigente com `BillingCycle::cycleFor($group->closing_day, Carbon::now())` (mesmo padrão de `pay()`/`unpay()` — sempre "agora", nunca a competência navegada), roda `computeCycleSummary()` e confirma que existe de fato um settlement `from_user_id === auth()->id() && to_user_id === $data['to_user_id']` — rejeita com 422 se não existir (evita confirmar um valor que não corresponde a nada real). Guarda o arquivo em `comprovantes-settlements/` (diretório separado de `comprovantes/`, que é dos comprovantes de despesa) e faz `updateOrCreate` na tabela nova com o valor do settlement encontrado.
- **Exposição no summary**: um helper privado `attachSettlementConfirmations($groupId, Carbon $start, Carbon $end, array $settlements): array` busca as confirmações da competência (`group_id` + `cycle_start`) e decora cada settlement com `confirmedProofUrl`/`confirmedAt` (`null` se não houver). Chamado nos 3 pontos que hoje devolvem `settlements` na resposta HTTP (`summary()`, `close()`, `reopen()` — os 3 convergem pra um único `return response()->json([...])` cada, então é uma linha por método, não por branch interno). Cobre os 3 casos de `summary()` (`computeCycleSummary` ao vivo, `manualSnapshot`, `cycleSnapshotFor` de competência fechada por data) porque a decoração acontece depois que qualquer um dos 3 já produziu o array de settlements, não dentro de cada um.
- **Rota**: `Route::post('/groups/{groupId}/settlements/confirm', [ExpenseController::class, 'confirmSettlement'])`, dentro do grupo `jwt.auth` já existente.

## 6. Frontend da confirmação (specify §R7, §R9)

- `SummarySettlement` (`useGroupCycle.ts`) ganha `confirmedProofUrl: string | null` e `confirmedAt: string | null`.
- `PayableSettlementList.tsx` deixa de ser um `CardActionArea` cobrindo o card inteiro (só fazia sentido quando a única ação era abrir o Pix) e passa a ter botões explícitos, condicionados a quem está vendo: se `currentUserId === settlement.from_user_id` (o usuário é o devedor daquele settlement), mostra "Pagar com Pix" (abre `PixPaymentDialog`, como já era) **e** "Enviar comprovante"/"Reenviar comprovante" (abre um diálogo de upload novo, reaproveitando o mesmo padrão visual do diálogo de confirmar despesa de `Payments.tsx`); se já `confirmedProofUrl`, mostra um chip "Comprovante enviado" + link "Ver comprovante" ao lado dos botões. Quem não é o devedor daquele settlement (credor ou terceiro) só vê o status (achado 2.7 — sem botão de ação, R8), sem nenhum botão de Pix/comprovante.
- `Payments.tsx` ganha o estado do diálogo de upload (`confirmSettlementTarget`/`selectedSettlementFile`, mesmo padrão de `confirmExpenseId`/`selectedFile` já existente) e a chamada `POST /groups/{id}/settlements/confirm` (multipart, campo `comprovante` + `to_user_id`).

## 7. Corrigir comprovante ausente nas telas de Despesas (specify §2.8, §R9)

- `ExpenseManager.tsx`: no modal de detalhamento (`detailExpense`) e opcionalmente como um ícone na linha da tabela quando `exp.paid && exp.paymentProofUrl`, mesmo padrão de link `<a href target="_blank">` já usado em `Payments.tsx`.
- `ExpenseView.tsx`: hoje busca `GET /api/expenses/{id}` (endpoint `show()`), que devolve `ExpenseDetail` sem nenhum campo de pagamento/comprovante — precisa verificar se `show()` já tem acesso à `Quota` da competência vigente pra expor `paid`/`paymentProofUrl` também ali, ou se a tela deve continuar sem esse dado (a origem do dado é outra: por-competência, não por-despesa "estática"). Decisão tomada durante a implementação, registrada no `implementation.md`.

## N. Ordem de execução

Sem dependência circular entre os itens 1-4 (execução original) e 7 (bug fix, independente). O item 6 (frontend da confirmação) depende do item 5 (backend) já estar pronto — precisa do endpoint e do campo novo em `settlements` pra funcionar de ponta a ponta. Ordem: (1) buscar membros com email/pix → (2) `PixPaymentDialog` → (3) `PayableSettlementList` → (4) grid final em `Payments.tsx` → (5) backend de confirmação → (6) frontend de confirmação → (7) corrigir comprovante ausente em Despesas (pode entrar em paralelo com 5/6, é independente).
