# Tasks — Grid de Pagamentos com Pix (QR Code + Copia e Cola)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260825

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Criar `PixPaymentDialog` (QR Code + copia-e-cola via `/pix/generate`) | frontend | plan.md §3 | nenhum | Concluída |
| TASK-002 | Criar `PayableSettlementList` (settlements clicáveis) | frontend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Buscar membros com email/pix e montar o grid final em `Payments.tsx` | frontend | plan.md §1, §4 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**: `frontend/src/components/PixPaymentDialog.tsx` existe; ao abrir com `targetEmail`/`amount` válidos, chama `GET /pix/generate?email=...&valor=...` e mostra QR + copia-e-cola com botão de copiar; erro da API mostra mensagem sem quebrar; teste unitário cobrindo sucesso e erro.
- **TASK-002**: `frontend/src/components/PayableSettlementList.tsx` existe, renderiza a partir de `settlements`/`balances` (mesmo formato de `SettlementList.tsx`), cada card clicável chama `onSelect(settlement)`; `SettlementList.tsx` não é alterado (continua igual nas outras 2 telas que o usam).
- **TASK-003**: `Payments.tsx` busca `GET /groups/{groupId}/members`, monta o grid (região A = despesas, igual a hoje; região B = `PayableSettlementList`), resolve `pix`/`email` do credor ao clicar num settlement — abre `PixPaymentDialog` se tiver `pix`, senão mostra aviso; usa `DespesasThemeScope`; suíte `Payments.test.tsx` com os 4 testes existentes ainda verde (mock de `/members` adicionado) + testes novos cobrindo clique com/sem Pix cadastrado; `npx tsc --noEmit` sem erro.
