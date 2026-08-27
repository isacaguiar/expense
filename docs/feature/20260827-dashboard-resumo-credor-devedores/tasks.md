# Tasks — Dashboard: resumo Credor→devedores por grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260827

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-212 | Criar endpoint `GET /groups/{groupId}/expenses/gross-debts` (árvore Credor→devedores) | backend | plan.md §1 | nenhum | Concluída |
| TASK-213 | Criar hook `useGroupGrossDebts` e componente `GroupGrossDebtsPanel` (árvore + Pix + informar pagamento) | frontend | plan.md §2 | nenhum | Pendente |
| TASK-214 | Tornar linhas do Dashboard expansíveis, integrando `GroupGrossDebtsPanel` | frontend | plan.md §3 | nenhum | Pendente |

## Critérios de aceite

- **TASK-212**: `GET /groups/{groupId}/expenses/gross-debts` exige `jwt.auth` e membership do grupo (404 para não-membro, mesmo padrão de `summary()`). Para um grupo com despesas do ciclo vigente com participações não pagas, retorna `{ cycle: {...}, creditors: [{ creditor: {id,name,email}, debtors: [{id,name,amount}] }] }`, valores brutos (soma de parcelas não pagas, sem netting) — não inclui o credor entre os próprios devedores, não inclui devedor cuja parcela já está paga, não inclui credor sem nenhum devedor pendente. `cycles_ago` navega para competências passadas como em `summary()`. Teste PHPUnit novo cobrindo: ciclo sem pendência (lista vazia), um credor com múltiplos devedores, despesa já paga não aparece, `cycles_ago` navegando. `php artisan test` verde.
- **TASK-213**: `useGroupGrossDebts(groupId, cyclesAgo)` busca o endpoint da TASK-212 e expõe `{ data, loading, error }`. `GroupGrossDebtsPanel` renderiza a árvore (credor + devedores indentados), navegação de ciclo própria (setas), estado vazio ("Nenhuma pendência neste ciclo."), botão de Pix por devedor abrindo `PixPaymentDialog` com `targetEmail`/`targetName`/`amount` corretos, e botão "Informar pagamento" que só alterna estado visual local (sem chamar API) e reseta ao trocar de ciclo. Teste novo (`GroupGrossDebtsPanel.test.tsx`) cobre: árvore renderizada a partir do mock do endpoint, clique no Pix abre o dialog com os dados certos, clique em "Informar pagamento" muda o visual sem chamar `axios.post`/`axios.put`, estado vazio. `npx tsc --noEmit` sem erro; `npx vitest run` do arquivo passa.
- **TASK-214**: `Dashboard.tsx` ganha uma coluna de expandir/colapsar por linha de grupo; expandir renderiza `GroupGrossDebtsPanel` daquele grupo (via `Collapse`/`unmountOnExit`), várias linhas podem estar expandidas simultaneamente e cada uma navega seu próprio ciclo sem afetar as outras. Colapsar e reabrir a mesma linha refaz a busca (estado não reaproveitado entre expansões). Teste novo/atualizado em `Dashboard.test.tsx` cobre: expandir uma linha mostra o painel, colapsar remove do DOM, duas linhas expandidas simultaneamente não interferem uma na outra (mock por `groupId`). `npx tsc --noEmit` sem erro; `npx vitest run` (suíte completa do frontend) passa.
