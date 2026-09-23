# Modo de edição da página da despesa abre com o campo Data vazio

ID: 040
Origem: docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/implementation.md (achado ao executar a TASK-278)
Criado em: 2026-09-12
Prioridade: MEDIA
Status: Promovido para TASK-347

## Descrição

`startEditing()` faz `setDate(expense.date_payment)` (`frontend/src/pages/ExpenseView.tsx:192`) e esse valor alimenta um `<TextField type="date">` (`:348`). Só que `date_payment` chega da API em **ISO-8601 com hora e Z** — verificado no próprio model, sem banco: `(new Expense(['date_payment' => '2026-08-01']))->toJson()` devolve `{"date_payment":"2026-08-01T00:00:00.000000Z"}`, porque `show()` serializa o model cru e o cast `date` do Laravel usa `toJSON()`.

Um `input[type=date]` aceita exclusivamente `YYYY-MM-DD`; recebendo qualquer outro formato, o navegador descarta o valor e o campo renderiza **vazio**.

Mesma causa raiz do item 013 (data crua da API consumida sem normalizar), com consequência diferente: não é data deslocada em um dia, é campo sem valor nenhum. A TASK-278 desta feature corrigiu só a **exibição** no modo de visualização (`specify.md` §3.6, via `parseLocalDate()`); o modo de edição ficou explicitamente fora de escopo (`specify.md` §4).

Nenhum teste cobre isso hoje: a fixture de `ExpenseView.test.tsx` usa `date_payment: '2026-08-01'` (formato curto, que o input aceita), então a suíte passa verde com o bug em pé.

## Por que importa

Quem clica em "Editar" vê o campo Data em branco e não tem como recuperar a data original sem sair da edição.

O dano hoje é de confiança, não de dado: o state continua com a string ISO que veio da API (o navegador rejeitar o valor não dispara `onChange`), e salvar sem tocar no campo envia `date_payment` com essa mesma string (`:249`), que a validação do backend aceita (`'date'` valida ISO-8601) — a data é preservada por acaso. O risco real é o usuário **preencher o campo vazio com o que acha que era**: aí a data errada entra no banco, e numa despesa parcelada a data é o que define a competência de cada parcela.

Remédio: normalizar na entrada do state (`setDate(expense.date_payment.slice(0, 10))`) — uma linha, no mesmo arquivo que já tem `parseLocalDate()` desde a TASK-278 —, com um caso de teste usando a fixture em ISO. Enquanto isso não for feito, vale conferir se outras telas que pré-preenchem `input[type=date]` com dado vindo direto de um model (ex.: `ExpenseForm`) têm o mesmo problema.

Tipo sugerido: frontend

## Resolução
Concluído em: 2026-09-22
Feature: docs/feature/concluidas/202609/20260922-expense-view-edicao-data-vazio/
Tasks: TASK-347, TASK-348
PRs: https://github.com/isacaguiar/expense/pull/194

Remédio aplicado exatamente como descrito: `setDate(expense.date_payment.slice(0, 10))`
em `startEditing()` (`ExpenseView.tsx`). TASK-348 cobriu o caso com fixture em
ISO-8601 completo e provou a regressão via `git stash` antes do fix.
