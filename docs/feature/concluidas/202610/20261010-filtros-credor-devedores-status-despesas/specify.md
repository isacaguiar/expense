# Specify — Filtros de Credor, Devedores e Status nas Despesas

> Feature: a tela de despesas do grupo ganha três filtros novos — **Credor**, **Devedores** e **Status** — ao lado da busca e do filtro de tipo que já existem. Pedido novo do usuário nesta conversa, sem task prévia em `docs/sdd/03-tasks.md`; planejado em modo `/plan` — plano aprovado em `C:\Users\Isac Aguiar\.claude\plans\na-tela-de-despesas-buzzing-beaver.md`.

Versão: 1.0 · Criado em: 20261010

---

## 1. Problema

Na tela de despesas (`frontend/src/pages/ExpenseManager.tsx`, rota `/groups/:id/expenses`) o usuário só consegue afunilar a lista por texto da descrição e por tipo (Todas/Fixas/Variáveis). Num grupo com várias pessoas e várias despesas na competência, não dá para responder:

- "O que o João pagou este mês?" (**Credor**);
- "O que a Maria deve, ou de que despesas ela participa?" (**Devedores**);
- "O que ainda está pendente?" (**Status**).

O pedido do usuário foi literalmente: *"Na tela de despesas adicione os filtros: Credor, Devedores, Status"*.

## 2. Achados confirmados

### 2.1 Todo dado necessário já chega no `summary`

`GET /groups/{groupId}/expenses/summary` → `ExpenseController::computeCycleSummary()` já devolve, por despesa, tudo que os três filtros precisam (`backend/app/Http/Controllers/ExpenseController.php:1278-1308`) e o tipo `SummaryExpense` já os declara (`frontend/src/hooks/useGroupCycle.ts:31-60`):

| Filtro | Campo(s) | Origem no backend | Observação |
|---|---|---|---|
| Credor | `userPayerId` + `payerName` | `expense->user_payer_id` / `payer->name` | quem pagou a despesa; `payerName` pode ser `null` |
| Devedores | `participantDetails[]` (`id`, `name`, `avatarUrl`) e `participants[]` (só nomes) | `expense->payers` | `participantDetails` é opcional no tipo (§2.3) |
| Status | `paid` | `$entry['paid']` | booleano da parcela do ciclo; só há dois estados |

Conclusão: **nenhuma mudança de API** é necessária. O web e o app móvel continuam consumindo o mesmo contrato (`docs/sdd/05-context-frontend.md`, "Convenções fixas").

### 2.2 A filtragem de hoje é inline e só cobre busca e tipo

`ExpenseManager.tsx:113-115` guarda `search` e `typeFilter`; `:271-277` aplica os dois em `filteredExpenses` direto no componente; `:559-581` desenha a barra (um `TextField` + um `ToggleButtonGroup` Todas/Fixas/Variáveis). O estado vazio "Nenhuma despesa encontrada para esse filtro." (`:617`) já cobre qualquer combinação sem resultado.

Os testes atuais cobrem busca e tipo em `ExpenseManager.test.tsx:173-223` e verificam que filtrar **não** dispara nova chamada a `/expenses/summary` (`:187`). Esse comportamento precisa ser mantido.

### 2.3 Ciclo selado vem de snapshot congelado, sem `participantDetails`

`summary()` devolve o JSON gravado em `ex_group_cycle_snapshots.expenses` quando o ciclo está selado ou fechado manualmente, então campos aditivos mais novos não aparecem nesses ciclos. É por isso que `participantDetails` é opcional no tipo, e `renderDetailPayers` (`ExpenseManager.tsx:322`) já cai em `participants` (só nomes) quando ele falta. O filtro de **Devedores** precisa do mesmo fallback, ou quebra justamente nas competências antigas.

### 2.4 O vocabulário do projeto trata "devedor" de duas formas

- `docs/sdd/01-specify.md` §4 regra 4: nos relatórios "quem deve a quem", o pagador **fica fora** da lista de devedores dele mesmo.
- O modal de detalhes (`renderDetailPayers`, `ExpenseManager.tsx:322`) lista todos os participantes, **incluindo o credor**, marcado com "(credor)".

Para este filtro o usuário escolheu a segunda leitura (§3.2, regra R1). A divergência com os relatórios é consciente e fica registrada aqui para ninguém "corrigir" depois.

## 3. Requisitos

### 3.1 Credor

Filtro de seleção **múltipla**. Mostra as despesas cujo credor (`userPayerId`) está entre os selecionados. Rótulo "Credor". As opções são os credores distintos das despesas da competência carregada, identificados por `userPayerId` e exibidos por `payerName`.

### 3.2 Devedores

Filtro de seleção **múltipla**. Mostra as despesas em que **ao menos um** dos selecionados é participante (pagador). O credor conta como participante: filtrar por Maria mostra tudo de que ela participa, inclusive o que ela mesma pagou. Rótulo "Devedores". As opções são os participantes distintos das despesas da competência carregada; a chave é o `id` de `participantDetails` quando existir e o nome quando só houver `participants` (§2.3).

### 3.3 Status

Filtro de seleção **única**, em `ToggleButtonGroup` no mesmo estilo do filtro de tipo: **Todas / Pagas / Pendentes**. Usa o mesmo `paid` que já colore o chip "Paga"/"Pendente" da listagem.

### 3.4 Composição

Os cinco critérios (busca, tipo, credor, devedores, status) se combinam. Regras de combinação em R2-R3 abaixo. Nenhuma troca de filtro refaz chamada à API.

### 3.5 Acessibilidade

Os dois `ToggleButtonGroup` (tipo e status) têm um botão "Todas". Cada grupo recebe `aria-label` próprio ("Tipo", "Status") para leitor de tela e testes distinguirem os dois.

## 4. Regras adotadas

Decididas com o usuário na conversa de planejamento (2026-10-10) ou derivadas dos requisitos acima. Não dependem de nova decisão.

| # | Regra | Origem |
|---|---|---|
| R1 | **Devedores** = qualquer participante da despesa, **inclusive o credor**. | Escolha do usuário (§2.4) |
| R2 | Dentro de um filtro múltiplo vale **OU**: a despesa passa se *algum* selecionado casa (credor ∈ selecionados; ou algum participante ∈ selecionados). | Escolha do usuário (selects múltiplos) |
| R3 | **Entre** filtros vale **E**. Filtro vazio (nenhum selecionado / "Todas") = sem restrição. | Consequência de R2 e do comportamento atual de busca + tipo |
| R4 | Credor e Devedores são `Autocomplete multiple` do MUI; Status é `ToggleButtonGroup`. | Escolha do usuário |
| R5 | A filtragem é **no cliente**, sobre a competência já carregada, sem nova chamada a `/expenses/summary`. | Mantém o padrão atual (§2.2) |
| R6 | Em ciclo selado (sem `participantDetails`), o filtro de Devedores usa `participants` por nome. | §2.3 |
| R7 | A lógica de filtragem sai do componente para um helper puro e testável, em `frontend/src/utils/`. Busca e tipo mantêm o comportamento atual. | Padrão de `utils/installments.ts` |

## 5. Decisões fechadas

Pontos de design levantados na redação do specify. O usuário aceitou as quatro recomendações na revisão de 2026-10-10 ("ok"), então valem como regras para o `plan.md`.

| # | Decisão | Opção A | Opção B | Decisão aceita | Por quê |
|---|---|---|---|---|---|
| D1 | De onde vêm as opções de Credor e Devedores? | **A.** Derivadas das despesas da competência carregada | **B.** Todos os membros do grupo (chamada extra de membros) | **A** | Zero chamada nova e nunca oferece um nome que resultaria em lista vazia. A desvantagem é que o dropdown muda de competência para competência. |
| D2 | O que acontece com credores/devedores selecionados ao navegar para outra competência? | **A.** Manter a seleção e **podar** quem não existe na nova competência | **B.** Zerar os filtros de pessoa a cada troca de competência | **A** | Quem continua aparecendo segue filtrado, e o `Autocomplete` nunca recebe valor fora das opções (que deixaria um chip de pessoa que não existe na competência). |
| D3 | Incluir um botão "Limpar filtros"? | **A.** Não neste escopo | **B.** Sim, botão que zera os cinco critérios | **A** | Não foi pedido. Cada controle já se limpa sozinho (chip do `Autocomplete`, botão "Todas"). Se virar incômodo, entra depois como backlog. |
| D4 | Persistir filtros ao sair da tela (URL ou `localStorage`)? | **A.** Não (estado local, como busca e tipo hoje) | **B.** Sim | **A** | Coerente com os filtros existentes. Persistir exige decidir o escopo por grupo e por competência, e ninguém pediu. |

## 6. Fora de escopo desta feature

- **Filtro/paginação no servidor.** O item de backlog 026 (`docs/backlog/expense-busca-filtro-paginacao-backend.md`) continua aberto e não muda. Ver R5.
- **Mudança de contrato de API ou de backend.** Nada novo é exposto (§2.1).
- **Pagamento individual por participante.** Hoje o Status é um booleano por parcela. Granularidade por devedor é outro item de backlog (`docs/backlog/pagamento-individual-por-despesa.md`) e, quando existir, pode pedir um filtro de Status mais rico.
- **Outras telas** (Pagamentos, Resumo, Relatórios, `ExpenseView`) e o **app Flutter** (`app/`).
- **Eventos de analytics** (`docs/analytics/README.md`): nenhum evento novo.
- **Mudar a regra de "devedor" dos relatórios** (§2.4).
