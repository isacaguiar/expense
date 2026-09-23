# Specify — Campo Data vazio ao editar despesa

> Feature: fecha o item de backlog 040 — o modo de edição de `ExpenseView` pré-preenche o campo Data com a string ISO-8601 crua vinda da API, que um `<input type="date">` não aceita e descarta, renderizando o campo vazio. Origem: achado ao executar a TASK-278 da feature `detalhe-despesa-tipo-parcela-valores`, que corrigiu o mesmo problema só no modo de visualização.

Versão: 1.0 · Criado em: 20260922

---

## 1. Problema

`GET /api/expenses/{id}` devolve o model cru; o cast `date` do Laravel serializa `date_payment` em ISO-8601 com hora e `Z` (`"2026-08-01T00:00:00.000000Z"`, comentário já registrado em `frontend/src/pages/ExpenseView.tsx:81-90`). Um `<input type="date">` só aceita `YYYY-MM-DD` — recebendo outro formato, o navegador descarta o valor e renderiza o campo vazio, sem disparar `onChange`.

A TASK-278 (`docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/`) já resolveu isso para o **modo de visualização**, introduzindo `parseLocalDate()` (`ExpenseView.tsx:96-100`, corta a string em 10 caracteres antes de montar a data). O modo de **edição** ficou fora daquele escopo e ainda usa o valor cru.

Risco: quem clica em "Editar" vê o campo Data em branco. Salvar sem tocar no campo preserva a data original por acidente (o state ainda tem a string ISO); o risco real é o usuário **preencher o campo vazio com uma data digitada**, que aí sim persiste errada — numa despesa parcelada, a data de cada parcela define a competência.

## 2. Achados confirmados

### 2.1 `startEditing()` não normaliza a data antes de preencher o state

- `frontend/src/pages/ExpenseView.tsx:210`: `setDate(expense.date_payment)` — usa o valor cru da API, sem passar por `parseLocalDate()` nem por um corte equivalente.
- `frontend/src/pages/ExpenseView.tsx:364-371`: o `<TextField type="date" value={date} onChange={...} />` do formulário de edição é o consumidor desse state — é aqui que o valor ISO completo é rejeitado pelo input nativo.
- `frontend/src/pages/ExpenseView.tsx:267`: `handleSave()` envia `date_payment: date` de volta pro backend — se o usuário reescrever o campo vazio, é esse valor (agora incorreto) que persiste.

### 2.2 Nenhuma outra tela tem o mesmo problema (verificado)

- `grep` por `type="date"` no frontend inteiro encontra só dois arquivos: `ExpenseView.tsx` (edição, o bug) e `ExpenseForm.tsx` (criação).
- `ExpenseForm.tsx:36` inicializa o state de data com `new Date().toISOString().slice(0, 10)` — gerado no cliente, nunca lido de um `date_payment` vindo da API. `ExpenseForm.tsx` não tem modo de edição (não recebe despesa existente como prop) — não é afetado.
- Conclusão: o único ponto a corrigir é `ExpenseView.tsx:210`. A pergunta em aberto que o item de backlog 040 levantava ("vale conferir se outras telas... têm o mesmo problema") está respondida — não há outra tela.

### 2.3 Requisito

`startEditing()` normaliza a data com o mesmo corte de 10 caracteres já usado por `parseLocalDate()`, antes de chamar `setDate()` — aceita tanto o formato ISO com hora quanto o formato curto `YYYY-MM-DD` (a fixture de teste hoje usa o formato curto, que mascara o bug).

## 3. Fora de escopo desta feature

- Padronizar a serialização de `date_payment` no backend (ex.: `show()` devolver sempre `YYYY-MM-DD`) — o item de backlog 040 e a correção já aplicada em `parseLocalDate()` tratam a normalização no cliente; mudar o contrato da API é uma decisão maior, fora do que este item pede.
- `ExpenseForm.tsx` — confirmado no §2.2 que não tem o bug; nenhuma mudança necessária lá.
- Qualquer outro campo do modo de edição além de Data.
