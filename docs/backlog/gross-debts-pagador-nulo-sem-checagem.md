# `grossDebts` usa o pagador da despesa sem checar nulo

ID: 087
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §9 (achado tangencial) e tasks.md (TASK-425); `backend/app/Http/Controllers/ExpenseController.php` (`grossDebts()`, linhas 934-935 em 2026-10-22)
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
`ExpenseController::grossDebts()` faz `$creditor = $expense->payer;` e logo em seguida `$expense->payers->reject(fn ($debtor) => $debtor->id === $creditor->id)` e `$creditors[$creditor->id]`, sem checar se `$creditor` é nulo. O `CycleSummaryService`, que calcula o mesmo ciclo, é defensivo: `$entry['expense']->payer->name ?? null` e `->payer->avatar_url ?? null` (linhas 179 e 183).

**Hoje não é alcançável.** `ex_expenses.user_payer_id` é `unsignedBigInteger` obrigatório, com chave estrangeira para `ex_users` sem `onDelete` (restringe), e o model `User` não usa `SoftDeletes`. Não há caminho para o pagador sumir. O defeito é latente: vira um `500` ("Attempt to read property id on null") em `GET /groups/{id}/gross-debts` no dia em que um usuário puder deixar de existir com as despesas preservadas.

Esse dia é a **exclusão de conta** (item 072), cujo specify ainda tem de decidir entre excluir, anonimizar ou transferir as despesas em que a pessoa participou. Se a decisão for remover o usuário ou desvincular `user_payer_id`, este ponto quebra o painel de dívidas brutas de todo o grupo.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Despesa sem pagador resolvido é ignorada em `grossDebts` (não há credor a quem atribuir a dívida), em vez de derrubar a resposta. Mesmo critério defensivo do `CycleSummaryService`. |
| R2 | Teste de regressão que cria a despesa com `user_payer_id` apontando para um usuário removido (ou força `payer` nulo) e espera `200` sem essa despesa. Mutação: remover a checagem derruba o teste. |
| R3 | Resolver **junto do 072**, ou antes dele ir para produção. Não vale uma feature própria. |

## Por que importa
Sem custo hoje, e barato de fechar agora. Se o 072 chegar antes, o primeiro usuário excluído que for credor de uma despesa viva deixa o painel de dívidas do grupo inteiro com `500`.

Relacionado: 072 (exclusão de conta).

Tipo sugerido: backend
