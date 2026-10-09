# Plan — Rateio de parcelas no backend

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261008

---

## 1. Classe `InstallmentSchedule` (R5; teto de R7)

- **Onde:** novo `backend/app/Support/InstallmentSchedule.php`, no molde de `App\Support\BillingCycle`: classe de regra pura, métodos estáticos, docblock em português. Não existe pasta `app/Services` no projeto; `Support` é onde as regras puras já moram (`BillingCycle`, `ProofStorage`), e a Constitution §1 item 3 pede a regra fora do controller sem impor o nome da camada.
- **API:**
  ```php
  public const MAX_INSTALLMENTS = 120; // D2

  /** @return list<array{number: int, date_expected: string, value_quota: float}> */
  public static function build(float|int|string $totalValue, int $installments, string $startDate): array
  ```
- **Algoritmo** (réplica exata de `frontend/src/utils/installments.ts`):
  1. `$totalCents = (int) round(round((float) $totalValue, 2) * 100)`. O primeiro `round(…, 2)` trata entradas com mais de 2 casas como o banco já faz (`decimal(38,2)`) e como `update()` já faz em `round((float) …, 2)` (`ExpenseController.php:197`); para entradas de até 2 casas, que é o que a UI produz, equivale ao `Math.round(total * 100)` do web.
  2. `base = intdiv($totalCents, $installments)`; `resto = $totalCents - base * $installments`; a parcela `i` recebe `base`, e a última recebe `base + resto`. `value_quota` = centavos ÷ 100.
  3. `date_expected` da parcela `i` = `Carbon::parse($startDate)->addMonthsNoOverflow($i - 1)->toDateString()`, **sempre a partir da data inicial** (não acumulado), que é o clamp do web. Conferido com o Carbon 2.73 instalado: 31/01/2026 +1 = 28/02, +2 = 31/03, +13 = 28/02/2027; 31/01/2028 +1 = 29/02.
- **À Vista e Fixa** usam o mesmo caminho com `$installments = 1`: a única parcela recebe o total inteiro e a data inicial. Um único código, sem caso especial.
- `$installments < 1` lança `InvalidArgumentException`: é erro de programação, já que os controllers validam o intervalo antes de chamar. O teto (`MAX_INSTALLMENTS`) é só a constante que os controllers usam nas regras de validação; a classe não o impõe, para o rateio continuar testável com qualquer N.
- **Por que estático e não injetado:** não há dependência (tempo, banco, config) a substituir em teste, e `BillingCycle` já é assim.
- **Testes:** novo `backend/tests/Unit/InstallmentScheduleTest.php`, com `PHPUnit\Framework\TestCase` puro (sem Laravel nem banco), como `BillingCycleTest`. Vetores fixos: 100/3, 10/3, 0,10/3, 1000,01/7, 1/3 (resto 1 centavo), total 0, N = 1, 31/01 +1 (clamp), 30/01 e 29/02 em ano bissexto, 31/03 e 31/05 (meses de 30 dias), virada de ano, N = 120.
- **Paridade com o web** (R5): o Node 25 executa TypeScript direto, então um script **descartável** (fora do repositório) importa o `frontend/src/utils/installments.ts` real, imprime os mesmos vetores em JSON, e um script PHP imprime os do `InstallmentSchedule`; os dois JSON são comparados. O resultado é registrado em `implementation.md`; nada disso entra no commit.

## 2. `store()` sem `quotas` (R1 e R7)

- **Onde:** `ExpenseController::store()` (`:322-469`).
- **Validação:**
  - `quotas` passa de `required|array|min:1` para `sometimes|array|min:1` (`:342`). As regras dos itens (`quotas.*.date_expected` etc., `:343-345`) ficam como estão e só se aplicam quando `quotas` vem. `quotas: null` continua inválido (422): `sometimes` + `array` não aceita nulo, igual ao `required` de hoje.
  - Teto de R7 como **regra de campo**, não como `{error}` manual: `installments` ganha `Rule::when(! $request->has('quotas') && $request->expense_type === 'IN_INSTALLMENTS', ['between:2,'.InstallmentSchedule::MAX_INSTALLMENTS])`. Teto e mínimo viram um erro padrão de validação do campo (`errors.installments`), no mesmo formato de qualquer outro campo inválido do endpoint. Com `quotas` presente nada muda (continua só `min:1`).
- **Geração:** logo depois da validação (`:346`) e antes da trava de competência e da trava de parcelada retroativa (`:348`), uma variável local `$quotas`:
  `$request->has('quotas') ? $request->input('quotas') : InstallmentSchedule::build($request->total_value, $installmentsToBuild, $request->date_payment)`, em que `$installmentsToBuild` é `1` para À Vista e Fixa e `$request->installments` para Parcelada.
- **Os cinco usos de `$request->quotas` passam a usar `$quotas`:** trava de parcelada retroativa (`:355`), contagem da Fixa (`:380`), contagem e soma da Parcelada (`:386` e `:390`) e o loop de criação (`:420`). Com isso as regras que dependem das quotas (competência fechada, retroativa, `born_paid`, "nasce pendente") rodam sobre as quotas geradas exatamente como rodam sobre as enviadas, sem duplicar nenhuma.
- **Por que não mudar mais nada:** À Vista/Fixa continuam sem conferência de soma quando o cliente envia `quotas` (D3, fora de escopo); o `installments` guardado para À Vista segue sendo o que o cliente mandou, como hoje.
- **Por que `$request->has()` e não `filled()`:** `has` separa "ausente" de "presente e inválido". Um `quotas: []` precisa continuar dando 422 (regra `min:1`), não cair silenciosamente na geração.

## 3. `update()` com troca para parcelada (R2 e R7)

- **Onde:** `ExpenseController::update()` (`:138-251`), ramo `changingType` (`:196-222`).
- **Quando `expense_type` = `IN_INSTALLMENTS`:**
  - `quotas` presente: validações de quantidade e soma como hoje (`:204-211`).
  - `quotas` ausente: exige só `installments` (a mensagem de 422 de `:201` passa a citar apenas `installments` quando `quotas` também faltar) e gera com `InstallmentSchedule::build($finalTotalValue, $data['installments'], $data['date_payment'] ?? $expense->date_payment->toDateString())`, em que `$finalTotalValue` já é calculado em `:197`.
  - Teto: a regra de `installments` em `:158` (`sometimes|required|integer|min:2`) ganha `Rule::when(! $request->has('quotas'), ['max:'.InstallmentSchedule::MAX_INSTALLMENTS])`, igual ao `store()`.
- **Troca para À Vista** já gera a quota única no servidor (`:214-221`) e **não muda**. Mantida como está de propósito: trocar o literal por `build(…, 1, …)` não traria ganho e mexeria em código que funciona.
- O bloco de apagar e recriar quotas (`:230-246`) segue igual, alimentado por `$newQuotas`.

## 4. Regeneração na edição (R3, D1)

- **Onde:** `update()`, na mesma etapa de `:230-246`, que passa a rodar quando `$changingType || $regenerate`.
- **Condição `$regenerate`** (todas juntas):
  1. não é troca de tipo (`! $changingType`);
  2. a despesa não é Fixa (`$expense->expense_type !== 'FIXED'`);
  3. nenhuma quota está paga (`$anyQuotaPaid`, já calculado em `:181`; despesa parcelada com parcela paga já é recusada por inteiro em `:187-189`);
  4. algum entre `total_value`, `date_payment` e (só para Parcelada) `installments` veio no payload **e difere do valor guardado**, comparados normalizados (`round((float) …, 2)`, `toDateString()` e inteiro). Comparar evita apagar e recriar quotas à toa quando um cliente reenvia os mesmos valores.
- **Geração:** À Vista = 1 quota (total final e data); Parcelada = `InstallmentSchedule::build(total final, installments final, data final)`, com total, parcelas e data finais = payload ou o que já está gravado. O teto de 120 vale para as parcelas finais (`installments` do payload ou o gravado); uma despesa legada com mais de 120 parcelas que tenha valor ou data editados recebe 422 de campo, e editar só a descrição não dispara a regeneração.
- **Bloqueios de hoje seguem valendo:** `rejectIfCycleClosed` no topo (`:143`), recusa de alterar valor de despesa paga (`:191`) e de parcelada com parcela paga (`:187`).
- **O web não é afetado:** ele sempre manda `expense_type`, então continua entrando por `$changingType`.
- **O `update()` continua devolvendo o mesmo corpo** (`$fresh` com `payers` e `quotas`, `:248-251`), sem `value_per_person` (R4 é só do `show()`).
- **Por que comparar em vez de regenerar sempre que o campo vier:** os IDs das quotas mudam a cada recriação, e a recriação já é a única forma de trocar o rateio; fazê-la só quando algo mudou mantém o efeito colateral mínimo.

## 5. `show()` com `value_per_person` (R4)

- **Onde:** `ExpenseController::show()` (`:116-123`) e um novo método privado `appendValuePerPerson(Expense $expense)` logo abaixo de `hydrateQuotaExpense()` (`:131`).
- **Como:** com `payers` e `quotas` já carregados por `show()`, `$divisor = max($expense->payers->count(), 1)` e, para cada quota, `setAttribute('value_per_person', round((float) $quota->value_quota / $divisor, 2))`. É a mesma fórmula do `valuePerPerson` de `computeCycleSummary()` (`:1140`), e o valor sai como número (float), como aquele campo. Zero consultas extras: usa as relações que já estão em memória.
- **Por que atributo local e não acessor em `Quota` com `$appends`:** um acessor apareceria em **toda** serialização de `Quota` (respostas de `pay()` e `unpay()`, snapshots de fechamento) e dependeria de `expense->payers` estar carregado, forçando consulta extra (N+1) ou valor nulo nesses outros pontos. O atributo só no `show()` é aditivo, local e sem efeito colateral.
- **Contrato:** campo novo e opcional por quota; nenhum campo existente muda (Constitution §4.1).
- **Divergência conhecida com o web (verificada em 2026-10-08):** o `perPersonValue()` do web arredonda com `Math.round((v / n) * 100) / 100` sobre ponto flutuante, e o `round()` do PHP trata o meio centavo como o decimal exige. Varrendo todos os valores de R$ 0,00 a R$ 2.000,00 (em centavos) com 1 a 8 pagadores (1.600.008 combinações), os dois diferem em **11.687 casos (0,73%)**, sempre por **1 centavo** e sempre com o PHP acima (ex.: R$ 0,29 ÷ 2 → web 0,14, PHP 0,15). O PHP é o resultado correto, e é o mesmo que `computeCycleSummary()` já mostra no resumo, então o `value_per_person` da API segue o PHP. Consequência para o item 071: ao trocar `perPersonValue()` pelo valor da API, alguns valores por pessoa na tela de detalhe mudam 1 centavo e passam a bater com o resumo. Nenhuma ação nesta feature além de registrar; o rateio das parcelas em si usa centavos inteiros e não sofre disso (a conferência de paridade da TASK-398 cobre isso).

## 6. Retrocompatibilidade e estratégia de testes (R6)

- **Compatibilidade:** quem manda `quotas` segue o caminho antigo, sem mudança. Os 23 usos de `quotas` nos testes atuais (`ExpenseControllerStoreTest` 15, `ShowUpdateDestroyTest` 4, `NotifierTriggersTest` 3, `CloseTest` 1) **não são alterados** e precisam passar. Se algum falhar, é regressão a corrigir no código, não no teste.
- **TDD** (skill `expense-backend`): em cada task, teste vermelho primeiro, depois o mínimo de código, e verificação por mutação nas regras novas (resto na última parcela, clamp, teto, condição de regeneração).
- **Testes novos de feature**, no estilo dos arquivos existentes (`DatabaseTransactions`, relógio fixo, `payloadFor`/`createExpense`; um helper sem `quotas` para os casos de geração):
  - `ExpenseControllerStoreTest`: gerar À Vista, Fixa e Parcelada produz as **mesmas linhas** que o equivalente com `quotas` enviadas; resto na última parcela e clamp de fim de mês; `installments` 1 e 121 sem `quotas` → 422 de campo; 120 é aceito; parcelada retroativa gerada marca `born_paid` como no teste de `:425`; À Vista sem `quotas` em competência fechada → 422; `quotas: []` e `quotas: null` continuam 422.
  - `ExpenseControllerShowUpdateDestroyTest`: trocar para parcelada sem `quotas`; regenerar ao mudar valor, data ou nº de parcelas; **não** regenerar com os mesmos valores nem só com descrição; despesa Fixa e despesa com parcela paga intocadas; `installments` 121 → 422; troca de tipo com `quotas` enviadas segue igual.
  - `show`: `value_per_person` com 1, 2 e 3 pagadores, divisor mínimo 1 sem pagador, e uma parcela com resto; número de consultas ao banco **não cresce** com o número de quotas.
- **Revisão:** o agent `security-reviewer` depois de alterar o `ExpenseController` (rota que expõe dado financeiro), confirmando que a autorização não mudou e que o teto de parcelas barra a amplificação; e o `pr-readiness-checker` antes do PR.
- **Banco de teste:** a suíte PHPUnit roda contra um MySQL **descartável**, nunca no MySQL compartilhado de outro projeto na porta 3306 da máquina.

## 7. Itens conscientemente não tocados

- `frontend/src/utils/installments.ts` e o comentário da linha 4 ("quem monta as quotas é sempre o client") ficam como estão: o web só passa a usar o contrato novo no item 071, e o arquivo sai junto com ele.
- O `ExpenseController` não é quebrado em classes menores nesta feature; só ganha `InstallmentSchedule` e um método privado.
- Sem migration, sem rota nova, sem mudança de middleware.

## 8. Ordem de execução

Há dependência técnica: o **item 1** (`InstallmentSchedule`) é pré-requisito dos itens 2, 3 e 4. O **item 5** (`show`) é independente de todos os outros. Ordem proposta, do que sustenta o resto ao que é mais isolado:

1. `InstallmentSchedule` + testes unitários + conferência de paridade com o web (item 1);
2. `store()` sem `quotas` e teto (item 2), o caminho central de criação;
3. `update()` com troca para parcelada (item 3);
4. Regeneração na edição (item 4), que depende do item 3;
5. `show()` com `value_per_person` (item 5);
6. Revisão final: `security-reviewer`, `pr-readiness-checker` e a suíte completa do backend (item 6).
