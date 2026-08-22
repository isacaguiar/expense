# Specify — Melhoria da Gestão de Despesas do Grupo

> Feature: evolui a tela `/groups/{groupId}/expenses` para grid com listagem + saldo por pessoa, fechamento/reabertura de competência com snapshot histórico, e ciclo de vida de pagamento (PENDENTE → PAGA só pelo credor). Pedido novo, feito pelo usuário via `/nova-feature` com objetivo detalhado; sobrepõe parcialmente a `TASK-018` (Épico D, `docs/sdd/03-tasks.md`).

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

A tela `/groups/{groupId}/expenses` (hoje [`ExpenseManager.tsx`](../../../frontend/src/pages/ExpenseManager.tsx)) só lista despesas em cards simples (descrição, valor, tipo, pagador) e só permite remover recorrência de despesa fixa — não tem status de pagamento, credor, pagadores explícitos, saldo por pessoa, fechamento de competência nem ações de editar/excluir/marcar como paga. Falta também regra de negócio para: despesa fixa preservar valor histórico de competências já fechadas, despesa nascer sempre `PENDENTE`, só o credor poder confirmar pagamento, e competência fechada travar edição/exclusão. Hoje só o backend calcula parte disso (saldos, ciclo) na rota `/groups/{groupId}/expenses/summary`, consumida por uma página separada, [`GroupSummary.tsx`](../../../frontend/src/pages/GroupSummary.tsx) (rota `/groups/:id/summary`), que já tem navegação de ciclo, lista de despesas com status pago/pendente e lista de saldos por pessoa — mas empilhados em coluna única, sem ícones de ação e sem as colunas completas (Tipo, Competência, Credor, Pagadores) pedidas para a tela de despesas.

## 2. Requisitos

### 2.1 Referência visual — grid de despesas + saldo por pessoa

Mockup de referência: `E:\Projetos\Controle de Despesas\assets\images\tela-despesas.png` — mostra layout em grid de duas colunas: à esquerda "Despesas do Mês" em cards compactos (ícone de tipo, descrição, "Fixa/Todo dia N" ou "Variável/data", valor, "Pago por X", "Dividido entre N pessoas", pill de status Pago/Pendente, link "Ver todas as despesas"); à direita "Saldos por pessoa" como cards individuais (avatar, nome, valor, rótulo "a receber"/"a pagar").

Decisões confirmadas com o usuário para o `plan.md`:

- O estilo de card da imagem é a base visual da listagem principal (não vira tabela tradicional linha/coluna) — mas precisa ganhar os campos que a imagem não mostra e o objetivo original pede: Tipo explícito, Competência, Credor, Pagadores (lista, não só contagem), e os ícones de ação (editar/excluir/marcar como paga) com tooltip, exibidos condicionalmente pelas regras de negócio.
- A tela `/groups/{groupId}/expenses` evolui a partir da lógica e dos dados que já existem em `GroupSummary.tsx` (navegação de ciclo, totais, cálculo de saldo por pessoa via `/groups/{groupId}/expenses/summary`) em vez de nascer do zero a partir do `ExpenseManager.tsx` atual. `plan.md` decide o destino técnico exato (`ExpenseManager.tsx` absorve o padrão de `GroupSummary.tsx`, ou as duas convergem/uma é removida) — não decidido aqui.

### 2.2 Exclusão de despesas variáveis

Hoje `DELETE /expenses/{id}` (`ExpenseController::destroy`, [ExpenseController.php:139-151](../../../backend/app/Http/Controllers/ExpenseController.php)) já chama `rejectIfCycleClosed()` ([ExpenseController.php:160-173](../../../backend/app/Http/Controllers/ExpenseController.php)), que bloqueia exclusão de despesa `IN_CASH`/`IN_INSTALLMENTS` ("variável") cujo ciclo já esteja `closed` — essa regra de backend já existe e cobre parte do requisito. Faltam:

- Nenhuma tela hoje oferece excluir despesa variável: [ExpenseManager.tsx:255-264](../../../frontend/src/pages/ExpenseManager.tsx) só tem exclusão/corte de recorrência de despesa `FIXED`, sem modal, sem confirmação, sem chamada ao `DELETE /expenses/{id}` genérico.
- Modal de confirmação explícita informando qual despesa será excluída — não existe hoje (requisito novo, só frontend).
- Bloquear exclusão de despesa já paga: hoje não é possível checar isso no backend porque não existe status pago/pendente na `Expense` (só `ex_quotas.paid`, não lido em `destroy()`) — depende do requisito 2.6 (status de pagamento) existir primeiro. **Decisão confirmada**: excluir despesa paga é sempre bloqueado; é preciso desfazer o pagamento (voltar para `PENDENTE`, ver 2.6) antes de poder excluir — e isso só é possível com a competência ainda aberta.

### 2.3 Despesas fixas — valor por competência

Achado técnico (gap real, não é comportamento desejado): `rejectIfCycleClosed()` pula a checagem de ciclo fechado para `expense_type === 'FIXED'` de propósito ([ExpenseController.php:153-164](../../../backend/app/Http/Controllers/ExpenseController.php)), confiando que a "foto" do ciclo (`GroupCycleSnapshot`) já tenha sido persistida. Mas essa foto é criada de forma **preguiçosa** — só na primeira leitura de `GET /groups/{groupId}/expenses/summary` depois que o ciclo fecha (`ExpenseController::cycleSnapshotFor`, [ExpenseController.php:355-396](../../../backend/app/Http/Controllers/ExpenseController.php)). Se ninguém consultou o resumo daquele ciclo fechado antes de alguém editar `total_value` de uma despesa `FIXED`, a projeção retroativa daquele ciclo passado muda — porque as ocorrências mensais de uma `FIXED` são recalculadas em memória a partir do `date_payment` original a cada requisição ([ExpenseController.php:524-556](../../../backend/app/Http/Controllers/ExpenseController.php)), sem nenhum valor histórico persistido por competência.

Requisito novo: alterar o valor de uma despesa `FIXED` não pode mudar competências já fechadas, **independente de já terem sido fotografadas ou não**. Isso exige persistir o valor usado por competência de forma confiável no momento do fechamento (ver 2.4), não depender de leitura prévia lazy do snapshot.

### 2.4 Fechamento mensal (competência)

Hoje o fechamento é 100% automático por data: `BillingCycle::statusFor`/`cycleFor` ([BillingCycle.php:21-44](../../../backend/app/Support/BillingCycle.php)) calculam `open`/`closed`/`future` comparando `ex_groups.closing_day` com `Carbon::now()` — não existe ação humana de "fechar", nem rota para isso. O `GroupCycleSnapshot` (tabela `ex_group_cycle_snapshots`) só é criado lazily na primeira leitura pós-fechamento automático ([ExpenseController.php:355-396](../../../backend/app/Http/Controllers/ExpenseController.php)).

Requisito novo: botão "Fechar mês" na tela de despesas + rota backend que força a criação do snapshot da competência **vigente** a qualquer momento (o objetivo original permite fechar antes da virada do mês, e a cópia pode ser atualizada até a virada final). Isso é uma capacidade nova — hoje não existe fechamento manual antecipado, só o automático por `closing_day`. `plan.md` decide como diferenciar "fechado manualmente, ainda dentro do mês, editável até a virada" de "fechado definitivamente pela virada do mês" (provavelmente um novo campo, já que hoje o status é 100% calculado, não persistido, em `ex_group_cycle_snapshots`).

### 2.5 Reabertura de mês

Não existe hoje nenhuma rota nem lógica de reabertura — busca por `reopen`/`reabrir` em todo `backend/` não retorna resultado. Requisito novo: invalidar/apagar o snapshot persistido em `ex_group_cycle_snapshots` **somente se** a competência fechada ainda corresponder ao mês vigente (checagem contra `Carbon::now()` e o ciclo atual via `BillingCycle`). Depois da virada do mês, a competência anterior precisa ficar definitivamente imutável — ou seja, a reabertura precisa ser negada mesmo que o snapshot ainda exista.

### 2.6 Pagamento da despesa

Não existe hoje status `PAGO`/`PENDENTE` na despesa em si. O único "pago" persistido é `ex_quotas.paid` (boolean simples, sem `paid_at`/`paid_by`), e os controllers que existiriam para mexer nisso são scaffolds vazios sem rota registrada: `ParticipationController` ([ParticipationController.php:8-65](../../../backend/app/Http/Controllers/ParticipationController.php), todos os métodos com corpo vazio) e `QuotaController` (mesmo padrão). Isso confirma a lacuna já registrada em `docs/sdd/03-tasks.md` TASK-018.

Requisitos novos: status `PENDENTE`/`PAGA` persistido na despesa (nasce sempre `PENDENTE`), `paid_at`, `paid_by`; só quem é credor — hoje modelado como `user_payer_id` ([Expense.php:55-58](../../../backend/app/Models/Expense.php), apesar do nome "payer" ele recebe de volta dos demais participantes no cálculo de saldo, [ExpenseController.php:439-453](../../../backend/app/Http/Controllers/ExpenseController.php)) — pode marcar como paga. **Decisão confirmada**: desfazer pagamento é permitido, só pelo credor, e só com a competência ainda aberta; competência fechada nunca permite mudar status (reaproveita a mesma checagem de 2.3/2.4, mas hoje ela é pulada para `FIXED` — precisa ser revista para cobrir status também, não só valor).

### 2.7 Saldo por pessoa

Já existe quase pronto no backend: `computeCycleSummary()` ([ExpenseController.php:404-466](../../../backend/app/Http/Controllers/ExpenseController.php)) e `cycleSnapshotFor()` calculam saldo por participante (pago/creditado, devido, líquido) e já são consumidos por [GroupSummary.tsx:242-262](../../../frontend/src/pages/GroupSummary.tsx) como lista de cards com avatar. Requisito: reaproveitar esse endpoint/cálculo já existente (`GET /groups/{groupId}/expenses/summary`) na nova tela `/expenses`, exibindo como os cards de "Saldo por pessoa" do lado direito da grid (ver 2.1) — não é um cálculo novo, é reuso.

### 2.8 Competência selecionada / navegação

Hoje existem **duas** navegações de período divergentes entre as duas telas envolvidas:

- [GroupSummary.tsx:85,133-148](../../../frontend/src/pages/GroupSummary.tsx) navega por `cyclesAgo`, usando o ciclo real do backend (`BillingCycle`, respeitando `closing_day` do grupo).
- [ExpenseManager.tsx:60,79-85](../../../frontend/src/pages/ExpenseManager.tsx) navega por `currentDate`/`changeMonth`, usando mês calendário puro (ano/mês), sem relação com `BillingCycle`/`closing_day`.

Requisito: a tela final `/groups/{groupId}/expenses` precisa de uma navegação única e consistente com o `BillingCycle` real do backend (não duas noções de período diferentes). `plan.md` decide qual das duas abordagens vira a oficial — dado a decisão já tomada em 2.1 de evoluir a partir de `GroupSummary.tsx`, o caminho natural é adotar a navegação por ciclo (`cyclesAgo`) dela, mas isso precisa ficar explícito no plano técnico.

### 2.9 Regras de consistência (validação também no backend)

Achados de gaps adicionais que a feature precisa fechar, não só os já citados em 2.2–2.6:

- `store()` (criar despesa nova) não chama `rejectIfCycleClosed()` nem `BillingCycle` em nenhum ponto ([ExpenseController.php:175-262](../../../backend/app/Http/Controllers/ExpenseController.php)) — hoje é possível criar uma despesa nova com `date_payment` dentro de um ciclo já fechado, sem nenhum bloqueio.
- Toda regra de exclusão/edição/pagamento/fechamento/reabertura desta feature precisa de validação no backend, não só na UI — a interface deve refletir permissões vindas do domínio (achado já é o padrão do projeto: `rejectIfCycleClosed()` e `authorizeExpenseOwner()`, [ExpenseController.php:570-575](../../../backend/app/Http/Controllers/ExpenseController.php), já seguem esse padrão para os casos que cobrem hoje).

## 3. Fora de escopo desta feature

- Corrigir `ExpenseController::index` ausente (rota `GET /expenses` registrada via `apiResource` sem método implementado) — já é `TASK-016` em `docs/sdd/03-tasks.md` Épico C, feature separada.
- Unificar fluxos de convite (`TASK-019`) e extrair Service de saldo compartilhado entre `computeCycleSummary` e os relatórios de `GroupExpenseReportController` (`TASK-020`) — tasks separadas do Épico D, não bloqueiam esta feature mesmo que toquem código relacionado.
- Mudar a semântica do ciclo de faturamento em si (`closing_day`, cálculo `BillingCycle`) — a feature usa o ciclo existente como base da competência, não redesenha esse conceito.
- Migração para o app Expo/React Native (`docs/feature/20260817-migracao-frontend-expo/`) — esta feature é só frontend web (`frontend/`).
- Notificação/e-mail sobre fechamento de competência ou confirmação de pagamento — não pedido no objetivo original.
- Persistência de `Participation`/`ex_quotas.paid` tal como hoje modelada — se o desenho técnico em `plan.md` optar por reaproveitar/substituir essas tabelas para o status de pagamento (2.6), é decisão técnica de lá, não requisito fechado aqui.
