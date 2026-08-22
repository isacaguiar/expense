# Specify — Home: ciclo corrente, navegação futura, status do ciclo e fechamento congelado

> Feature: a tela "Home" (`/groups/:id/summary`) passa a abrir no ciclo/mês corrente (mesmo em andamento) em vez do último ciclo já fechado, permite navegar para ciclos futuros e passados, exibe o status do ciclo (aberto/fechado/futuro), e garante que um ciclo já fechado nunca mude de valor depois — tirando uma "foto" da resposta inteira do ciclo na primeira leitura após o fechamento. Origem: pedido direto do usuário nesta conversa, sem task/épico prévio em `03-tasks.md`.

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

A tela "Home" (sidebar chama assim; componente `frontend/src/pages/GroupSummary.tsx`) hoje abre mostrando o **último ciclo já fechado** do grupo (ex.: em 21/08/2026, mostra "01 De Jul – 31 De Jul", não agosto) e a seta "próximo" fica travada (`disabled={cyclesAgo === 0}`), impossibilitando navegar para o ciclo em andamento ou para ciclos futuros. O usuário quer ver o mês corrente por padrão e poder navegar à frente, e destacou que o status do ciclo (aberto/fechado) precisa ficar visível.

**Importante**: isso reverte um requisito explícito da feature original que criou essa tela — `docs/feature/20260818-resumo-grupo-dashboard/specify.md` R3: *"A tela 'Resumo do grupo' abre, por padrão, no ciclo fechado mais recente do grupo selecionado... (não no ciclo em andamento)"* — e `plan.md` (linha ~23, ~39): *"o ciclo que contém hoje é 'em andamento' e nunca é o default"*, *"nunca navega para o ciclo em andamento"*. Não é correção de bug; é mudança de requisito de produto, decidida nesta conversa.

## 2. Requisitos

### 2.1 `BillingCycle` passa a calcular o ciclo que contém a referência, não o último fechado

`backend/app/Support/BillingCycle.php:18-35` (`closedCycle`) calcula hoje o mês-âncora do último ciclo **fechado** e desloca `cyclesAgo` ciclos fechados para trás (`cyclesAgo` sempre `>= 0` na prática, já que nada no código chama com valor negativo). Passa a calcular o mês-âncora do ciclo que **contém a data de referência** (podendo estar aberto, ou no futuro quando `cyclesAgo` for negativo), e a devolver também um `status` (`'closed' | 'open' | 'future'`).

### 2.2 Navegação futura destravada no frontend

`frontend/src/pages/GroupSummary.tsx:134-140` — botão "próximo" hoje: `setCyclesAgo(prev => Math.max(prev - 1, 0))`, `disabled={cyclesAgo === 0}`. Passa a decrementar livremente, sem clamp nem disabled, simétrico ao botão "anterior" (linha 125-130, já sem limite).

### 2.3 Status do ciclo visível na tela

Nenhum campo de status de ciclo existe hoje na resposta de `GET /groups/{groupId}/expenses/summary` (`ExpenseController::summary`, `backend/app/Http/Controllers/ExpenseController.php:285-395`) — só `cycle: {start, end}`. Passa a incluir `status`, exibido como um indicador visual (`Chip`) no cabeçalho da tela, distinto do `Chip` "Paga"/"Pendente" que já existe por despesa (`GroupSummary.tsx:215-219`) — são dois conceitos de status diferentes (ciclo vs. despesa individual) e não devem ser confundidos visualmente.

### 2.4 Bug preexistente: parcelas de despesa `IN_INSTALLMENTS` só aparecem no ciclo de criação

Achado durante a investigação desta feature, em `ExpenseController::collectCycleEntries` (linhas ~371-432): a query de despesas do ciclo (`Expense::whereBetween('date_payment', [...])`) não distingue `IN_INSTALLMENTS` das demais — como `date_payment` é fixo na data de criação (1ª parcela), uma 2ª/3ª parcela com `date_expected` em outro ciclo nunca é retornada pela query daquele ciclo. Isso já existe hoje (afeta também navegação para ciclos fechados passados), mas fica muito mais visível e contraditório com o objetivo desta feature assim que a navegação futura for liberada — uma parcela já lançada "sumiria" do resumo ao navegar até o ciclo dela. Decisão tomada com o usuário: corrigir dentro desta mesma feature, reaproveitando o padrão já usado em `indexByGroup` desde `docs/feature/20260821-expense-manager-mes-e-data-corretos/` (separar consulta direta por `date_payment` das parcelas via `Quota::whereBetween('date_expected', ...)`).

### 2.5 Ciclo fechado é só leitura, e nunca muda de valor depois (foto do ciclo)

Requisito adicionado pelo usuário após a primeira aprovação deste `specify.md`, em 3 rodadas até chegar neste desenho final:

1. Navegar para ciclos passados deve ser só leitura — não altera valores de despesas de um ciclo já fechado.
2. O valor de um ciclo fechado nunca muda depois, mesmo que a despesa correspondente seja editada.
3. **Decisão final**: o congelamento é uma foto do **ciclo inteiro** (totais, lista de despesas, saldos por pessoa) — não é por despesa nem exclusivo de um tipo de despesa. Vale igualmente para `IN_CASH`, `IN_INSTALLMENTS` e `FIXED`.

Achado que motivou a investigação: despesas `FIXED` (Fixas/recorrentes) não têm nenhuma "parcela por mês" como `IN_INSTALLMENTS` — o valor projetado em qualquer mês é sempre lido ao vivo de `Expense.total_value` (`ExpenseController.php:422`, dentro de `collectCycleEntries`). Editar esse valor hoje muda retroativamente todos os meses, inclusive os fechados. `IN_INSTALLMENTS` já é seguro para o *valor* (`Quota.value_quota` congelado na criação), mas nenhum tipo de despesa está protegido contra edição de descrição/pagadores nem contra exclusão (soft delete) retroativa — daí a decisão de fotografar o ciclo inteiro, não só valores de despesa Fixa.

Não existe hoje nenhuma infraestrutura de fechamento/agendamento (`backend/app/Console/Kernel.php` só tem o exemplo comentado, sem cron real; `ex_participations`/model `Participation` existe mas é 100% órfã — nenhum código cria/lê/atualiza — e tem formato errado para servir de foto: 1 registro por `Quota`, sem `user_id`/totais/saldos). A foto é tirada de forma **preguiçosa** (lazy), na primeira leitura do ciclo após o fechamento, sem depender de nenhum job agendado.

Nova tabela `ex_group_cycle_snapshots` — uma foto por `(group_id, cycle_start)`, guardando a resposta inteira de `summary()` (`totals`, `expenses`, `balances`) computada na primeira leitura após o fechamento. Ao consultar `summary()` para um ciclo com `status === 'closed'`: se já existe foto, devolve os dados da foto diretamente (sem tocar `Expense`/`Quota`); se não existe, computa ao vivo como hoje e persiste o resultado antes de responder. Ciclos `open`/`future` continuam 100% ao vivo, sem persistir nada.

Consequência: uma vez fotografado, editar ou apagar qualquer despesa depois não muda mais nada naquele ciclo — para nenhum tipo de despesa. Isso resolve a garantia de "os fechados devem ser mantidos" (valor, descrição, pagadores) de forma unificada.

Limitação assumida e documentada (não é bug): a foto reflete o estado do ciclo na primeira leitura após o fechamento, não necessariamente no instante exato do fechamento (não há scheduler em produção hoje).

### 2.6 Bloqueio de edição/exclusão de despesa em ciclo fechado (regra de UX/negócio, não de integridade)

Como a foto (2.5) já garante que os valores do passado não mudam, o bloqueio de edição existe só para impedir edição de despesas "no passado" como regra de produto (navegação em ciclo fechado é só leitura) — não é o mecanismo que protege os números.

- `IN_CASH`/`IN_INSTALLMENTS`: se o ciclo da `date_payment` da despesa (via `BillingCycle` + `group.closing_day`) tiver `status === 'closed'`, `ExpenseController::update()` e `destroy()` (`backend/app/Http/Controllers/ExpenseController.php:111-142`) retornam `422`. Ciclo `open`/`future` continua liberado como hoje.
- `FIXED`: sem bloqueio adicional — `total_value`, `description`, `payers`, `date_payment` continuam editáveis a qualquer momento, e `destroy()` continua soft-delete normal, porque a foto (2.5) já é independente da `Expense` ao vivo.
- `stopRecurrence()` (`ExpenseController.php:233-262`): se o ciclo do `cutoff` (via `BillingCycle`) tiver `status === 'closed'`, rejeita com `422` — além da validação já existente (cutoff não anterior à criação).

### 2.7 Bug preexistente adicional: `collectCycleEntries` recalcula até para ciclos já fechados

Achado como parte da investigação de 2.5: hoje `ExpenseController::summary` sempre chama `collectCycleEntries` ao vivo, para qualquer `cyclesAgo`, mesmo para ciclos fechados há muito tempo — sem nenhuma persistência do resultado. A feature 2.5 corrige isso introduzindo a foto; antes dela, esse recálculo constante era apenas ineficiente (não incorreto, já que os dados de origem hoje não mudam sozinhos) — mas se tornaria uma inconsistência real assim que edição de despesas Fixas passasse a ser mais comum, daí a decisão de resolver agora.

## 3. Fora de escopo desta feature

- Qualquer mudança no terceiro status "Aguardando" por despesa (backlog item 025, `docs/backlog/expense-status-aguardando.md`) — é um conceito distinto do status de ciclo tratado aqui, sem decisão de negócio tomada ainda.
- Limitar o alcance da navegação futura/passada (ex.: não deixar navegar mais de N meses à frente) — decisão: sem teto artificial, já que o custo de calcular um ciclo é O(1) independente do deslocamento.
- Notificação/e-mail sobre fechamento de ciclo — já estava fora de escopo da feature original e continua.
- Mudar `ExpenseController::indexByGroup` ou `ExpenseManager.tsx` (mês calendário) para usar o conceito de ciclo — eles continuam em mês calendário; só o endpoint de `summary` usa ciclo. Consequência aceita: uma despesa editada/apagada continua aparecendo normalmente em `ExpenseManager`/`indexByGroup` mesmo pertencendo a um período cujo ciclo de `summary` já está fotografado — os dois endpoints não são unificados nesta feature.
- Criar um agendador (cron/Artisan schedule) para fotografar ciclos automaticamente no instante exato do fechamento — a foto é sempre preguiçosa (lazy), na primeira leitura após o fechamento.
- Adicionar UI de edição de despesa (`ExpenseForm.tsx` continua só criação, `ExpenseView.tsx` continua só leitura) — o bloqueio de edição (2.6) protege a API, que hoje já é a única superfície de mutação alcançável fora da UI existente.
- Qualquer estrutura de reconciliação caso a foto de um ciclo precise ser corrigida manualmente depois de criada (ex.: bug na primeira leitura) — não há endpoint/ferramenta de "refazer a foto" nesta feature.
