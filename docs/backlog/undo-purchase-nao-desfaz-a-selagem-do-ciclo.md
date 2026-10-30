# O "desfazer pagamento da compra" não chama `unsealIfBroken`: uma leitura concorrente pode deixar o ciclo selado com parcelas pendentes

ID: 114
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/implementation.md (TASK-532, achado N6 da revisão de segurança); `backend/app/Http/Controllers/ExpenseController.php` (`undoPurchase()` e `unpay()`), `backend/app/Services/ExpensePaymentService.php` (`undoPurchase()`, `windowOpen()`)
Criado em: 2026-10-29
Prioridade: BAIXA
Status: Aberto

## Descrição
`ExpensePaymentService::undoPurchase()` confere dentro da transação, com as quotas travadas (`lockForUpdate`), que nenhuma parcela paga está em ciclo encerrado (inclui o ciclo selado) e então limpa as parcelas. Mas o `ExpenseController::undoPurchase()` não chama `unsealIfBroken` depois, ao contrário do `unpay()`. Um `summary()` que rode **entre a checagem e o commit** lê as parcelas ainda pagas, vê o ciclo quitado e grava o snapshot selado; depois do commit as parcelas estão pendentes e o snapshot segue selado e velho, e o ciclo aparece como quitado com dívida em aberto (o mesmo sintoma do item 113).

A janela da corrida é estreita, e por isso a prioridade é baixa. Proposta: depois do commit, chamar `unsealIfBroken` para cada ciclo das quotas limpas (a operação é idempotente: sem snapshot selado, não faz nada).

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A chamada é a mesma que o `unpay()` faz, uma vez por ciclo afetado, fora da transação. |
| R2 | Sem snapshot selado a operação não faz nada, então não muda o resultado dos casos normais. |
| R3 | Sem mudança de contrato da rota nem da resposta. |

## Decisões em aberto
Nenhuma: a correção não depende de decisão de produto.

## Por que importa
Um ciclo "quitado" com parcelas pendentes some da lista de pendências e fecha com o valor errado. É raro (precisa de leitura concorrente na janela do commit), mas o custo de fechar a lacuna é uma chamada que já existe.

Tipo sugerido: backend
