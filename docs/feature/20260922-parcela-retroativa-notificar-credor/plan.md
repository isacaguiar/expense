# Plan — Notificar credor quando parcela retroativa nasce paga em seu nome

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. Nova notificação `Notifier::expenseBornPaid()` (specify §2.2)

- **Decisão**: novo método estático em `backend/app/Support/Notifier.php`, mesmo padrão `guard()`/`fanOut()` dos demais (`expenseCreated`, `expensePaid`, `cycleClosed`):

  ```php
  public static function expenseBornPaid(Expense $expense, int $bornPaidQuotasCount): void
  {
      self::guard('expense_born_paid', function () use ($expense, $bornPaidQuotasCount) {
          $recipients = collect([$expense->user_payer_id])
              ->reject(fn ($id) => $id === $expense->user_creator_id);

          if ($recipients->isEmpty()) {
              return;
          }

          $expense->loadMissing('creator', 'group');

          self::fanOut($recipients, 'expense_born_paid', $expense->group_id, [
              'actorName' => $expense->creator?->name,
              'groupId' => $expense->group_id,
              'groupName' => $expense->group?->name,
              'expenseId' => $expense->id,
              'expenseDescription' => $expense->description,
              'quotasCount' => $bornPaidQuotasCount,
          ]);
      });
  }
  ```

  Type novo: `expense_born_paid` — coluna `type` de `ex_notifications` é `string` livre (`database/migrations/2026_09_03_000000_create_ex_notifications_table.php:20`), sem enum/check constraint, então não precisa de migration.

- **Chamada em `ExpenseController::store()`** (`backend/app/Http/Controllers/ExpenseController.php`): o loop que cria as quotas (`:418-443`) ganha um contador `$bornPaidCount`, incrementado a cada quota com `$bornPaid === true`. Logo depois de `Notifier::expenseCreated($expense);` (`:448`, fora da transação, mesma posição/estilo best-effort):

  ```php
  if ($bornPaidCount > 0) {
      Notifier::expenseBornPaid($expense, $bornPaidCount);
  }
  ```

- **Por que essa abordagem e não outra**:
  - O gate "só notifica se `auth()->id() !== user_payer_id`" vira, dentro do `Notifier`, `$expense->user_creator_id !== $expense->user_payer_id` — usa os campos já carregados do `Expense`, sem precisar passar `auth()->id()` como parâmetro extra; é o mesmo padrão de `reject()` que `expenseCreated()`/`cycleClosed()` já usam pra excluir o próprio ator dos destinatários.
  - Evento dedicado (`expense_born_paid`), em vez de religar `Notifier::expensePaid()` para esse caminho ou inflar `expenseCreated()` com um destinatário condicional: preserva a decisão já tomada pela feature de origem (`specify.md §2.5`: parcela retroativa não é "pagamento agora"), e mantém cada método do `Notifier` com uma única responsabilidade — mesmo estilo dos outros 6 métodos existentes.
  - Contador (`quotasCount`) em vez de listar cada parcela: a despesa já tem um `expenseId` no payload — o cliente pode navegar até ela pra ver o detalhe; o texto da notificação só precisa comunicar "quantas".

- **Arquivos afetados**: `backend/app/Support/Notifier.php`, `backend/app/Http/Controllers/ExpenseController.php`.

## 2. Texto da notificação no frontend (specify §2.2)

- **Decisão**: novo `case 'expense_born_paid'` em `frontend/src/components/notificationText.ts`, seguindo o padrão de helpers já existente (`str()`) e pluralização simples inline (sem depender de biblioteca de i18n, que não existe no projeto — backlog 016 ainda aberto):

  ```ts
  case 'expense_born_paid': {
    const count = Number(data.quotasCount) || 0;
    const parcela = count === 1 ? 'parcela' : 'parcelas';
    const paga = count === 1 ? 'já paga' : 'já pagas';
    return `${str(data.actorName)} registrou "${str(data.expenseDescription)}" com ${count} ${parcela} ${paga} em seu nome`;
  }
  ```

- **Por que essa abordagem**: mesmo arquivo, mesmo padrão de `switch` dos outros 6 tipos — não introduz mecanismo novo de tradução. Sem o `case` novo, o `default` (`'Nova notificação'`) já cobriria o tipo com um texto genérico, então tecnicamente não bloqueia nada no backend, mas deixaria a notificação sem informação útil pro credor.
- **Arquivos afetados**: `frontend/src/components/notificationText.ts`.

## 3. Ordem de execução

Sem dependência técnica bloqueante entre os dois itens — o backend pode gravar o `type` novo antes do frontend saber renderizá-lo (cai no `default` genérico até lá). `tasks.md` ordena backend → frontend por clareza (o frontend só faz sentido testar depois que o payload existe de verdade).
