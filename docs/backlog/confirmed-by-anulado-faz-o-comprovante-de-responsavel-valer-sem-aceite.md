# Apagar a conta de quem enviou o comprovante zera `confirmed_by` e o comprovante de responsável passa a valer sem o aceite do credor

ID: 146
Origem: docs/feature/concluidas/202611/20261110-quem-paga-se-declara-e-credor-recusa/ (TASK-605, revisão do `security-reviewer`, achado 3); `backend/database/migrations/2026_11_09_000001_add_confirmed_by_to_ex_settlement_confirmations_table.php` (`nullOnDelete`), `backend/app/Models/SettlementConfirmation.php` (`isThirdParty`, `scopeCountsForSettlement`)
Criado em: 2026-11-11
Prioridade: BAIXA (hoje; sobe para ALTA quando existir exclusão de conta)
Status: Aberto

## Descrição
O estado do comprovante é **inferido** de uma chave estrangeira anulável: `isThirdParty()` e o escopo `countsForSettlement` tratam `confirmed_by` nulo como "enviado pelo próprio devedor". A chave tem `nullOnDelete`. Se a conta de quem enviou (um responsável) for apagada, a linha `awaiting_acceptance` vira `sent`: passa a contar para o selo sem o aceite do credor, e o credor deixa de poder recusar (`isThirdParty()` dá falso, `refuse` responde 422).

**Hoje não há como acontecer pela API:** nenhum código apaga `ex_users` (conferido na revisão). A combinação já existe no banco; o teste `test_confirmed_by_defaults_to_null_and_becomes_null_when_the_user_is_deleted` confirma o `nullOnDelete`.

## Por que importa
A Google Play exige exclusão de conta dentro do app (item de backlog do app móvel). No dia em que ela existir, apagar a conta de um responsável faria um comprovante não aceito valer e selar o ciclo.

Correção sugerida (decidir junto da feature de exclusão de conta): antes de apagar o usuário, recusar ou excluir as linhas `awaiting_acceptance` que ele enviou; ou gravar a exigência de aceite numa coluna própria no envio (por exemplo `requires_acceptance`), em vez de inferi-la de uma chave anulável. Vale já um teste de caracterização: remetente apagado com linha `awaiting` não pode contar.
