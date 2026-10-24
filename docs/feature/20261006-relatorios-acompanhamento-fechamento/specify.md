# Specify — Acompanhamento e Fechamento nos Relatórios

> Feature: a tela de Relatórios passa a mostrar também os meses **encerrados que ainda aguardam pagamento** (aba Acompanhamento) e a oferecer, para qualquer ciclo, um **relatório de fechamento** com credores, devedores e status dos acertos, com impressão/PDF. Origem: pedido do usuário em 2026-10-06, depois de constatar que em produção Relatórios só mostrava agosto. Não é migração de épico de `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20261006

---

## 1. Problema

Em produção, a tela Relatórios ("Histórico de ciclos fechados", `frontend/src/pages/GroupReports.tsx:27`) mostrava só o ciclo de agosto. Setembro já tinha fechado por data (30/09 + 5 dias de carência = 05/10), com despesas "Pendente" e acertos sem comprovante, e por isso não aparecia.

Causa confirmada (não é bug de código): `ExpenseController::cycleHistory` só lista ciclos **selados**, `whereNotNull('settled_at')` (`backend/app/Http/Controllers/ExpenseController.php:775-779`), decisão da feature `docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/` (plan §3). Um ciclo só sela quando toda despesa está paga **e** todo acerto tem comprovante confirmado (`cycleIsFullySettled`, `ExpenseController.php:1323`). Consulta de diagnóstico ao banco de produção (2026-10-06, `consultas-tela-relatorio.md` §3): o grupo tinha só dois snapshots, agosto selado em 2026-09-06 e julho vazio (`settled_at` nulo, total 0). Setembro não tem snapshot, porque o snapshot só nasce ao selar ou ao fechar manualmente (`ExpenseController.php:671`, `:1388`).

Consequências para o usuário:

- **Um mês encerrado e pendente fica invisível em Relatórios.** O único jeito de acompanhá-lo é Home/Despesas/Pagamentos, navegando por setas de ciclo (o `focus-cycle` abre direto nele, `ExpenseController.php:600`). Quem entra em Relatórios para ver "como está setembro" conclui que o mês não existe.
- **Não existe um relatório de fechamento consolidado.** O detalhe de um ciclo (`CycleDetailPanel`) lista despesas e mostra saldos e acertos par-a-par, mas não organiza a leitura por **credor → devedores** (quem adiantou quanto e de quem recebe) e não tem como imprimir ou guardar em PDF.

Contexto de bug relacionado, já corrigido e em produção em 2026-10-06 (`docs/bugfix/concluidos/202610/20261006-acerto-comprovante-sem-cycles-ago.md`): o devedor não conseguia enviar o comprovante do acerto de ciclo anterior (422 por falta de `cycles_ago`), o que travava a selagem de setembro. Esta feature não depende dele, mas explica por que há ciclos pendentes agora.

## 2. Requisitos

Glossário (de `01-specify.md` e do código): **credor** = quem pagou a despesa (`user_payer_id`); **devedor** = participante da despesa que não é o credor; **acerto** = transferência líquida par-a-par entre dois membros no ciclo (`settlements`), confirmada pelo devedor com comprovante.

### 2.1 Aba "Acompanhamento": ciclos encerrados aguardando quitação

Novo endpoint somente leitura `GET /api/groups/{groupId}/expenses/pending-cycles` (nome ajustável no `plan.md`).

- Lista os ciclos do grupo, dos mais recentes aos mais antigos, que **não estão selados** e estão **fechados** (por data ou manualmente, e ainda não reabertos) ou **em carência** (`open` com `end` no passado; a Home já os trata como pendentes via `focusCycle`). Varre até `FOCUS_LOOKBACK` (12) ciclos para trás, como `focusCycle()`.
- Só entra o ciclo em que ainda há algo a quitar: `totals.pending > 0` ou acerto sem confirmação. Ciclo vazio, ou só com parcela retroativa nascida quitada (`bornPaid`), não entra (mesma regra de `sealCycleIfSettled`, `ExpenseController.php:1378-1382`). Exceção: ciclo já quitado mas ainda sem selo (caso raro, ver `plan.md` §0.1) entra marcado como "Quitado", porque a listagem não pode selar.
- Nunca inclui ciclo futuro nem a competência vigente ainda aberta.
- Cada item traz: período e `closes_at`, status do ciclo, `totals` (total, pago, a pagar), quantos acertos existem e quantos já foram confirmados, e quantas despesas seguem pendentes.
- Os números vêm de `computeCycleSummary` **ao vivo** e mudam a cada pagamento ou confirmação. O endpoint **nunca grava** snapshot nem sela (é GET).
- Autorização: membro do grupo (`authorizeGroupMembership`), como os demais endpoints de ciclo.

### 2.2 Relatório de Fechamento de um ciclo

Novo endpoint somente leitura `GET /api/groups/{groupId}/expenses/closing-report?cycle_start=YYYY-MM-DD`.

- Funciona para qualquer ciclo não futuro do grupo: selado (lê o snapshot) ou não (calcula ao vivo), mesma escolha de fonte do `summary()` (`ExpenseController.php:541-572`). `cycle_start` que não corresponde a um ciclo do grupo devolve 404. O cliente não faz conta de ciclo: ele manda a data de início que a lista devolveu.
- O ciclo vigente aberto também é aceito e vem marcado como **Prévia** (o chip Prévia/Definitivo já existe em `SummarySidePanel.tsx:29`).
- Conteúdo:
  1. **Cabeçalho:** grupo, período, status (Prévia / Definitivo) e data de emissão.
  2. **Totais:** total, pago, a pagar.
  3. **Credores → devedores:** por credor, quanto adiantou no ciclo e, por devedor, quanto este lhe deve. Valor **bruto por despesa**, excluindo `bornPaid`, na mesma regra de divisão de `computeCycleSummary`/`grossDebts`.
  4. **Acertos (líquido par-a-par):** quem paga a quem e quanto, com o status de cada um (`Confirmado em dd/mm`, com comprovante, ou `Aguardando comprovante`).
  5. **Despesas do ciclo:** a lista já existente (descrição, valor, credor, participantes, Paga/Pendente).
- Bruto e líquido são coisas diferentes e a tela deve rotular as duas. Exemplo: A pagou R$ 100 e B pagou R$ 40, ambas divididas igualmente entre os dois. Bruto: B deve R$ 50 a A, e A deve R$ 20 a B. Líquido (acerto): B paga R$ 30 a A.
- A regra de divisão fica no **backend**: o cliente não recalcula rateio (`docs/sdd/05-context-frontend.md`, "Convenções fixas"). Ao vivo e snapshot passam pelo mesmo código de agregação.
- Snapshots antigos podem não ter `participantDetails`, `installmentNumber` ou `bornPaid` (campos aditivos posteriores, já opcionais em `useGroupCycle.ts`). O relatório tolera a ausência: nomes são resolvidos para ids pelos membros do grupo e `bornPaid` ausente vale `false`.
- Autorização: membro do grupo.

### 2.3 Tela de Relatórios com duas abas

- Título "Relatórios", com as abas **Acompanhamento** (§2.1) e **Histórico** (a lista atual de ciclos selados, sem mudança de regra nem de endpoint). Aba selecionada na URL (`?aba=acompanhamento|historico`), para link direto.
- Aba padrão: Acompanhamento.
- Em ambas as abas, escolher um ciclo abre o **Relatório de Fechamento** (§2.2). Hoje o Histórico abre `CycleDetailPanel`; passa a abrir o Fechamento, que inclui a lista de despesas e os acertos.
- Estados de carregamento, erro e vazio em cada aba. Vazio de Acompanhamento: "Nenhum ciclo aguardando pagamento."

### 2.4 Imprimir / salvar PDF

- O Fechamento tem o botão "Imprimir / Salvar PDF", que chama `window.print()`. O usuário escolhe "Salvar como PDF" no diálogo do navegador.
- Folha de impressão (`@media print`): esconde sidebar, header, drawer de navegação, abas e botões, e mostra só o relatório. Sem biblioteca nova de PDF, no frontend ou no backend, portanto sem ADR.
- Limitação aceita: o nome e o layout do arquivo são os do navegador, não controlados pela aplicação.

### 2.5 Compatibilidade

- Mudanças **aditivas** de API (Constitution §4.1): dois endpoints novos. `GET .../expenses/cycles` (histórico), `summary` e `focus-cycle` não mudam de contrato.
- **Sem migration.** Nenhuma tabela ou coluna nova.
- Sem efeito colateral: nenhum dos dois endpoints novos grava dados, e a regra de selagem não é tocada.

## 3. Fora de escopo desta feature

- Mudar a regra de selagem ou o filtro do `cycleHistory`: o Histórico continua sendo só de ciclos quitados.
- PDF gerado por biblioteca (jsPDF) ou pelo servidor (dompdf): decidido usar a impressão do navegador. Isso reabre de forma deliberada o item "Exportar relatório (PDF/CSV)" que o spec `docs/feature/concluidas/202608/20260827-relatorios-historico-ciclos/specify.md` §3 deixou de fora, e só para PDF por impressão. CSV continua fora.
- Pagar despesa, marcar como paga ou confirmar acerto dentro de Relatórios: essas ações continuam em Pagamentos.
- Cobrança automática ou lembretes (WhatsApp, e-mail) a partir do Acompanhamento.
- App Expo/React Native (`expense/app`): esta feature é só do `frontend/` web.
- Remover ou alterar o `GroupExpenseReportController` legado (relatório anual, sem uso pela tela).
- Filtros, busca e exportação dentro das listas.
- Corrigir ou reprocessar ciclos já selados.

## 4. Pontos a confirmar na revisão

As propostas dos pontos 1 e 3 foram aceitas com a aprovação do specify; o ponto 2 foi delegado ao plano e decidido em `plan.md` §0.1.

1. **Carência:** ciclo em carência (já passou da fronteira, ainda editável) aparece em Acompanhamento com o rótulo "Fecha em dd/mm". Decidido: sim.
2. **Ciclo quitado mas não selado:** o caso existe, é raro, e ficaria fora das duas abas. Decidido: entra no Acompanhamento com selo "Quitado" (ver §2.1). A causa raiz vira item de backlog.
3. **Ciclo vigente no Fechamento:** aceito como Prévia (§2.2).
