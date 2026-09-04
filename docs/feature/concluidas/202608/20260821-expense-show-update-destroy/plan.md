# Plan — Expense show/update/destroy

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. `show` (specify §2.1)

- Novo método `ExpenseController::show($id)`:
  - `Expense::where('deleted', false)->findOrFail($id)` — inclui o filtro `deleted` diretamente na query, para que uma despesa soft-deleted resulte em 404 pelo próprio `findOrFail`, sem passo extra.
  - `Group::findOrFail($expense->group_id)` seguido de `$this->authorizeGroupMembership($group)` — mesmo padrão de 3 linhas já usado em `indexByGroup`/`store`/`stopRecurrence` no mesmo arquivo.
  - Carregar `payers` e `quotas` via `->load(['payers', 'quotas'])` antes de retornar (dados que a tela de edição do mockup precisa).
  - Retorno: `response()->json($expense)` (200) — serialização direta do model, mesmo estilo de `GroupController::show`.

## 2. `update` (specify §2.2)

- Novo método `ExpenseController::update(Request $request, $id)`:
  - Mesmo carregamento + `authorizeGroupMembership` de §1 (buscar despesa não deletada, senão 404), **mais** `authorizeExpenseOwner($expense)` (novo helper, ver §4): `abort_unless(auth()->id() === $expense->user_creator_id || auth()->id() === $expense->user_payer_id, 403)`.
  - Validação com `sometimes|required` para permitir PATCH parcial, reaproveitando os tipos de regra já usados em `store` (`backend/app/Http/Controllers/ExpenseController.php:87-102`), restrita aos campos editáveis definidos no specify:
    ```php
    $data = $request->validate([
        'description' => 'sometimes|required|string|max:255',
        'date_payment' => 'sometimes|required|date',
        'total_value' => 'sometimes|required|numeric|min:0',
        'user_payer_id' => ['sometimes', 'required', Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id)],
        'payers' => 'sometimes|required|array|min:1',
        'payers.*' => Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id),
    ]);
    ```
  - `$expense->update(Arr::except($data, ['payers']))`, e se `payers` veio no payload: `$expense->payers()->sync($data['payers'])` (substitui a lista, diferente de `syncWithoutDetaching` do `store` — em edição o usuário está redefinindo quem participa, não só adicionando).
  - Não expõe `expense_type`, `installments` nem `quotas` na validação — de acordo com o "fora de escopo" do specify, então não há como o cliente alterá-los por essa rota.
  - Retorno: `response()->json($expense->fresh(['payers', 'quotas']))` (200).

## 3. `destroy` (specify §2.3)

- Novo método `ExpenseController::destroy($id)`:
  - Mesmo carregamento + `authorizeGroupMembership` + `authorizeExpenseOwner` de §2.
  - `$expense->update(['deleted' => true])` — soft delete, mesmo padrão de `GroupController::destroy`.
  - Retorno: `response()->json(['message' => 'Despesa marcada como deletada.'])` (200) — mesmo formato de mensagem de `GroupController::destroy`.

## 4. Extração de helpers comuns

- Os 3 métodos repetem "buscar despesa não deletada + checar membership do grupo". Extrair um método privado `private function findExpenseForMember($id): Expense` no próprio `ExpenseController` que faz as duas coisas e retorna a `Expense` — usado pelos 3 métodos.
- `update`/`destroy` além disso chamam `private function authorizeExpenseOwner(Expense $expense): void` (criador ou pagador, senão 403) — helper separado porque `show` não usa essa segunda checagem (specify §2.1: qualquer membro pode ver).
- Ambos ficam no próprio `ExpenseController`, consistente com a regra de controllers finos do `00-constitution.md` §2.3, sem introduzir uma camada de Service para uma extração deste tamanho.

## 5. Testes

- Novo arquivo `backend/tests/Feature/ExpenseControllerShowUpdateDestroyTest.php`, seguindo o padrão de `ExpenseControllerStoreTest.php` (`DatabaseTransactions`, helper `tokenFor`, `withToken(...)->json(...)`), cobrindo por método:
  - `show`: membro do grupo (qualquer um) consegue; não-membro recebe 404; despesa `deleted=true` recebe 404.
  - `update`/`destroy`: não-membro recebe 404; membro que não é criador nem pagador recebe 403; criador consegue; pagador consegue (caso `user_creator_id !== user_payer_id`).
  - `destroy`: confirmar que o registro continua existindo no banco com `deleted=true` (nunca `assertDatabaseMissing`, já que não é hard delete).

## 6. Ordem de execução

Sem dependência técnica forte entre `show`, `update` e `destroy` — os 3 são independentes uma vez que o helper §4 existe. Ordem sugerida em `tasks.md`: helper + `show` primeiro (menor risco, só leitura), depois `update`, depois `destroy` (maior risco por ser destrutivo mesmo sendo soft delete) — cada um em sua própria task para manter o PR pequeno e revisável, mas todas dentro da mesma branch de feature conforme `ADR-003-fluxo-branch-por-feature.md`.
