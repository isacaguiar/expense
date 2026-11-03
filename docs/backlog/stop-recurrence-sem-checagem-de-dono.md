# `POST /api/expenses/{id}/stop-recurrence` deixa qualquer membro encerrar a despesa fixa de outra pessoa

ID: 126
Origem: TASK-556 (revisão de segurança) da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/`; `backend/app/Http/Controllers/ExpenseController.php`, `stopRecurrence()` (`:851-884`)
Criado em: 2026-10-31
Prioridade: MEDIA
Status: Aberto

## Descrição
- `stopRecurrence` valida `year` e `month`, faz `Expense::findOrFail($expenseId)` (sem filtrar `deleted`) e só chama `authorizeGroupMembership($group)`. Não passa por `findExpenseForMember` nem por `authorizeExpenseOwner`, ao contrário de `update` e `destroy`, que exigem ser membro e depois criador ou credor da despesa.
- Qualquer membro do grupo grava `fixed_recurrence_ends_at` na despesa fixa de outro, e ela deixa de gerar quotas dali em diante. A rota também aceita despesa já excluída logicamente.
- Achado pelo `security-reviewer` e **conferido na leitura do código**; não foi reproduzido por requisição. Pré-existente.

## Por que importa
Perda de cobrança futura por ação de um membro que não é dono da despesa. O item 086 dá a esta rota um papel novo: a D3-B bloqueia a saída de quem participa de fixa ativa, e hoje o caminho mais curto para destravar é qualquer membro encerrar a fixa, em vez de o dono decidir.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem pode encerrar a recorrência | A) só criador ou credor, como `update` e `destroy`<br>B) qualquer membro (hoje) | **A** | Encerrar a cobrança é decisão de quem a criou. O participante bloqueado pela D3-B passa a pedir ao dono, o que já é o caso das parcelas abertas. |
| D2 | Despesa excluída | A) 404, como `findExpenseForMember`<br>B) aceitar | **A** | Atualizar uma despesa que não existe mais não tem efeito útil. |

Tipo sugerido: bugfix pelo BFF (`/novo-bug`, com Triagem); a correção é usar `findExpenseForMember` e `authorizeExpenseOwner` e ajustar os testes de `stopRecurrence`.
