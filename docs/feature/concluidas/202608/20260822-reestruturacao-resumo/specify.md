# Specify — Reestruturação do Resumo do Grupo

> Feature: reorganiza a tela "Resumo do grupo" (`/groups/{id}/summary`) de blocos empilhados verticalmente para um layout de duas colunas (despesas + painel lateral com abas Saldo/À pagar), reduzindo rolagem e aproveitando espaço horizontal — só UI/UX, sem alterar API nem regra de negócio. Origem: pedido novo do usuário nesta conversa (sem task/épico prévio em `03-tasks.md`), com mockup de referência em `E:\Projetos\Controle de Despesas\assets\images\tela-despesas.png` (fora do repositório).

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

A tela de Resumo do grupo (`GroupSummary.tsx`) apresenta hoje três blocos empilhados verticalmente, cada um ocupando a largura total: "Despesas do ciclo" (`GroupSummary.tsx:133-168`), "Saldos por pessoa" (`GroupSummary.tsx:170-173`) e, quando há liquidação par-a-par, "Quem paga a quem" (`GroupSummary.tsx:175-182`, adicionado por `docs/feature/concluidas/202608/20260822-acerto-de-contas-ciclo/`). Em telas largas isso desperdiça espaço horizontal e obriga rolagem vertical maior do que o necessário para ver os três blocos — o usuário quer aproveitar a largura disponível em desktop com um layout em duas colunas, mantendo pilha vertical em mobile.

## 2. Achados confirmados

### 2.1 Os três blocos hoje formam uma sequência linear única, sem contêiner próprio por bloco

`GroupSummary.tsx:40-187` renderiza tudo em um único fragmento (`<>...</>`): cards de totais (`Grid container`, linhas 87-131), depois `Typography` + `Paper`/`List` de despesas (133-168), depois `Typography` + `BalanceCards` (170-173), depois, condicionalmente, `Typography` + `SettlementList` (175-182). Não há hoje nenhum `Grid`/`Box` de duas colunas envolvendo os blocos de despesas e saldo — a reestruturação pedida precisa introduzir esse contêiner.

### 2.2 "Saldos por pessoa" e "Quem paga a quem" já são componentes isolados e prontos para reuso

`BalanceCards` (`frontend/src/components/BalanceCards.tsx`) e `SettlementList` (`frontend/src/components/SettlementList.tsx`) já recebem os dados via props (`balances`, `settlements`) e não têm acoplamento com o layout ao redor — podem ser movidos para dentro de um painel com abas sem alteração de props. `BalanceCards` já é reaproveitado também por `ExpenseManager.tsx` (comentário em `BalanceCards.tsx:14-19`) — qualquer mudança no componente em si (não só em onde ele é renderizado) afeta essa outra tela também.

### 2.3 Não existe hoje nenhum uso de MUI `Tabs`/`Tab` no frontend

Busca por `Tabs`/`Tab` em `frontend/src` não encontrou nenhum uso — o padrão de abas "Saldo"/"À pagar" pedido é um componente novo neste código, não um reuso de um padrão de abas já existente. `plan.md` deve decidir estilo (MUI `Tabs` padrão, cores/tipografia do `theme.ts`) já que não há precedente direto a copiar.

### 2.4 Teste existente depende do texto "Quem paga a quem" sumir da árvore quando `settlements` está vazio

`GroupSummary.test.tsx:200-210` (`does not show the "quem paga a quem" block when settlements is empty`) verifica `queryByText('Quem paga a quem')` como `not.toBeInTheDocument()`. Se a nova estrutura em abas mantiver um rótulo de aba fixo "À pagar" (conforme pedido do usuário) independente de `settlements` estar vazio, esse teste específico deixa de fazer sentido do jeito que está escrito hoje e precisa ser adaptado — não é um requisito novo, é um efeito colateral da mudança de estrutura que `plan.md`/`tasks.md` devem tratar explicitamente.

### 2.5 Cards de totais já usam MUI Grid v2 (`size={{ xs, md }}`) — padrão a manter

`GroupSummary.tsx:87-131` usa `<Grid container spacing={2}>` com `<Grid size={{ xs: 12, md: 4 }}>` por card — é o padrão de grid já adotado no projeto (mesma sintaxe usada em outras telas, ex. `ExpenseManager.tsx`, `GroupMembersForm.tsx`) e deve ser reaproveitado para as novas colunas "Despesas"/"Painel lateral", em vez de introduzir outro mecanismo de layout (ex. CSS Grid manual ou Flexbox cru), salvo se `plan.md` justificar o contrário.

### 2.6 Contrato de dados já fornecido por `useGroupCycle` cobre tudo que as duas colunas precisam

`useGroupCycle` (`frontend/src/hooks/useGroupCycle.ts`) já expõe `summary.totals`, `summary.expenses`, `summary.balances` e `summary.settlements` num único objeto — a reestruturação não precisa de nenhum campo novo do backend nem de chamada de API adicional; é reorganização do JSX existente em `GroupSummary.tsx` consumindo os mesmos dados.

### 2.7 Status do ciclo já determina se o valor é definitivo (snapshot) ou volátil (cálculo ao vivo), mas hoje sem indicação visual perto do saldo/liquidação

`useGroupCycle.ts:9` define `CycleStatus = 'closed' | 'open' | 'future' | 'closed_manually'`, já exposto em `summary.cycle.status` — nenhum campo novo de API é necessário para o requisito abaixo. No backend, `ExpenseController::summary()` (`backend/app/Http/Controllers/ExpenseController.php:373-415`) usa `cycleSnapshotFor()` (imutável, linhas 592-636) para `closed` e `closed_manually`, e `computeCycleSummary()` ao vivo (linhas 645-755) para `open` (sem fechamento manual) e `future` — a mesma função que calcula tanto `balances` quanto `settlements`, logo ambos são igualmente voláteis nesses dois status (podem mudar conforme despesas são criadas/editadas/excluídas antes do fechamento do ciclo).

Já existe um chip de status no topo da página (`cycleStatusChip`, `GroupSummary.tsx:78-85`), mas ele fica distante do painel lateral (Saldo/À pagar, achado 2.1) e não tem nenhuma ligação visual direta com esses blocos. `AutorenewOutlinedIcon` já é usado na mesma tela (`GroupSummary.tsx:146-147`) para marcar despesa Fixa/recorrente — não deve ser reaproveitado com outro significado no selo de valor volátil, sob risco de confundir os dois conceitos na mesma tela. Nenhum precedente textual de "previsão"/"estimativa"/"provisório" existe hoje no projeto (frontend ou backend) — é um padrão novo.

## 3. Requisitos

- **R1**: Em telas desktop, a área abaixo dos cards de totais (`Total de despesas`/`Pago`/`A pagar`, que permanecem no topo, achado 2.1) passa a ter duas colunas lado a lado: coluna esquerda com "Despesas do ciclo" (~65–70% da largura) e coluna direita com um painel lateral de resumo financeiro (~30–35% da largura).
- **R2**: A coluna "Despesas do ciclo" preserva integralmente o conteúdo e comportamento atual de cada item da lista (`GroupSummary.tsx:143-165`): descrição, valor, data, pagador, quantidade de participantes, ícone fixa/variável, chip Paga/Pendente — sem remover nem duplicar essa lógica de exibição.
- **R3**: O painel lateral direito substitui a exibição sequencial atual de "Saldos por pessoa" + "Quem paga a quem" por um único card com duas abas: **Saldo** (conteúdo atual de `BalanceCards`, achado 2.2) e **À pagar** (conteúdo atual de `SettlementList`, achado 2.2), reaproveitando os dois componentes existentes sem duplicar a lógica de cálculo/formatação que já têm.
- **R4**: A aba **Saldo** vem selecionada por padrão ao abrir a tela. Trocar para **À pagar** (e voltar) troca apenas o conteúdo do painel lateral, sem navegação de rota nem recarregamento da página.
- **R5**: Em mobile (mesmo breakpoint já usado pelos cards de totais, achado 2.5), a estrutura volta a ser empilhada verticalmente: "Despesas do ciclo" primeiro, painel lateral com abas depois — sem rolagem horizontal em nenhum breakpoint.
- **R6**: Indicação visual de valor a receber (verde) / a pagar (vermelho ou laranja) já existente em `BalanceCards`/`SettlementList` (achado 2.2) é preservada sem alteração de cor/semântica.
- **R7**: Nenhuma chamada de API, endpoint, campo de resposta ou regra de cálculo de saldo/liquidação é alterada — a feature consome exatamente os mesmos dados de `useGroupCycle` (achado 2.6), só reorganiza onde e como são exibidos.
- **R8**: Quando `summary.cycle.status` for `open` ou `future`, as abas **Saldo** e **À pagar** do painel lateral exibem um selo "Prévia" (com ícone de "ainda em progresso", ex. `UpdateIcon`/`PendingOutlinedIcon`) no título da aba, sinalizando que o valor pode mudar até o ciclo fechar. Quando `closed` ou `closed_manually`, o selo indica valor definitivo (ícone tipo `PaidOutlinedIcon`), reaproveitando o mesmo `summary.cycle.status` já consumido pelo chip existente (achado 2.7) — sem chamada de API nova.

## 4. Fora de escopo desta feature

- Qualquer mudança em `useGroupCycle`, endpoints de `/expenses/summary` ou nas regras de cálculo de `totals`/`balances`/`settlements` — puramente apresentação (achado 2.6, requisito R7).
- Redesenho do card de totais no topo (`Total de despesas`/`Pago`/`A pagar`) — continuam como estão hoje, só a área abaixo deles é reestruturada.
- Simplificação multilateral de dívida ou qualquer mudança na lógica de liquidação par-a-par — já definida como fora de escopo em `docs/feature/concluidas/202608/20260822-acerto-de-contas-ciclo/specify.md` §"Fora de escopo" e não reaberta aqui.
- Ação de marcar despesa/quota como paga, ou qualquer alteração de estado a partir da tela de Resumo — a tela continua só de leitura.
- Redesenho de navegação global (sidebar) ou rebrand visual (paleta, logo) — já marcados fora de escopo em `docs/feature/concluidas/202608/20260818-resumo-grupo-dashboard/specify.md` §4 e não reabertos aqui.
- Ajuste do teste `GroupSummary.test.tsx:200-210` em si (achado 2.4) fica registrado aqui como efeito colateral conhecido, mas a decisão de como reescrevê-lo é de `plan.md`/`tasks.md`, não desta seção.
- Alterar o chip de status do ciclo já existente no topo da página (`GroupSummary.tsx:78-85`) — o selo novo das abas (R8) é um reforço visual local, não substitui nem duplica esse chip.
- Mudar a regra de quando um ciclo é considerado fechado/aberto/futuro, ou o mecanismo de snapshot (`cycleSnapshotFor`/`computeCycleSummary`) — R8 só consome o status já calculado (achado 2.7).
