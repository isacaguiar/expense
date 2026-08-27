# Plan — Dashboard: resumo Credor→devedores por grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260827

---

## 1. Endpoint de resumo bruto por grupo (specify §2.1)

- Nova rota `GET /groups/{groupId}/expenses/gross-debts` em `backend/routes/api.php`, ao lado de `summary`/`cycles`.
- Novo método `ExpenseController::grossDebts($groupId, Request $request)`:
  - `$group = Group::findOrFail($groupId); $this->authorizeGroupMembership($group);` — mesmo padrão de `summary()`.
  - `$data = $request->validate(['cycles_ago' => 'nullable|integer']);` — mesma navegação por competência de `summary()` (não fica preso só à vigente, já que o requisito de navegação por linha em §2.2 do specify precisa disso).
  - `$cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);` — mesmo cálculo de `summary()`.
  - `$entries = $this->collectCycleEntries($groupId, $cycle['start'], $cycle['end']);` — reaproveita a mesma fonte de dado que `computeCycleSummary` usa (nenhum SQL novo).
  - Agregação (nova, sem netting): para cada `$entry` com `paid === false`, credor = `$entry['expense']->payer` (`id`, `name`, `email`), participantes = `$entry['expense']->payers` (exclui o próprio credor da lista de devedores — ele não deve a si mesmo); valor por devedor = `$entry['value'] / max($entry['expense']->payers->count(), 1)` (mesma fórmula de `valuePerPerson` em `computeCycleSummary`). Acumula num array associativo `[creditorId][debtorId] += valor`, com `round(..., 2)` no total final de cada par.
  - Monta a árvore de resposta: `creditors: [{ creditor: { id, name, email }, debtors: [{ id, name, amount }] }]` — só credores com pelo menos um devedor (ciclo sem pendência não aparece).
  - Retorna `response()->json(['cycle' => ['start' => ..., 'end' => ..., 'status' => ...], 'creditors' => [...]])`.
- Por quê essa abordagem e não outra: reaproveitar `collectCycleEntries` evita duplicar a lógica já complexa de coleta de despesas do ciclo (diretas, parceladas, fixas projetadas — `ExpenseController.php:995-1055`); a agregação sem netting é intencionalmente um método novo (`grossDebts`), não uma flag em `computeCycleSummary`, porque os dois têm consumidores e formatos de resposta diferentes (árvore por credor vs. saldo líquido + settlements par-a-par) — misturar os dois num único método aumentaria a complexidade condicional sem necessidade.

## 2. Hook + painel da árvore Credor→devedores (specify §2.2, §2.3)

- Novo hook `frontend/src/hooks/useGroupGrossDebts.ts`: dado `groupId` e `cyclesAgo`, busca `GET /groups/{groupId}/expenses/gross-debts?cycles_ago=N` — só quando o hook é efetivamente montado (o "carregar sob demanda" de specify §2.3 vem do próprio componente que o usa só ser renderizado quando a linha expande, não de uma flag `enabled` extra). Expõe `{ data, loading, error }`, mesmo padrão de tratamento de erro 401 dos outros hooks (`useGroupCycle`, `useGroupCycleHistory`).
- Novo componente `frontend/src/components/GroupGrossDebtsPanel.tsx`, props `{ groupId: string }`:
  - Estado local `cyclesAgo` (navegação de competência só deste painel, independente de outras linhas expandidas) — mesmas setas anterior/próximo de `GroupSummary.tsx`.
  - Usa `useGroupGrossDebts(groupId, cyclesAgo)`; renderiza a árvore: por credor, nome do credor como cabeçalho, lista indentada de devedores (nome + valor formatado).
  - Cada devedor tem: um `IconButton` de Pix (`ContentCopyOutlined` ou ícone de Pix já usado em `Payments.tsx`) que abre `PixPaymentDialog` (já existente, sem alteração) com `targetEmail = creditor.email`, `targetName = creditor.name`, `amount = debtor.amount`.
  - Cada devedor também tem um botão "Informar pagamento" que só alterna um estado local `informedIds: Set<string>` (chave `${creditorId}-${debtorId}`) — troca o visual (ex.: `Chip` "Informado" no lugar do botão) sem chamar nenhuma API. Resetado ao trocar `cyclesAgo` (novo ciclo, nova competência, nada foi "informado" ainda ali).
  - Estado vazio (`creditors: []`): "Nenhuma pendência neste ciclo." — não é erro.
- Por quê essa abordagem e não outra: reaproveitar `PixPaymentDialog` sem modificação evita duplicar a integração com `GET /api/pix/generate` que `Payments.tsx` já resolveu; manter a navegação de ciclo e o estado "informado" só no componente do painel (não em `Dashboard.tsx`) mantém cada linha expandida independente, como pedido no specify.

## 3. Linha expansível no Dashboard (specify §2.2, §2.3)

- `frontend/src/pages/Dashboard.tsx` ganha `const [expandedGroupIds, setExpandedGroupIds] = useState<Set<number>>(new Set())` — várias linhas podem estar expandidas ao mesmo tempo, cada uma com seu próprio `GroupGrossDebtsPanel` (que já isola sua própria navegação de ciclo, item 2 acima).
- Nova primeira coluna na tabela (`TableHead`/`TableRow`, antes de "Nome") com `IconButton` (seta down/up, `ExpandMoreIcon`/`ExpandLessIcon`) alternando a presença do `group.id` no `Set`.
- Cada `TableRow` de grupo é seguida por uma segunda `TableRow` (`colSpan` = todas as colunas) contendo `<Collapse in={expandedGroupIds.has(group.id)}><GroupGrossDebtsPanel groupId={String(group.id)} /></Collapse>` — o componente só monta (e só então dispara o fetch, item 2 acima) quando a linha é expandida pela primeira vez; React não desmonta ao colapsar por padrão a menos que `Collapse` force isso — usar `unmountOnExit` (prop do `Collapse` do MUI, já disponível) para efetivamente desmontar ao colapsar, garantindo que reabrir não reaproveite um estado "informado" obsoleto de uma competência antiga sem querer.
- Por quê essa abordagem e não outra: é o padrão MUI documentado de "collapsible table row" (`TableRow` + `Collapse` + `colSpan`), sem introduzir biblioteca nova; `unmountOnExit` resolve a exigência de "sob demanda" de forma simples, sem gerenciar cache manual de quais grupos já foram buscados.

## N. Ordem de execução

1. **§1 (endpoint backend)** — independente do frontend, testável isoladamente via PHPUnit.
2. **§2 (hook + painel)** — depende só de `PixPaymentDialog` (já existente); pode ser desenvolvido/testado com o endpoint de §1 pronto (mock em teste, integração real depois).
3. **§3 (linha expansível no Dashboard)** — depende de §2 (usa `GroupGrossDebtsPanel`); é o item que efetivamente entrega a feature visível.

Ordem de tasks em `tasks.md`: §1 → §2 → §3 (sequencial, mesmo padrão das features anteriores desta série).
