# Tasks — Tela de Pagamentos

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Migration aditiva `payment_proof_path` em `ex_quotas` + accessor `payment_proof_url` em `Quota` | backend | plan.md §1 | nenhum (migration aditiva local) | Concluída |
| TASK-002 | `pay()`/`unpay()` aceitam comprovante opcional (multipart) e limpam o arquivo ao desfazer | backend | plan.md §2 | antes do merge | Concluída |
| TASK-003 | `computeCycleSummary()`/`collectCycleEntries()` expõem `valuePerPerson` e `paymentProofUrl` por despesa | backend | plan.md §3, §4 | antes do merge | Concluída |
| TASK-004 | Testes backend de comprovante (`pay` com/sem foto, `unpay` limpa foto, `valuePerPerson`, `paymentProofUrl` no summary) | backend | plan.md §2, §3, §4 | antes do merge | Concluída |
| TASK-005 | Tela `Payments.tsx` (lista por despesa: credor, valor total, valor por pessoa, pagadores) + rota `/groups/:id/payments` | frontend | plan.md §5 | nenhum | Pendente |
| TASK-006 | Hook compartilhado de ações de pagamento (`canPay`/`canUnpay`) + diálogo "Confirmar pagamento" com foto obrigatória (multipart) + "Desfazer pagamento", usados em `Payments.tsx` e `ExpenseManager.tsx` | frontend | plan.md §6 | antes do merge | Pendente |
| TASK-007 | `PaymentsEntry.tsx` (`/payments` sem grupo selecionado) + item de menu "Pagamentos" com `to` nos dois sidebars | frontend | plan.md §7 | nenhum | Pendente |
| TASK-008 | Testes frontend (`Payments.tsx`, diálogo de confirmação exige foto, `PaymentsEntry`, navegação do menu) | frontend | plan.md §5, §6, §7 | antes do merge | Pendente |

## Critérios de aceite

- **TASK-001**: `php artisan migrate` local aplica sem erro; `Quota::find($id)->payment_proof_url` retorna `null` quando `payment_proof_path` é `null`, e uma URL (`Storage::disk('public')->url(...)`) quando preenchido; `Quota` existente antes da migration continua legível com `payment_proof_path = null`.
- **TASK-002**: `POST /expenses/{id}/pay` sem arquivo continua funcionando exatamente como hoje (mesma resposta, `payment_proof_path` permanece `null`) — não quebra o fluxo atual de `ExpenseManager.tsx`; `POST /expenses/{id}/pay` com um arquivo de imagem válido no campo `comprovante` salva o arquivo em `storage/app/public/comprovantes/` e grava o path na quota; arquivo inválido (não-imagem, ou maior que 5MB) devolve 422; `POST /expenses/{id}/unpay` numa quota com comprovante remove o arquivo do disco e limpa `payment_proof_path`.
- **TASK-003**: `GET /groups/{id}/expenses/summary` devolve, para cada item de `expenses[]`, `valuePerPerson` igual a `value / número de participantes` (arredondado a 2 casas) e `paymentProofUrl` (`null` se não paga ou paga sem foto; URL se paga com foto) — conferir para despesa direta, parcela de `IN_INSTALLMENTS` e ocorrência de `FIXED`, os 3 pontos de `collectCycleEntries()`.
- **TASK-004**: `php artisan test` verde cobrindo os cenários acima (pay com/sem foto, unpay limpa foto e arquivo, validação de formato/tamanho, `valuePerPerson`/`paymentProofUrl` nos 3 tipos de despesa) — sem regressão na suíte completa.
- **TASK-005**: navegar para `/groups/{id}/payments` mostra a competência vigente (mesmo cabeçalho/navegação de ciclo de `GroupSummary`) e, para cada despesa, uma linha/card com descrição, "Credor: `payerName`", "Valor Total: R$ `value`", "Valor por pessoa: R$ `valuePerPerson`" e a lista de `participants` como "Pagadores".
- **TASK-006**: para o credor de uma despesa pendente, na competência aberta, aparece o botão "Confirmar pagamento"; clicar abre um diálogo cujo botão de confirmar só habilita depois de um arquivo de imagem ser selecionado; confirmar dispara `POST .../pay` como `multipart/form-data` com o arquivo no campo `comprovante` e recarrega a lista (despesa passa a "Paga"); para despesa já paga pelo credor aparece "Desfazer pagamento", que chama `POST .../unpay` sem arquivo; nenhum botão aparece para quem não é credor, nem em competência fechada; `ExpenseManager.tsx` continua exibindo/funcionando os mesmos botões de hoje após a extração do hook compartilhado (sem regressão).
- **TASK-007**: acessar `/payments` sem grupo selecionado redireciona para `/groups/{grupo mais ativo}/payments` (mesma regra de `ExpensesEntry`); usuário sem nenhum grupo vê a mesma mensagem de vazio já usada nas outras telas "Entry"; clicar em "Pagamentos" no menu (com ou sem grupo selecionado) navega para a rota correta.
- **TASK-008**: `npx vitest run` verde cobrindo os cenários de TASK-005/006/007 (mock da API), sem regressão na suíte completa (incluindo os testes já existentes de pay/unpay em `ExpenseManager.test.tsx`).
