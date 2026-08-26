# Tasks — Grid de Pagamentos com Pix (QR Code + Copia e Cola)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260825

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Criar `PixPaymentDialog` (QR Code + copia-e-cola via `/pix/generate`) | frontend | plan.md §3 | nenhum | Concluída |
| TASK-002 | Criar `PayableSettlementList` (settlements clicáveis) | frontend | plan.md §2 | nenhum | Concluída |
| TASK-003 | Buscar membros com email/pix e montar o grid final em `Payments.tsx` | frontend | plan.md §1, §4 | nenhum | Concluída (não commitada) |
| TASK-004 | Migration + model `SettlementConfirmation` | backend | plan.md §5 | nenhum | Concluída (não commitada) |
| TASK-005 | Endpoint `POST /groups/{id}/settlements/confirm` + expor confirmação em `settlements` | backend | plan.md §5 | nenhum | Concluída (não commitada) |
| TASK-006 | Botões de comprovante do devedor em `PayableSettlementList`/`Payments.tsx` | frontend | plan.md §6 | nenhum | Concluída (não commitada) |
| TASK-007 | Corrigir comprovante ausente em `ExpenseManager.tsx`/`ExpenseView.tsx` | frontend | plan.md §7 | nenhum | Concluída (não commitada) |

## Critérios de aceite

- **TASK-001**: `frontend/src/components/PixPaymentDialog.tsx` existe; ao abrir com `targetEmail`/`amount` válidos, chama `GET /pix/generate?email=...&valor=...` e mostra QR + copia-e-cola com botão de copiar; erro da API mostra mensagem sem quebrar; teste unitário cobrindo sucesso e erro.
- **TASK-002**: `frontend/src/components/PayableSettlementList.tsx` existe, renderiza a partir de `settlements`/`balances` (mesmo formato de `SettlementList.tsx`), cada card clicável chama `onSelect(settlement)`; `SettlementList.tsx` não é alterado (continua igual nas outras 2 telas que o usam).
- **TASK-003**: `Payments.tsx` busca `GET /groups/{groupId}/members`, monta o grid (região A = despesas, igual a hoje; região B = `PayableSettlementList`), resolve `pix`/`email` do credor ao clicar num settlement — abre `PixPaymentDialog` se tiver `pix`, senão mostra aviso; usa `DespesasThemeScope`; suíte `Payments.test.tsx` com os 4 testes existentes ainda verde (mock de `/members` adicionado) + testes novos cobrindo clique com/sem Pix cadastrado; `npx tsc --noEmit` sem erro.
- **TASK-004**: migration cria `ex_settlement_confirmations` (aditiva); `SettlementConfirmation` model com `proof_url` (accessor); `php artisan migrate` local sem erro.
- **TASK-005**: `POST /groups/{id}/settlements/confirm` valida `to_user_id`+`comprovante` (imagem obrigatória), rejeita se não houver settlement real correspondente (422), grava via `updateOrCreate`; `summary()`/`close()`/`reopen()` passam a expor `confirmedProofUrl`/`confirmedAt` em cada settlement; testes PHPUnit cobrindo: confirmação com sucesso, rejeição sem settlement real, reenvio substitui o comprovante anterior, não-membro do grupo recebe 404 (mesmo padrão de `authorizeGroupMembership` das outras rotas do controller — o critério original dizia "401", ajustado na execução), confirmação bloqueada em ciclo fechado manualmente (422, `rejectIfCompetenceClosed`); `php artisan test` sem regressão na suíte completa.
- **TASK-006**: `PayableSettlementList` mostra "Pagar com Pix" + "Enviar comprovante" só para quem é o devedor daquele settlement (`currentUserId === from_user_id`); mostra status "Comprovante enviado"/link pra quem não é; `Payments.tsx` chama o endpoint novo (multipart); `npx tsc --noEmit` sem erro; testes novos em `Payments.test.tsx` cobrindo o fluxo de envio.
- **TASK-007**: `ExpenseManager.tsx` (listagem + modal de detalhe) e `ExpenseView.tsx` mostram "Ver comprovante" quando a despesa está paga e tem `paymentProofUrl`; testes existentes continuam verdes + teste novo cobrindo o link aparecendo.
