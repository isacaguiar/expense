# Specify — Organização de Despesas e Pagamentos

> Feature: reorganizar as telas de despesas e pagamentos do grupo — despesas ordenadas pela data da ocorrência (mais recente primeiro), aba **Saldo** com o usuário logado em primeiro, **filtros de Despesas** também em Pagamentos e os acertos entre membros separados em **"Valores a pagar"** e **"Valores pagos"**. Pedido novo do usuário (2026-10-26), sem task prévia em `docs/sdd/03-tasks.md` e sem item equivalente no backlog (001–104 conferidos), por isso `/nova-feature`. Cumpre o que o specify de `20261010-filtros-credor-devedores-status-despesas` deixou fora de escopo (§6: "Outras telas (Pagamentos…)"). Plano da rodada: `C:\Users\Isac Aguiar\.claude\plans\pasted-content-id-b857-revise-e-precious-island.md`.

Versão: 1.0 · Criado em: 20261026

**Status: decisões D1–D11 aprovadas em 2026-10-26** (Constitution §5.2) — o usuário aceitou todas as recomendações do §6 e liberou o `plan.md`. Nenhum código foi escrito até aqui.

---

## 1. Problema

Hoje, na competência de um grupo:

- A lista de despesas aparece com **pendentes primeiro** e, dentro de cada bloco, por data crescente, sem desempate. Uma despesa recém-lançada de uma pessoa fica no meio da lista, e quem procura "o que aconteceu por último" precisa percorrer tudo.
- A aba **Saldo** lista os membros na ordem em que o grupo os guarda. O usuário logado não aparece em destaque nem em posição previsível.
- A tela **Pagamentos** mostra todas as despesas sem filtro, apesar de o usuário já ter busca, tipo, credor, devedores e status em Despesas.
- Os **acertos entre membros** (quem paga a quem) ficam todos numa lista só, "Valores a pagar", que mistura o que ainda falta pagar com o que já tem comprovante enviado. O usuário logado não vê primeiro o que ele mesmo precisa pagar.

O pedido original, resumido em sete pontos: (1) ordenar despesas pela data da ocorrência, decrescente, desempate por ID, em cards e tabela; (2) Saldo com o usuário logado primeiro; (3) filtros de Despesas em Pagamentos; (4) filtros só na lista de despesas; (5) abas "Valores a pagar" e "Valores pagos" separadas pela confirmação do acerto; (6) ordem dos acertos com as saídas do usuário primeiro; (7) preservar permissões, Pix, comprovante, navegação de ciclo, estados vazios e responsividade. **Sem mudar cálculos financeiros e sem regravar snapshots.**

## 2. Achados confirmados

Conferidos lendo o código de `dev` (`d9dccb4df4`) em 2026-10-26. Onde o pedido pressupunha algo diferente do código, está dito.

### 2.1 A ordem "pendentes primeiro" mora no backend, não tem desempate e não é a do pedido

`backend/app/Services/CycleSummaryService.php:210-214`, dentro de `compute()`:

```php
// Não pago primeiro (o que falta quitar fica em alerta no topo),
// cronológico dentro de cada bloco. `paid` é bool → `false` < `true`.
->sortBy([['paid', 'asc'], ['date', 'asc']])
```

Empates (mesmo `paid`, mesma `date`) caem na ordem de inserção das entradas (diretas, parcelas, fixas projetadas). O frontend **não reordena** a lista de despesas em lugar nenhum: `ExpenseManager.tsx` (`renderExpenseCards` `:541-585` e `filteredExpenses.map` da tabela `:760`) e `Payments.tsx:242` renderizam na ordem da API. A regra veio de `docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/specify.md` §2.7 ("a ordenação vive no backend; congela junto no snapshot; o front consome a ordem como vem"). **Esta feature substitui parcialmente essa §2.7** (despesas por data decrescente; acertos pela separação em abas — ver R2 e R10). O arquivo antigo é um snapshot arquivado e não é editado.

### 2.2 A "data da ocorrência na competência" já existe: é o campo `date`

Cada despesa do `summary` traz `date` como string `Y-m-d` (`CycleSummaryService.php:170`), calculada na origem por tipo (`entries()`):

| Tipo | Origem de `date` | Linha |
|---|---|---|
| À Vista | `expense->date_payment` | `:64` |
| Parcelada | `quota->date_expected` da parcela que vence na competência | `:89` |
| Fixa | ocorrência projetada: dia de `date_payment` no mês, limitado ao último dia do mês | `:116-131` |

Nenhum campo novo é necessário. `id` é o da **despesa** (`:168`), não o da parcela. `id` e `date` são obrigatórios no tipo `SummaryExpense` (`useGroupCycle.ts:32,34`) desde o endpoint original (`20260818-resumo-grupo-dashboard`, TASK-061) e o snapshot é gravado a partir da mesma saída de `compute()`, então ordenar por eles deve funcionar em ciclo antigo. O plano confirma isso contra uma fixture de snapshot antigo antes de depender disso (R4). `installmentNumber`, usado como terceiro critério na D2, é opcional no tipo (`:58`) e precisa de `?? 0`.

### 2.3 A data não aparece na lista de Despesas nem de Pagamentos

Os cards de Despesas (`ExpenseManager.tsx:541-585`) mostram descrição, status, valor e credor; a tabela (`:748-757`) tem Tipo, Despesa, Valor, Credor, Status e Ações; os cards de Pagamentos (`Payments.tsx:243+`) também não têm data. A data só aparece no detalhe (`ExpenseManager.tsx:852`) e na Home do grupo (`CycleExpenseList.tsx:55`, formato `dd mmm`). Ordenar por uma chave invisível dificulta confiar e testar a ordem — é a **D11**.

### 2.4 Quem consome a lista de despesas e herda a ordem da API

| Consumidor | Onde aparece | Ordem hoje |
|---|---|---|
| `ExpenseManager` (cards e tabela) | `/groups/:id/expenses` | da API |
| `Payments` (cards) | `/groups/:id/payments` | da API |
| `CycleExpenseList` via `CycleDetailPanel` | Home do grupo (`GroupSummary`) | da API |
| `CycleExpenseList` via `ClosingReportPanel` (`:209`) | Relatório de fechamento | da API (snapshot) |
| `MyItemsList` via `GroupPositionCard` | Painel `/painel` (`PersonalOverviewService.php:108` → `itemsOf` `:168-179`) | da API; `MyPanel.tsx:11-13` documenta que a página exibe "na ordem que o servidor mandou" |

Ciclo **selado** devolve `snapshot->expenses` cru (`ExpenseController.php:682`; `CycleSummaryService.php:678` para `viewAt`/relatório). Como um ciclo selado está todo pago, a ordem congelada é efetivamente **data crescente**. Mudar só `compute()` deixaria ciclos selados na ordem antiga e os recém-selados na nova (o snapshot é gravado a partir de `compute()`), inconsistente entre competências. Isto fundamenta a D1.

### 2.5 A aba "Saldo" não está em Pagamentos

Pagamentos não tem aba Saldo. A aba está em `SummarySidePanel.tsx:54-57` (abas **Saldo** e **À pagar**), que renderiza `BalanceCards` na aba Saldo (`:60`). O painel é usado em **Despesas** (`ExpenseManager.tsx:803`) e na **Home do grupo** (`CycleDetailPanel.tsx:38`). Logo, mexer no Saldo afeta as duas telas.

- `BalanceCards.tsx:19-39` faz `balances.map` direto. A ordem de `balances` é a de `$group->members` (`CycleSummaryService.php:222-226`, `:273-280`), sem `orderBy`.
- `SummarySidePanel` **não recebe o usuário logado**. `ExpenseManager.tsx:128` e `Payments.tsx:51` fazem `GET /api/me` para obter o `id`; `GroupSummary`/`CycleDetailPanel` não fazem. `GroupShellLayout.tsx:38` já chama `/api/me`, mas só guarda `name`, `email` e `avatar_url`. "Usuário logado primeiro" exige levar o `id` até o painel (decisão de plano; D5 trata o escopo).
- `balances[].balance` é o **rateio líquido do ciclo**: soma o rateio de todas as despesas, **sem olhar `paid` nem confirmação** (`CycleSummaryService.php:238-271`; regressão em `CycleSummaryServiceTest.php:196`). "Saldo numérico decrescente" é esse número, já arredondado em duas casas (`:275`).

### 2.6 Pagamentos hoje: sem abas, sem filtros, todos os pares na mesma lista

`Payments.tsx` é um grid de duas colunas (`:233` `md:7` despesas; `:335` `md:5` "Valores a pagar"). A coluna da direita é o `PayableSettlementList` com `summary.settlements` inteiro (`:340-351`): um card por par, "X deve pagar R$ Y a Z", na ordem da API (`PayableSettlementList.tsx:61`). Estados vazios: "Nenhuma despesa nesta competência." (`:239`) e "Ninguém deve nada a ninguém neste ciclo." (`PayableSettlementList.tsx:51-57`). Pix e comprovante do devedor só aparecem para `isDebtor && canConfirm` (`:116`); `canConfirm` = ciclo fechado e não selado (`Payments.tsx:344-347`).

### 2.7 Os filtros de Despesas: lógica reutilizável, interface não

`utils/expenseFilters.ts` (`creditorOf`, `debtorsOf`, `buildCreditorOptions`, `buildDebtorOptions`, `filterExpenses`) é puro e só depende do tipo `SummaryExpense`; combina com **E** entre filtros e **OU** dentro de Credor e Devedores, e cai em `participants` por nome quando o snapshot não tem `participantDetails`. Já a interface é **inline** em `ExpenseManager.tsx`: estado (`:135-139`), opções e poda (`:144-153`), `renderPersonFilter` (`:433-453`), barra (`:669-704`) e dois estados vazios (`:722`, `:740`). Não há componente nem hook compartilhado. Para reutilizar em Pagamentos é preciso extrair, não copiar (decisão de plano; a regra é R6).

### 2.8 O critério "acerto confirmado" diverge entre componentes

- `PayableSettlementList.tsx:63`: `const confirmed = Boolean(settlement.confirmedProofUrl);` — **usa a URL do comprovante**, o oposto do que o pedido exige.
- Usam `confirmedAt`: `SettlementList.tsx:56`, `CycleClosingAlert.tsx:32`, `ClosingReportPanel.tsx:50`; no backend, `PersonalOverviewService.php:133` (`confirmed_at !== null`). `CycleSummaryService::summaryIsFullySettled` (`:383-395`) decide pela existência da linha de confirmação do par, que é o mesmo fato por outro caminho.

Hoje os dois critérios coincidem na prática: `proof_path` é `NOT NULL` (migration `2026_08_25_220000:30`) e o `confirm` exige foto, então `confirmedAt` implica `confirmedProofUrl`. Divergem se o arquivo for removido ou a linha for criada à mão. `PayableSettlementList` também é usado pelo Painel (`GroupPositionCard.tsx:216`, alimentado com `confirmedProofUrl` e `confirmedAt` de `to_pay`, `:110-111`), então a correção do critério chega lá (D6).

### 2.9 Os dados de confirmação são ao vivo, inclusive em ciclo selado

`summary()` decora `settlements` com `withConfirmations` nos três caminhos — snapshot selado, fechado não selado e aberto (`ExpenseController.php:716`; `viewAt` em `CycleSummaryService.php:706`). `confirmedAt`, `confirmedProofUrl` e `confirmedNote` vêm de `ex_settlement_confirmations`, nunca do snapshot; o snapshot guarda só `from_user_id`, `to_user_id` e `amount`. Separar por `confirmedAt` portanto **não exige regravar snapshot**. O oposto vale para `paymentProofUrl` das **despesas**: a URL assinada de 30 minutos fica congelada no snapshot e expira (backlog 096) — mais um motivo para nunca separar por URL.

Pontos pré-existentes que esta feature **não corrige**, mas o plano deve respeitar:

- A confirmação casa só por par e `cycle_start` (`CycleSummaryService.php:337`), sem comparar `amount`.
- `cycleHistory` devolve `$snapshot->settlements` cru, sem `confirmed*` (`ExpenseController.php:949-951`); Pagamentos não usa esse endpoint.
- `settlements` do snapshot é coluna `nullable` (migration `2026_08_22_140000:15`) e `withConfirmations(..., array $settlements)` não tem guarda para `null` (`CycleSummaryService.php:328`). Não verifiquei se existe linha assim. O frontend novo trata `settlements` ausente como lista vazia (R12).

### 2.10 A ordem dos acertos no servidor conflita com as abas

`withConfirmations` ordena "não confirmado primeiro, depois valor decrescente" (`CycleSummaryService.php:345-348`), sem desempate por nome ou ID, e a lista ao vivo sai na ordem de iteração (`:291-314`). A ordem pedida depende do usuário logado ("saídas dele primeiro"), que não pode ir para o snapshot nem para uma resposta compartilhada: tem de ser feita na apresentação. O frontend hoje não reordena `balances` nem `settlements` (`ReceivableList.test.tsx:46` fixa isso para o Painel).

### 2.11 Testes que fixam as regras atuais

| Teste | Fixa | Efeito |
|---|---|---|
| `ExpenseControllerSummaryTest.php:922` `test_expenses_list_puts_unpaid_before_paid_then_chronological` | ordem do backend | permanece válido (D1 = A: a API não muda) |
| `ExpenseManager.test.tsx:176` | `getAllByRole('button', {name:'Ver detalhes'})[0]` abre "Aluguel" (a mais antiga) | passa a abrir outra despesa |
| `CycleExpenseList.test.tsx:40` | "keeps the order it received" | só é afetado se a ordenação for feita dentro do componente |
| `PayableSettlementList.test.tsx` | fixtures de "confirmado" sempre trazem `confirmedProofUrl` junto de `confirmedAt` | não protegem o novo critério; faltam casos com só um dos dois |
| `Payments.test.tsx` | sem asserção de ordem nem de abas | casos novos |
| `utils/expenseFilters.test.ts:167` | `filterExpenses` preserva a ordem | continua valendo (R3) |

## 3. Requisitos

### 3.1 Ordem das despesas (pedido 1)

Em Despesas (cards e tabela) e nas demais telas definidas na D3, as despesas aparecem por **data da ocorrência decrescente** (campo `date`, §2.2), com desempate determinístico por `id` (direção na D2). Isso **substitui** "pendentes primeiro" (R2): uma despesa pendente não sobe nem desce por estar pendente. Cards e tabela usam a mesma lista ordenada.

### 3.2 Saldo (pedido 2)

Na aba **Saldo**, o usuário logado aparece primeiro. Os demais, por saldo numérico decrescente, com desempate por nome e por ID (interpretação e variante na D4; exemplos no §5.2).

### 3.3 Filtros em Pagamentos (pedido 3)

Pagamentos ganha busca, tipo, credor, devedores e status, com os mesmos rótulos, as mesmas regras de combinação e o mesmo suporte a snapshot antigo de Despesas. Uma única implementação serve as duas telas (R6).

### 3.4 Escopo dos filtros (pedido 4)

Os filtros afetam **somente a lista de despesas**. Totais, saldos e acertos continuam representando a competência inteira (R7).

### 3.5 Abas dos acertos (pedido 5)

A coluna "Valores a pagar" de Pagamentos vira duas abas: **"Valores a pagar"** (acertos sem confirmação) e **"Valores pagos"** (acertos com confirmação). A separação usa os dados de confirmação do acerto — `confirmedAt` — nunca `expense.paid` nem a existência de URL de comprovante (R8).

### 3.6 Ordem dos acertos (pedido 6)

Em cada aba, primeiro as **saídas do usuário logado**; depois os **demais devedores**, pelo total do devedor naquela aba, decrescente (R10). Como os valores que o usuário tem a **receber** aparecem: D7 e §5.3.

### 3.7 Preservação (pedido 7)

Permissões, Pix, envio e reenvio de comprovante, navegação de ciclo, estados vazios e comportamento responsivo não mudam (R11). Nenhum cálculo financeiro, contrato de API, migration ou snapshot é tocado (R13).

## 4. Regras adotadas

Derivadas do pedido e dos achados. Não dependem de nova decisão.

| # | Regra | Origem |
|---|---|---|
| R1 | A chave da ordem das despesas é o campo `date` do `summary` (§2.2), sem campo novo. | Pedido 1, §2.2 |
| R2 | A nova ordem **substitui** "pendentes primeiro" e a ordenação de acertos de `20260902-pagamento-ciclo-fechado` §2.7. O que a regra antiga protegia ("pagamento aberto em alerta") continua coberto pelo chip Paga/Pendente, pelo filtro Status, pelo `CycleClosingAlert` e pela aba "Valores a pagar". | Pedido 1, §2.1 |
| R3 | Cards e tabela consomem **a mesma lista ordenada**. A ordenação acontece **antes** de filtrar; `filterExpenses` preserva a ordem de entrada. | Pedido 1, §2.7 |
| R4 | Ordenar não altera valor, total, saldo, acerto nem snapshot, e funciona em snapshot antigo (`date` e `id` são obrigatórios no contrato; o plano confirma com uma fixture antiga, §2.2). | "Não mudar cálculos nem regravar snapshots" |
| R5 | Saldo: o usuário logado é identificado por `user_id`; os demais são comparados em **centavos inteiros** (evita `-0,00`), depois por nome (`localeCompare('pt-BR')`) e por `user_id` crescente. | Pedido 2, §2.5 |
| R6 | Filtros em Pagamentos: mesmos cinco critérios; **E** entre filtros, **OU** dentro de Credor e Devedores; mesmo helper `expenseFilters.ts`; fallback por nome em snapshot sem `participantDetails`; poda da seleção ao trocar de competência; sem nova chamada à API; estado local por tela, sem persistência. A interface de filtros é extraída e compartilhada, não duplicada. | Pedido 3; herda R2, R3, R5, R6, D2 e D4 de `20261010-filtros-credor-devedores-status-despesas` |
| R7 | Filtros afetam só a lista de despesas. `summary.balances`, `summary.settlements` e os totais não são filtrados. O diálogo "Editar comprovante" localiza a despesa na lista **completa**, não na filtrada. | Pedido 4; `Payments.tsx:195` |
| R8 | Um acerto é **"pago"** se `confirmedAt` é não nulo; caso contrário é **"a pagar"**. Nunca por `Quota.paid`, nunca por `confirmedProofUrl`. O chip de comprovante só vira link se a URL existir. | Pedido 5, §2.8 |
| R9 | As abas só **repartem** `summary.settlements`: nenhum acerto some ou aparece duas vezes, e a soma dos valores das duas abas é a de `settlements`. | Pedido 5 |
| R10 | Ordem dos acertos, feita na apresentação (ignora a ordem do servidor): **bloco 1** = acertos em que `from_user_id` é o usuário logado, por valor decrescente, depois nome do credor e `to_user_id`; **bloco 2** = demais devedores, por **total do devedor na aba** decrescente (soma em centavos de todos os acertos dele naquela aba), depois nome e `from_user_id`; dentro de cada devedor, valor decrescente, nome do credor, `to_user_id`. | Pedido 6, §2.10 |
| R11 | Preservados: botões de Pix/enviar/reenviar só para `isDebtor && canConfirm`; chip de comprovante e nota; textos "Disponível após o fechamento do ciclo." e "Ciclo encerrado."; `DespesasThemeScope`; grid `xs:12 / md:7 / md:5`; navegação de ciclo; estados vazios; `aria-label` dos grupos de filtro; 375 px sem scroll horizontal. | Pedido 7, §2.6 |
| R12 | `settlements` ausente ou `null` (snapshot antigo) é tratado como lista vazia, sem erro. | §2.9 |
| R13 | Sem mudança de contrato de API (Constitution §4.1), migration, cálculo, arredondamento ou snapshot; nenhuma chamada nova. | "Não mudar cálculos nem regravar snapshots" |
| R14 | O usuário logado ausente ou ainda não carregado nunca causa erro: a ordem cai nas regras dos demais e se ajusta quando o `id` chega. | Robustez, §2.5 |

## 5. Exemplos de validação

### 5.1 Ordem das despesas

Competência com quatro despesas (valores omitidos):

| id | Descrição | Tipo | `date` | Status |
|---|---|---|---|---|
| 31 | Mercado | À Vista | 2026-10-03 | Pendente |
| 28 | Internet | Fixa | 2026-10-03 | Paga |
| 25 | Sofá 3/6 | Parcelada | 2026-09-20 | Pendente |
| 30 | Luz | À Vista | 2026-09-12 | Paga |

- **Hoje** (pendentes primeiro, data crescente): Sofá, Mercado, Luz, Internet.
- **Proposto** (data decrescente, empate por `id` decrescente): **Mercado, Internet**, Sofá, Luz. Mercado e Internet têm a mesma data; 31 vem antes de 28.
- Com `id` crescente (D2, opção B): Internet, Mercado, Sofá, Luz.

### 5.2 Saldo

Saldos (somam zero, como no rateio): Ana +90,00 · Bruno +90,00 · Carla −30,00 · Diego 0,00 · Eva −45,00 · Fábio −105,00.

| Logada | Proposta (D4 = A, numérica decrescente) | Variante (D4 = B) |
|---|---|---|
| **Carla** (saldo negativo) | **Carla**, Ana +90, Bruno +90, Diego 0, Eva −45, Fábio −105 | **Carla**, Ana +90, Bruno +90, Diego 0, Fábio −105, Eva −45 |
| **Ana** (saldo positivo) | **Ana**, Bruno +90, Diego 0, Carla −30, Eva −45, Fábio −105 | **Ana**, Bruno +90, Diego 0, Fábio −105, Eva −45, Carla −30 |

Para validar a interpretação: na proposta, entre os negativos vem primeiro **quem deve menos** (Eva −45 antes de Fábio −105), porque −45 é numericamente maior que −105. A variante inverte só o trecho negativo, para o maior devedor aparecer logo depois de zero. Ana e Bruno empatam em +90,00: o desempate é o nome (Ana, Bruno). Um saldo `-0,00` conta como zero.

### 5.3 Acertos

Logada: **Carla**. Valores ilustrativos, não derivam da tabela do §5.2.

| De → Para | Valor | `confirmedAt` | Aba |
|---|---|---|---|
| Carla → Ana | 60,00 | — | a pagar |
| Diego → Ana | 90,00 | — | a pagar |
| Diego → Carla | 40,00 | — | a pagar (**a receber** para Carla) |
| Eva → Bruno | 70,00 | — | a pagar |
| Carla → Bruno | 25,00 | preenchido | pagos |
| Eva → Ana | 30,00 | preenchido | pagos |
| Eva → Carla | 20,00 | preenchido | pagos (**a receber** para Carla) |
| Bruno → Diego | 10,00 | preenchido | pagos |

**"Valores a pagar"**, na ordem proposta (D7 = A):

1. Carla → Ana 60,00 *(saída da logada)*
2. Diego → Ana 90,00 *(Diego, total 130,00 na aba)*
3. Diego → Carla 40,00 — **"deve pagar a você"** *(mesmo grupo de Diego, valor menor)*
4. Eva → Bruno 70,00 *(Eva, total 70,00)*

**"Valores pagos"**:

1. Carla → Bruno 25,00 *(saída da logada)*
2. Eva → Ana 30,00 *(Eva, total 50,00 na aba)*
3. Eva → Carla 20,00 — **"pagou a você"**
4. Bruno → Diego 10,00 *(Bruno, total 10,00)*

Observe que o total de Eva é 70,00 na aba "a pagar" e 50,00 em "pagos": o critério é o total **da respectiva aba**. Na variante B da D7, "Diego → Carla 40,00" sairia do grupo de Diego e ficaria logo depois das saídas de Carla.

## 6. Decisões fechadas

O usuário aprovou em 2026-10-26 ("Aprovo as recomendações D1 a D11, pode seguir para o plan.md"). A **decisão aceita** em cada linha é a da coluna "Recomendação": D1 A · D2 A · D3 B · D4 A · D5 A · D6 A · D7 A · D8 A · D9 A · D10 A · D11 A. Valem como regras para o `plan.md`. As demais opções ficam registradas pelo porquê da escolha.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde ordenar | **A.** Utilitário puro no frontend, aplicado a despesas, saldos e acertos · **B.** Backend em `compute()` · **C.** Backend na leitura (`summary`, `viewAt`, `overview`) | **A** | B grava a nova ordem só nos snapshots futuros e deixa os já selados em ordem antiga (§2.4). C mexe em ao menos quatro caminhos de leitura e, de qualquer forma, Saldo e acertos dependem do usuário logado, que só o cliente conhece. A mantém contrato e snapshots intactos (R13) e é testável como função pura. O custo é o app Flutter precisar repetir a regra quando existir. |
| D2 | Direção do desempate por `id` | **A.** `id` decrescente · **B.** `id` crescente | **A** | Coerente com "mais recente primeiro": o lançamento mais novo na frente. O plano deve acrescentar um terceiro critério (`installmentNumber` decrescente) e testar o caso de duas parcelas da mesma despesa na mesma competência, que repetiria `id` (§2.2). |
| D3 | Telas que recebem a nova ordem de despesas | **A.** Despesas e Pagamentos · **B.** A + Home do grupo e Relatório de fechamento (`CycleExpenseList`) · **C.** B + Painel `/painel` (exige backend) | **B** | Home e Despesas mostram hoje a mesma ordem; mudar só uma criaria divergência visível. O Painel tem contrato próprio ("na ordem que o servidor mandou") e mudá-lo exigiria backend; fica como follow-up. |
| D4 | Ordem do Saldo além do usuário logado | **A.** Numérica decrescente: positivos, zero, negativos (o menos negativo antes) · **B.** A receber (decrescente), zero, depois a pagar do **maior devedor** ao menor | **A** | É a sua proposta. O §5.2 mostra o efeito que talvez surpreenda (Eva −45 antes de Fábio −105); se preferir o maior devedor logo após os credores, a opção é B. |
| D5 | "Logado primeiro" também na Home do grupo | **A.** Sim, nas duas telas que usam `SummarySidePanel` · **B.** Só em Despesas | **A** | O painel e o `BalanceCards` são o mesmo componente; divergir entre Despesas e Home seria estranho. Exige levar o `id` do usuário a `GroupSummary` (hoje não o tem, §2.5): reaproveitar a chamada que `GroupShellLayout` já faz a `/api/me` ou acrescentar uma. |
| D6 | Onde ficam as abas de acertos | **A.** Só em Pagamentos · **B.** Também no Painel (`GroupPositionCard`) | **A** | No Painel, `to_pay` já são só as saídas do próprio usuário e `to_receive` tem lista própria. O critério `confirmedAt` do `PayableSettlementList` compartilhado (§2.8) chega ao Painel de qualquer forma, sem mudança visível hoje. |
| D7 | Como aparecem os valores a receber | **A.** Ficam entre os "demais devedores", ordenados pelo total do devedor, com rótulo de texto e ícone ("deve pagar a você" / "pagou a você"), sem botões · **B.** Bloco próprio "A receber", logo depois das saídas da logada · **C.** No fim da aba | **A** | É a leitura literal do pedido ("depois os demais devedores") e não cria bloco novo. O rótulo precisa ser texto, não só cor (acessibilidade). |
| D8 | Apresentação dentro da aba | **A.** Lista plana de cards, só reordenada · **B.** Cabeçalho por devedor com subtotal | **A** | É organização, não redesenho (o redesign visual ficou fora de escopo desde `20260902`). Se o subtotal fizer falta, entra depois. |
| D9 | Abas: contador e estado | **A.** Mostrar contador ("Valores a pagar (4)"), abrir em "Valores a pagar" e manter a aba escolhida ao navegar entre ciclos · **B.** Sem contador; sempre reabrir em "Valores a pagar" | **A** | O contador evita o usuário não saber que há itens na outra aba. Manter a aba é o comportamento esperado de quem está comparando ciclos. |
| D10 | Aba "À pagar" do painel lateral (`SettlementList`, em Despesas e na Home) | **A.** Não alterar nesta feature · **B.** Aplicar a mesma ordem de acertos | **A** | Não foi pedido, e é outro componente (`SettlementList`). Registrar como follow-up se a diferença incomodar. |
| D11 | Mostrar a data nas listas de Despesas e Pagamentos | **A.** Sim: data (`dd mmm`, como em `CycleExpenseList`) em cada card e coluna "Data" na tabela · **B.** Não, a ordem fica implícita | **A** | Sem a data visível o usuário não consegue confirmar a ordem, e o teste de aceite fica indireto (§2.3). Custa uma linha no card e uma coluna na tabela, sem mudar cor, fonte ou tema. |

## 7. Critérios de aceite

Cada item deve ser verificável por teste automatizado ou por conferência manual descrita no `implementation.md`. A numeração será herdada pelas tasks.

### Ordem das despesas

1. Em Despesas, os cards (viewport `< sm`) e a tabela mostram as despesas por `date` decrescente. No exemplo do §5.1 a ordem é Mercado, Internet, Sofá, Luz.
2. Duas despesas com a mesma `date` aparecem na ordem definida na D2, igual em recarregamentos; uma despesa pendente não sobe nem desce por estar pendente.
3. A ordem vale com qualquer combinação de filtros e em competência selada (cuja ordem congelada no snapshot é crescente).
4. Pagamentos, Home do grupo e Relatório de fechamento usam a mesma ordem, conforme a D3.
5. Cada card e a tabela de Despesas, e cada card de Pagamentos, mostram a data da despesa (D11).
6. Nenhum valor, total, saldo ou acerto muda; nenhuma chamada à API é acrescentada; nenhum snapshot é regravado. Conferência: respostas de `summary` idênticas antes e depois (D1 = A).

### Saldo

7. Na aba Saldo, em Despesas e na Home do grupo (D5), o usuário logado é o primeiro card, qualquer que seja o saldo dele.
8. Os demais seguem a D4, com empate por nome (`pt-BR`) e depois `user_id`; os exemplos do §5.2 passam para as duas colunas de "Logada".
9. Saldo `0,00` e `-0,00` ordenam como zero.
10. Usuário logado ausente, ou `id` ainda não carregado, não gera erro; a ordem se ajusta quando o `id` chega.
11. Textos "a receber" / "a pagar" e cores dos cards não mudam.

### Filtros em Pagamentos

12. Pagamentos mostra busca, tipo, credor, devedores e status, com os mesmos rótulos e `aria-label` ("Tipo", "Status") de Despesas.
13. A combinação de filtros reproduz os casos de `ExpenseManager.test.tsx` (E entre filtros; OU em Credor e Devedores; credor conta como devedor).
14. Em competência selada sem `participantDetails`, o filtro de Devedores funciona por nome.
15. Mudar um filtro não dispara nenhuma chamada nova (`focus-cycle`, `summary` ou `members`).
16. Ao trocar de competência, credores e devedores selecionados que não existem na nova são podados.
17. Há dois estados vazios distintos: competência sem despesas e filtro sem resultado.
18. Com qualquer filtro ativo, as abas de acertos, os saldos e os totais mostram exatamente os mesmos dados que sem filtro.
19. "Editar comprovante" abre e salva com um filtro ativo; "Desfazer pagamento" com o filtro "Pagas" tira a despesa da lista filtrada sem erro (R7).

### Abas de acertos

20. Pagamentos tem as abas "Valores a pagar" e "Valores pagos" no lugar da coluna única; abre em "Valores a pagar".
21. Um acerto com `confirmedAt` não nulo está em "Valores pagos"; com `confirmedAt` nulo, em "Valores a pagar" — independente de `Quota.paid`, de `confirmedProofUrl` e de `confirmedNote`.
22. `confirmedAt` preenchido com `confirmedProofUrl` nulo aparece em "pagos", sem link quebrado. `confirmedAt` nulo com `confirmedProofUrl` preenchido fica em "a pagar".
23. Despesa `paid = true` com acerto sem confirmação gera acerto em "a pagar"; despesa pendente com acerto confirmado fica em "pagos".
24. A soma das contagens e dos valores das duas abas é a de `summary.settlements`.
25. Em ciclo selado as abas funcionam sem regravar o snapshot.
26. Cada aba tem estado vazio próprio; sem nenhum acerto no ciclo mantém-se "Ninguém deve nada a ninguém neste ciclo.".
27. `settlements` ausente ou `null` não gera erro e mostra os estados vazios.
28. Depois de enviar o comprovante com sucesso, o acerto passa a aparecer em "Valores pagos" e sai de "Valores a pagar".

### Ordem dos acertos

29. Em cada aba, as saídas da logada vêm primeiro (valor decrescente, depois credor e id).
30. Os demais devedores seguem o total do devedor na aba, decrescente (Eva: 70,00 em "a pagar", 50,00 em "pagos"); empate por nome e id; dentro do devedor, valor decrescente, credor e id.
31. Os exemplos do §5.3 produzem exatamente as duas listas descritas.
32. Os valores a receber aparecem conforme a D7, com rótulo de texto e sem botões Pix ou de comprovante.
33. Logada sem acertos de saída: a aba começa pelos demais, sem erro.

### Preservação

34. "Pagar com Pix" e "Enviar/Reenviar comprovante" aparecem só para o devedor com `canConfirm`; os pedidos de Pix e de envio (URL, corpo, `cycles_ago`) são os mesmos de hoje.
35. Chip de comprovante, nota do acerto, "Disponível após o fechamento do ciclo." e "Ciclo encerrado." mantêm-se.
36. Navegação de ciclo, `CycleClosingAlert` e carregamento/erro de Pagamentos não mudam; a aba escolhida persiste ao trocar de ciclo (D9).
37. Em 375 px não há scroll horizontal e abas e filtros são utilizáveis; a partir de `md` Pagamentos mantém duas colunas.
38. As abas têm `role="tablist"`/`tab`, operam por teclado (MUI `Tabs`) e nenhum estado é transmitido só por cor.
39. O Painel `/painel` não muda de aparência nem de ordem (§2.8, D6).

### Qualidade

40. Utilitários de ordem (despesas, saldo, acertos) são funções puras com testes unitários; Pagamentos tem testes de filtros e de abas; os testes do §2.11 afetados são atualizados.
41. `npx tsc --noEmit` e `npm test` do frontend passam. O backend não muda (D1 = A): conferir que `git diff --stat -- backend/` está vazio.

## 8. Impacto na Home e nos componentes compartilhados

"Home" = Home do grupo (`GroupSummary`) e, no seu entorno, Dashboard e Painel. Efeito de cada mudança:

| Componente | Usado por | Muda? | Decisão |
|---|---|---|---|
| `SummarySidePanel` / `BalanceCards` | Despesas, **Home do grupo** | Sim: ordem do Saldo e novo `currentUserId` | D4, D5 |
| `CycleExpenseList` | **Home do grupo**, Relatório de fechamento | Sim (D3 = B): ordem das despesas; o componente continua só renderizando, a ordenação é feita por quem o chama. A data já é exibida | D3 |
| `SettlementList` (aba "À pagar") | Despesas, Home do grupo, Relatório | Não | D10 |
| `PayableSettlementList` | Pagamentos, Painel (`GroupPositionCard`) | Sim: critério `confirmedAt`, ordem e abas via componente novo em Pagamentos | D6, D7 |
| `MyItemsList` / `ReceivableList` | Painel | Não | D3 (opção C descartada) |
| `GroupGrossDebtsPanel` | Dashboard | Não (usa `gross-debts`, não `settlements`) | — |
| `useGroupCycle`, `usePaymentActions`, `PaymentProofDialog`, `PixPaymentDialog` | Despesas, Pagamentos, Home, Painel | Não | R11 |
| `ExpenseManager` | `/groups/:id/expenses` | Sim: ordem, data (D11), barra de filtros extraída | D1, D11 |
| `Payments` | `/groups/:id/payments` | Sim: filtros, abas, ordem | — |

## 9. Fora de escopo desta feature

- **Mudar cálculo, arredondamento, `balances` ou `settlements`**; regravar ou migrar snapshots; qualquer migration ou mudança de contrato de API (R13).
- **Ordem do Painel `/painel`** (`MyItemsList`, `ReceivableList`, `to_pay`): contrato próprio do servidor.
- **Aba "À pagar" do painel lateral** (`SettlementList`) — D10.
- **Filtro e paginação no servidor** — backlog 026 continua aberto.
- **Pagamento individual por participante** (backlog 075) e **quitação antecipada** (076).
- **App Flutter** (`app/`): quando existir, repete a ordem do cliente (D1).
- **Redesenho visual**: nenhuma cor, fonte, `elevation`, `borderRadius` ou token de `theme/brandColors.ts` muda.
- **Os pontos pré-existentes do §2.9** (confirmação casada só por par sem `amount`, `cycleHistory` sem `confirmed*`, ausência de guarda para `settlements = NULL`, link do comprovante que expira em ciclo selado — backlog 096). São candidatos a backlog; **não foram criados nesta rodada**.
- **Eventos de analytics** (`docs/analytics/README.md`): nenhum evento novo.
