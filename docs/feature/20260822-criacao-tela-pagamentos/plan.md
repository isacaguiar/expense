# Plan — Tela de Pagamentos

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Comprovante de pagamento — coluna nova + storage (specify §2.5)

- Migration aditiva `add_payment_proof_path_to_ex_quotas_table`: coluna `payment_proof_path` (string, nullable) em `ex_quotas`.
- `Quota` model (`backend/app/Models/Quota.php`): adicionar `payment_proof_path` a `$fillable`; accessor `payment_proof_url` (via `Storage::disk('public')->url(...)` quando não nulo), exposto no JSON via `$appends`.
- Disco: `public` (padrão Laravel — `storage/app/public` + `php artisan storage:link`, sem infra nova; trocável depois por S3 via config, sem mudar código de controller).
- Validação do arquivo: `image` (jpeg/png/webp), `max:5120` (5MB).

## 2. `pay()` aceita comprovante opcional; `unpay()` limpa o comprovante (specify §2.1, §2.5)

**Decisão-chave**: o comprovante é **opcional** no contrato da API (`nullable|image|max:5120`), não obrigatório. `ExpenseManager.tsx:110` já chama `POST .../pay` sem corpo (`axios.post(url, null, ...)`) — tornar o campo obrigatório no backend quebraria esse fluxo já em produção, contrariando `specify.md` §3 ("o fluxo de pagar/desfazer já existente em `ExpenseManager.tsx` continua existindo como está"). A obrigatoriedade "quando o usuário confirmar um pagamento é feita a foto" (specify §1) é imposta só do lado do cliente, só na tela nova — botão "Confirmar pagamento" fica desabilitado até uma foto ser anexada (ver item 6). API permissiva, UX restritiva.

- `ExpenseController::pay()`: se `$request->hasFile('comprovante')`, salva via `$request->file('comprovante')->store('comprovantes', 'public')` e inclui `payment_proof_path` no `update()` da quota (junto com `paid`/`paid_at`/`paid_by`, linha 535-539); senão, comportamento idêntico ao atual.
- `ExpenseController::unpay()`: se a quota tinha `payment_proof_path`, apaga o arquivo (`Storage::disk('public')->delete(...)`) e limpa a coluna (`null`) — pagamento desfeito não deve manter comprovante de um estado que não vale mais; um novo `pay()` subsequente anexa uma foto nova, se houver.

## 3. `valuePerPerson` no summary (specify §2.2)

- `ExpenseController::computeCycleSummary()`: no mapeamento de `$expenses` (linhas 649-668), adicionar `'valuePerPerson' => round($entry['value'] / max($entry['expense']->payers->count(), 1), 2)` — mesma fórmula já usada no loop de `$owed`/`$balances` logo abaixo (`$valuePerPerson = $entry['value'] / $participantsCount`, linha 691). Extrair para uma variável local calculada uma vez por entrada e reaproveitada nos dois pontos, em vez de duplicar a fórmula.
- `SummaryExpense` (`frontend/src/hooks/useGroupCycle.ts:15-26`): novo campo `valuePerPerson: number`.

## 4. `paymentProofUrl` no summary, para a lista poder linkar o comprovante (specify §2.2, §2.5)

- `collectCycleEntries()` (`ExpenseController.php:765-853`): cada `entry` já carrega `paid` a partir da `Quota` resolvida (linhas 788, 809, 844) — adicionar `paymentProofUrl` (via `$quota->payment_proof_url ?? null`) do mesmo jeito, nos 3 pontos onde `paid` é montado.
- `computeCycleSummary()`: repassar `paymentProofUrl` de `$entry` para o item de `$expenses` (junto ao `paid` já existente na linha 655).
- Justificativa: sem isso o comprovante seria write-only (guardado mas nunca mais visível) — esvazia o propósito de "prova de pagamento" pedido em `specify.md` §1.
- `SummaryExpense` (frontend): novo campo `paymentProofUrl: string | null`.

## 5. Rota + tela `Payments.tsx` (specify §2.3, §2.6)

- Nova rota `/groups/:id/payments`, dentro de `GroupShellLayout` (`frontend/src/App.tsx:29-36`), ao lado de `/groups/:id/summary`.
- Componente novo `frontend/src/pages/Payments.tsx`, reaproveitando `useGroupCycle(groupId)` (mesmo hook de `GroupSummary.tsx`/`ExpenseManager.tsx` — mesma navegação de competência por `cyclesAgo`). Resolve a decisão pendente de `specify.md` §2.3: a tela abre em `cyclesAgo=0` (competência vigente), igual às outras duas telas — é a única competência em que `pay()`/`unpay()` funcionam (`rejectIfCompetenceClosed` sempre valida a competência que contém "agora", nunca uma passada), então abrir numa competência já fechada mostraria uma tela onde a ação principal está sempre desabilitada.
- Lista por despesa (`summary.expenses`, um item por entrada): descrição, credor (`payerName`), valor total (`value`), valor por pessoa (`valuePerPerson`, item 3), pagadores (`participants` — lista só informativa, specify §2.7/§3, não checkbox por pessoa).

## 6. Fluxo de confirmar pagamento com foto (specify §1, §2.1, §2.5)

- Predicados de habilitação: reaproveitar exatamente a lógica já existente em `ExpenseManager.tsx:284-294` (`cycleIsOpen`, `isCreditor`, `canPay`, `canUnpay`) — extrair para um hook compartilhado (`frontend/src/hooks/usePaymentActions.ts` ou equivalente) usado por `ExpenseManager.tsx` e `Payments.tsx`, em vez de duplicar a mesma regra de negócio (quem pode pagar/desfazer, quando) em dois arquivos.
- Botão "Confirmar pagamento" (visível quando `canPay`) abre um diálogo com `<input type="file" accept="image/*" capture="environment">` (permite câmera no mobile, seleção de arquivo no desktop). O botão de confirmar do diálogo fica desabilitado até um arquivo ser selecionado — é aqui, e só aqui, que a foto vira obrigatória (ver decisão do item 2).
- Ao confirmar: `POST /expenses/{id}/pay` como `multipart/form-data` (`FormData` com o arquivo no campo `comprovante`), depois `useGroupCycle().reload()`.
- "Desfazer pagamento" (quando `canUnpay`): chama `unpay()` sem arquivo, mesmo fluxo simples já existente em `ExpenseManager.tsx:124-143`.

## 7. Entrada sem grupo selecionado + item de menu (specify §2.6)

- Novo componente `frontend/src/pages/PaymentsEntry.tsx`, mesmo padrão de `ExpensesEntry.tsx` (resolve o grupo mais ativo via `mostActiveGroup`, redireciona para `/groups/{id}/payments`), montado em `/payments` dentro de `SimpleShellLayout` (`App.tsx:39-47`).
- `frontend/src/layouts/simpleNavItems.ts:18` e `frontend/src/layouts/group/GroupSidebar.tsx:32`: adicionar `to: '/payments'` e `` to: `/groups/${groupId}/payments` `` respectivamente — hoje o item "Pagamentos" existe nos dois sem `to`.

## N. Ordem de execução

Backend (itens 1-4) antes do frontend (itens 5-7): a tela nova consome os campos novos (`valuePerPerson`, `paymentProofUrl`) e o `pay()` multipart, então não há como validar o frontend sem o backend pronto. Dentro do backend: item 1 (migration/coluna) bloqueia o item 2 (usa a coluna); itens 3 e 4 não dependem um do outro nem de 1/2, mas mexem no mesmo método (`computeCycleSummary`/`collectCycleEntries`) — agrupados numa única task para evitar duas edições concorrentes no mesmo trecho. Dentro do frontend: item 7 (rota + entry + menu) antes de 5/6 só por conveniência de já poder navegar até a tela durante o desenvolvimento — não é bloqueante tecnicamente.
