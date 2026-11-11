# Achados informativos da revisão de segurança da feature "quem paga se declara e o credor recusa"

ID: 149
Origem: docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/ (TASK-605, revisão do `security-reviewer`, achados INFO); nenhum bloqueia o PR
Criado em: 2026-11-11
Prioridade: BAIXA
Status: Aberto

## Descrição
Pontos menores, cada um com o arquivo e a sugestão; quem pegar o item decide quais valem uma task própria.

1. **Oráculo de existência de usuário.** `from_user_id` e `to_user_id` usam `exists:ex_users,id` e a validação roda antes do 403 (`SettlementDecisionController.php`, `validatedPair`): um membro enumera quais ids de usuário existem. É o mesmo padrão de `confirmSettlement`. Em `accept` e `refuse` dá para tirar o `exists` de `to_user_id`, já que ele é comparado com `auth()->id()`.
2. **`cycles_ago` sem teto** (mesmo método): é a convenção do repositório, mas um valor enorme pode dar 500 em `BillingCycle`. Opcional: `max:120`.
3. **`pendingCycles` e `/me/overview` tratam `awaiting_acceptance` como confirmado** (`CycleSummaryService.php`, `PersonalOverviewService.php`): usam `confirmedAt !== null`, que só zera no recusado. É só exibição, não afeta o selo (`awaiting_seal` usa o escopo). O credor vê "confirmado" com a URL do comprovante e sem o botão de aceitar nesses painéis. Relacionado ao item 139.
4. **A migration 595 não faz backfill.** Comprovantes de responsável gravados desde a `v1.4.0` e ainda em ciclo não selado passam a `awaiting_acceptance` sem aviso ao credor. É o efeito pretendido (D6) e a janela é pequena, mas deve constar da nota do PR.
5. **`confirmSettlement` não confere `$group->deleted`** (`ExpenseController.php`, `findOrFail`). As quatro rotas novas dão 404 para grupo excluído. Preexistente, fora do diff desta feature.
6. **`GroupMemberResponsibleController@update`** ainda usa `Group::find($groupId)` sem a guarda `ctype_digit` das rotas novas. Cosmético: a checagem de membro continua valendo.
7. **A exclusão física (D7-A) não deixa rastro.** `SettlementConfirmationService::destroy` não registra log. Sugestão: `Log::info('settlement_confirmation_deleted', [...])` só com ids (grupo, devedor, credor, início do ciclo, quem excluiu), sem caminho nem URL do arquivo.
8. **Responsável sobrescrevendo o comprovante aguardando de outro responsável** apaga o arquivo do primeiro sem avisá-lo. A spec permite (R7); vale ao menos um aviso ao remetente anterior.
9. **Docblock desatualizado** em `Notifier.php` (`oncePer`): no Laravel 10.48 o `FileStore::add` é atômico, então a ressalva sobre o driver de arquivo já não vale. Um `cache:clear` no deploy zera a janela, o que só custa um aviso a mais.
10. **O credor que é o remetente aceita o próprio comprovante** (só afeta ele mesmo; o plan §48 já prevê). Registrar como decisão.

## Por que importa
Nenhum é exploração hoje; são endurecimento, consistência entre painéis e rastro de auditoria da exclusão que a Constitution trata como gate.
