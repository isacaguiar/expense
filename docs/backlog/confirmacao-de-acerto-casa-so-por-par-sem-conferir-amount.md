# A confirmação de acerto casa só por par e ciclo, sem conferir o valor confirmado

ID: 106
Origem: docs/feature/concluidas/202610/20261026-organizacao-despesas-pagamentos/specify.md §2.9 (ponto pré-existente, fora do escopo daquela feature)
Criado em: 2026-10-27
Prioridade: MEDIA
Status: Aberto

## Descrição
`CycleSummaryService::withConfirmations` (`backend/app/Services/CycleSummaryService.php:328`) decora cada acerto com a confirmação que tenha o mesmo `from_user_id`, `to_user_id` e `cycle_start` (`keyBy` em `:333`, `get` em `:337`). `summaryIsFullySettled` faz o mesmo por par (`$confirmedPairs`). Nenhum dos dois compara o valor: a confirmação grava `amount` (`ExpenseController::confirmSettlement`, `:1396-1398`), mas esse campo nunca é lido de volta.

Cenário a verificar (**não reproduzido**): o devedor confirma R$ 100 com comprovante; depois, ainda no mesmo ciclo, uma despesa é editada ou removida, ou um pagamento é desfeito, e o acerto do mesmo par passa a ser R$ 60 ou R$ 140. O acerto continua aparecendo como "confirmado" (e como "Valores pagos" em Pagamentos), com o valor novo, e conta como quitado para a selagem do ciclo, embora o comprovante seja de outro valor. Editar e remover despesa e desfazer pagamento (`unpay`) são operações existentes, e o item 102 trata de um efeito colateral das três (não selam o ciclo que ficou quitado), então alterar a despesa depois de a confirmação existir é possível; o que não verifiquei é se algum bloqueio de ciclo fechado impede isso em cada caso.

Correção provável: tratar a confirmação como válida só se `abs(confirmation.amount - settlement.amount) < 0.01` (em centavos), em `withConfirmations` e em `summaryIsFullySettled`. Falta decidir o que mostrar quando o valor mudou (por exemplo, voltar a "a pagar" e manter o comprovante antigo visível como histórico) e se isso gera notificação.

## Por que importa
Dado incorreto numa tela de cobrança: um comprovante de valor antigo fecha um acerto de valor diferente, e o ciclo pode ser selado como quitado com diferença real. O risco só existe se a despesa mudar depois da confirmação, por isso a prioridade MEDIA; vale decidir antes de o app Flutter consumir esses campos.

Tipo sugerido: backend (com teste de regressão no `CycleSummaryServiceTest` e no teste de selagem)
