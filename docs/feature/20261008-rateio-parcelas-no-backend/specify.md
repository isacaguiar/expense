# Specify — Rateio de parcelas no backend

> Feature: o backend passa a gerar as quotas de uma despesa (rateio em centavos, resto na última parcela, meses com clamp de fim de mês), tornando `quotas` opcional em `POST /api/expenses` e `PUT /api/expenses/{id}`, e a devolver `value_per_person` por quota em `GET /api/expenses/{id}`. Promovida do item 070 do backlog (`docs/backlog/concluidos/rateio-parcelas-no-backend.md`); é o primeiro da trilha 070 → 071 → 074 do app na Google Play (`docs/sdd/decisions/ADR-010-app-movel-em-flutter.md`).

Versão: 1.0 · Criado em: 20261008

---

## 1. Problema

A Constitution §1 item 1 diz que nenhuma lógica de negócio deve viver no frontend além de validação de UX. O rateio de parcelas é uma regra de dinheiro e hoje vive no cliente web: `frontend/src/utils/installments.ts` divide o total em centavos, joga o resto na última parcela e soma meses com clamp de fim de mês, e o web envia o array `quotas` pronto. O backend só confere o que recebe.

Consequências:

- **Toda nova interface repete a regra.** O app Flutter (ADR-010) teria que reimplementá-la em Dart, e qualquer divergência de arredondamento ou de data entre os dois gera parcelas diferentes para a mesma despesa.
- **O servidor confia nos valores do cliente.** Para À Vista e Fixa nem a soma é conferida (§2.1), então um cliente pode gravar uma quota com valor diferente do `total_value`.
- **A edição deixa quotas desatualizadas.** `update()` só refaz as quotas quando `expense_type` vem no payload; um cliente que mande só `total_value` ou `date_payment` deixa as quotas com o valor e a data antigos. O web evita isso reenviando tudo a cada salvamento.
- **A regra não tem teste unitário.** Hoje só um teste de componente a cobre (`frontend/src/pages/ExpenseForm.test.tsx:102`, "rounding absorbed in the last one").

O contrato novo precisa estar em produção, provado pelo web (item 071), antes de o app depender dele. Por isso esta feature só adiciona: quem manda `quotas` continua funcionando como hoje.

## 2. Achados confirmados e requisitos

### 2.1 Como está hoje (verificado no código)

- **`store()`** (`backend/app/Http/Controllers/ExpenseController.php`):
  - `quotas` é obrigatório: `required|array|min:1`, com `date_expected`, `number` e `value_quota` por item (`:342-345`); `installments` é `required|integer|min:1` (`:336`).
  - Fixa exige `installments` 1 e exatamente 1 quota (`:375-383`). Parcelada exige quantidade de quotas igual a `installments` e soma igual a `total_value`, com tolerância de 0,01 (`:385-396`). **À Vista e Fixa não têm o `value_quota` conferido contra `total_value`.**
  - A trava de parcelada retroativa e o `born_paid` usam `date_expected` das quotas recebidas (`:348-367` e `:420-449`). O `paid` enviado é ignorado: a despesa nasce pendente (`:421-423`).
- **`update()`** (`:138-251`):
  - Aceita `expense_type` (`IN_CASH` ou `IN_INSTALLMENTS`), `installments` (`min:2`) e `quotas` (`:157-162`). Só considera troca de tipo se `expense_type` vier (`:165`).
  - Para parcelar, exige `installments` e `quotas`, com as mesmas conferências de quantidade e soma (`:199-213`). Para À Vista, **já gera** a quota única no servidor, com o total final e a data (`:214-221`).
  - Quotas só são apagadas e recriadas em troca de tipo (`:230-246`), e isso só acontece se nenhuma quota estiver paga (`:181-194`).
- **O web** envia `expense_type`, `installments` e `quotas` em todo salvamento de despesa não-fixa (`frontend/src/pages/ExpenseView.tsx:241-258` e `:274`), e na criação (`ExpenseForm.tsx:91-105`). Portanto, no web, o `update()` recria as quotas a cada salvamento.
- **`show()`** (`:116-123`) carrega `payers` e `quotas` e devolve o `Expense` serializado. Não há valor por pessoa por parcela: o web calcula com `perPersonValue()` (`ExpenseView.tsx:116`, usado em `:496` e `:535`), que é `round(valor / max(pagadores, 1), 2)`, a mesma fórmula do `valuePerPerson` de `computeCycleSummary()` (`ExpenseController.php:1140`).
- **Tipos numéricos:** `value_quota` e `total_value` são `decimal(38,2)` no banco e `decimal:2` no model (`Quota.php`, `Expense.php`).
- **Datas:** o Carbon instalado (2.73) calcula `addMonthsNoOverflow(i)` a partir da data inicial com o mesmo clamp do web. Conferido: 31/01/2026 +1 mês = 28/02/2026, +2 = 31/03/2026, +13 = 28/02/2027; 31/01/2028 +1 = 29/02/2028.
- **Limite de parcelas:** nem o web nem o backend impõem máximo para `installments`. Hoje isso é inofensivo, porque o cliente precisa enviar uma quota por parcela; com geração no servidor, um payload pequeno poderia pedir milhares de linhas.
- **Testes atuais:** 23 usos de `quotas` em 4 arquivos (`ExpenseControllerStoreTest` 15, `ExpenseControllerShowUpdateDestroyTest` 4, `NotifierTriggersTest` 3, `ExpenseControllerCloseTest` 1). Não há teste unitário do rateio.

### 2.2 Requisitos

**R1 — Criação sem `quotas`.** Em `POST /api/expenses`, `quotas` passa a ser opcional. Presente, o comportamento atual não muda. Ausente, o backend gera:
- À Vista e Fixa: 1 quota com `number` 1, `date_expected` = `date_payment` e `value_quota` = `total_value`. Fixa continua exigindo `installments` 1.
- Parcelada (`IN_INSTALLMENTS`): exige `installments` ≥ 2 (422 com mensagem se menor) e divide o total em centavos por N: cada parcela recebe a parte inteira, e o **resto vai para a última**. A parcela `i` (1 a N) tem `number` = `i` e `date_expected` = `date_payment` + (`i` − 1) meses, com clamp de fim de mês calculado sempre a partir de `date_payment` (não de forma acumulada).
- As regras que já dependem das quotas (competência fechada para À Vista e Fixa, parcelada retroativa, `born_paid`, e "despesa nasce pendente") rodam sobre as quotas geradas e dão o mesmo resultado que dariam com as mesmas quotas enviadas.

**R2 — Edição com troca para parcelada.** Em `PUT /api/expenses/{id}`, quando `expense_type` for `IN_INSTALLMENTS` e `quotas` não vier, o backend gera as parcelas com `installments`, o total final (`total_value` do payload ou o atual) e a data inicial (`date_payment` do payload ou a atual). Troca para À Vista continua como hoje.

**R3 — Regeneração na edição (D1, incluída).** Numa despesa não-fixa sem nenhuma quota paga, se o payload trouxer `total_value`, `date_payment` ou `installments` e **não** trouxer `expense_type`, o backend regenera as quotas conforme o tipo atual (À Vista: 1 quota; Parcelada: N quotas), em vez de deixá-las com valor e data antigos. Despesa Fixa continua fora: o `total_value` dela é o modelo das ocorrências futuras.

**R4 — `value_per_person` por quota.** `GET /api/expenses/{id}` passa a devolver, em cada quota, `value_per_person` = `round(value_quota / max(pagadores, 1), 2)`, com a mesma fórmula de `computeCycleSummary()` e sem consulta extra por quota (sem N+1). É campo novo e aditivo; nenhum campo atual muda.

**R5 — Uma regra, um lugar, com teste unitário.** O rateio (centavos, resto na última, meses com clamp) fica numa classe própria do backend, usada por criação e edição, com testes PHPUnit unitários e vetores fixos: 100/3, 10/3, 0,10/3, 1000,01/7, 31/01 +1 mês (clamp), ano bissexto e virada de ano. Os mesmos vetores devem conferir com `frontend/src/utils/installments.ts`, para provar que as duas implementações dão o mesmo resultado enquanto o web não migra; a conferência é feita uma vez e registrada em `implementation.md`.

**R6 — Retrocompatibilidade.** Quem envia `quotas` (o web de hoje, incluindo abas antigas em cache) continua funcionando exatamente como antes. Todos os testes atuais que enviam `quotas` passam sem alteração. Sem migration (Constitution §4.2); mudança de API só aditiva (§4.1).

**R7 — Teto de 120 parcelas na geração (D2).** Nos caminhos em que o servidor gera as parcelas (R1, R2 e R3), `installments` tem máximo de 120, para que um payload pequeno não gere linhas sem limite. Quem envia `quotas` próprias não muda.

### 2.3 Decisões (aprovadas em 2026-10-08)

- **D1 — Regeneração na edição (R3): incluir.** Fecha a armadilha das quotas desatualizadas para qualquer cliente novo (o app), e o web não é afetado, porque já reenvia tipo e quotas.
- **D2 — Teto de parcelas na geração (R7): 120** (10 anos mensais). É folgado para uso real e impede amplificação. O teto vale só quando o servidor gera as parcelas.
- **D3 — Conferir a soma de À Vista e Fixa quando o cliente envia `quotas`: não incluir agora.** Fechar essa lacuna para quem manda `quotas` pode quebrar clientes e fixtures de teste que hoje enviam valores arbitrários, e perde a urgência quando o web parar de mandar `quotas` (item 071). Fica como candidato a backlog depois do 071.

## 3. Fora de escopo desta feature

- **Web usar o contrato novo e remover `utils/installments.ts` e `perPersonValue`:** é o item 071 do backlog (frontend), que depende desta feature em produção.
- **App Flutter, exclusão de conta e versão mínima da API:** itens 074, 072 e 073 do backlog.
- **Qualquer mudança nos campos de resposta já existentes** e nas regras de competência fechada, parcela retroativa e `born_paid`: só passam a rodar sobre quotas geradas.
- **Rateio não-igualitário entre pagadores** (percentual ou valor por pagador): não existe no modelo (`docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/specify.md` §2.5).
- **Despesa Fixa:** a recorrência e a materialização mensal de quotas não mudam.
- **Alterar a regra de arredondamento ou de clamp:** a geração replica exatamente a do web (resto na última parcela; clamp a partir da data inicial).
- **Conferência de soma para À Vista e Fixa com `quotas` enviadas** (D3): candidata a backlog depois do item 071.
