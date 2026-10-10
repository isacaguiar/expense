# Rateio de parcelas no backend (`quotas` opcional, regeneração na edição, `value_per_person`)

ID: 070
Origem: análise do app na Google Play (conversa de 2026-10-06); `frontend/src/utils/installments.ts` e `backend/app/Http/Controllers/ExpenseController.php`
Criado em: 2026-10-06
Prioridade: ALTA
Status: Promovido para TASK-397

## Descrição
Hoje o cliente web monta o array `quotas` de uma despesa: `frontend/src/utils/installments.ts` divide o total em centavos, joga o resto na última parcela e soma meses com clamp de fim de mês, e `ExpenseForm.tsx:91-105` (criar) e `ExpenseView.tsx:241-258` (editar) o enviam pronto. O backend só confere quantidade e soma das parceladas (`ExpenseController::store()` em `:385-396`, `update()` em `:204-210`); para À Vista e Fixa nem confere `value_quota` contra `total_value`. A regra vem da TASK-049 (2026-08-18), herdada do contrato original, e não há ADR defendendo que o cliente monte as parcelas.

Proposta, aditiva (Constitution §4.1) e sem migration:

- `POST /api/expenses`: `quotas` passa a ser opcional. Presente, comportamento atual. Ausente, o backend gera: À Vista e Fixa com 1 quota (`number` 1, `date_expected` = `date_payment`, `value_quota` = `total_value`); Parcelada (exige `installments` ≥ 2) em centavos ÷ N, resto na última, datas = início + i meses com clamp (equivale a `Carbon::addMonthsNoOverflow(i)`). As checagens existentes (competência fechada, parcelada retroativa, `born_paid`) rodam sobre as quotas geradas.
- `PUT /api/expenses/{id}`: mesma geração quando `expense_type` vem sem `quotas`. Decidir no specify se as quotas são regeneradas sempre que valor, data ou número de parcelas mudarem numa despesa não-fixa sem parcela paga. Hoje `update()` só as refaz se `expense_type` vier (`:230`), e um cliente que mande só o valor deixa as quotas desatualizadas; o web evita isso reenviando tipo, parcelas e quotas a cada salvamento.
- `show()`: `value_per_person` por quota (aditivo), no lugar do `perPersonValue()` do web (`ExpenseView.tsx:116`), que repete o `valuePerPerson` de `computeCycleSummary()`.
- Serviço pequeno (ex.: `App\Support\InstallmentSchedule`, no molde de `BillingCycle`) com PHPUnit unitário e vetores fixos: 100/3, 10/3, 0,10/3, 1000,01/7, 31/01 +1 mês (clamp), ano bissexto, virada de ano. Esses vetores devem passar também na implementação atual do web enquanto ele não migrar (item 071).

Ordem: é o primeiro item da trilha do app na Google Play (**070 → 071 → 074**). Sem dependências; promover primeiro. O web continua funcionando sem mudança, porque segue mandando `quotas`. Os cerca de 23 usos de `quotas` nos testes do backend (`ExpenseControllerStoreTest`, `ShowUpdateDestroy`, `NotifierTriggers`, `Close`) precisam seguir verdes.

## Por que importa
Uma regra de dinheiro em um lugar só, com teste unitário (hoje só há teste de componente), e o servidor deixa de confiar nos valores que o cliente manda. Fecha a armadilha das quotas desatualizadas e é pré-requisito para que o app Flutter (item 074) nasça sem nenhuma regra de parcelas. O contrato novo fica provado em produção pelo web (item 071) antes de o app depender dele.

Tipo sugerido: backend

## Resolução
Concluído em: 2026-10-09
Feature: docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/
Tasks: TASK-397 a TASK-405
PRs: https://github.com/isacaguiar/expense/pull/213

Entregue como descrito: `quotas` opcional em `POST /api/expenses` e em `PUT /api/expenses/{id}` (ao trocar para parcelada), regeneração das quotas na edição quando valor, data ou parcelas mudam (decidida no specify, D1), `value_per_person` por quota no `show()` e o `App\Support\InstallmentSchedule` com testes de vetores fixos, conferido contra `frontend/src/utils/installments.ts` em 5.712 combinações sem diferença. Acrescentou um teto de 120 parcelas quando o servidor gera as quotas e uma guarda para o rateio que passaria do ano 9999 (achado da revisão de segurança, TASK-404), além de a escrita do `update()` rodar numa transação (TASK-405).

Desdobramentos: o item 071 (o web usar o contrato novo) segue aberto e agora pode ser promovido depois do deploy; a revisão deixou os itens 077 a 080 no backlog (competência fechada da nova data no `update()`, corrida entre `pay()` e a edição, limites de entrada do rateio e o `details` do 500 do `store()`).
