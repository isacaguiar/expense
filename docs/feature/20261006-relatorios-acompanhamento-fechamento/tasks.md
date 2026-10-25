# Tasks — Acompanhamento e Fechamento nos Relatórios

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261006 · Preenchido em: 20261024 (depois da aprovação de D-N1, D-N2 e D-N3 como recomendado, `plan.md` v1.2)

IDs a partir de TASK-481: o maior em qualquer lugar do projeto é TASK-480 (feature `20261023-members-index-checagem-de-membro`). Conferido em 2026-10-24 em `origin/dev` e em todas as branches locais e remotas, que só chegam a TASK-480. Reconfirmar a numeração se outra feature reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-481 | Criar `CycleSummaryService::viewAt` e fazer `view()` delegar a ele, com testes PHPUnit | backend | plan.md §2.1 (D-N3) | nenhum | Concluída (`implementation.md` §2) |
| TASK-482 | Criar `App\Support\ClosingBreakdown` (agregação credor → devedores) com testes unitários | backend | plan.md §2.2 | nenhum | Concluída (`implementation.md` §2) |
| TASK-483 | Criar `CycleSummaryService::pendingCycles` e o endpoint `GET /groups/{groupId}/expenses/pending-cycles`, com testes de feature | backend | plan.md §1 (D-N2) | nenhum | Concluída (`implementation.md` §2) |
| TASK-484 | Criar o endpoint `GET /groups/{groupId}/expenses/closing-report`, com testes de feature | backend | plan.md §2.1 e §2.3 | nenhum | Concluída (`implementation.md` §2) |
| TASK-485 | Criar o hook `useGroupPendingCycles` e o tipo `PendingCycle`, com testes | frontend | plan.md §3 | nenhum | Concluída (`implementation.md` §2) |
| TASK-486 | Criar o hook `useClosingReport` e o tipo `ClosingReport`, com testes | frontend | plan.md §3 e §4 | nenhum | Concluída (`implementation.md` §2) |
| TASK-487 | Extrair `CycleTotalsCards` de `CycleDetailPanel` sem mudar o comportamento | frontend | plan.md §4 (item 2) | nenhum | Concluída (`implementation.md` §2) |
| TASK-488 | Extrair `CycleExpenseList` de `CycleDetailPanel` e mostrar o `paymentNote` da despesa | frontend | plan.md §4 (item 5, D-N1) | nenhum | Concluída (`implementation.md` §2) |
| TASK-489 | Acrescentar `showConfirmation` a `SettlementList` (chip de confirmação e `confirmedNote`) | frontend | plan.md §4 (item 4, D-N1) | nenhum | Concluída (`implementation.md` §2) |
| TASK-490 | Criar `ClosingReportPanel` com o botão Imprimir / Salvar PDF | frontend | plan.md §4 | nenhum | Concluída (`implementation.md` §2) |
| TASK-491 | Marcar `Sidebar` e `GroupHeader` com a classe `no-print` | frontend | plan.md §4 (Imprimir) | nenhum | Concluída (`implementation.md` §2) |
| TASK-492 | Criar `PendingCyclesList` | frontend | plan.md §3 | nenhum | Concluída (`implementation.md` §2) |
| TASK-493 | Reescrever `GroupReports` com as abas Acompanhamento e Histórico e o estado na URL, ajustando os testes existentes | frontend | plan.md §3 | nenhum | Concluída (`implementation.md` §2) |
| TASK-494 | Atualizar `consultas-tela-relatorio.md` com as consultas das duas abas e do Fechamento | doc | plan.md §7 (item 8) | nenhum | Concluída (`implementation.md` §2) |
| TASK-495 | Registrar em `docs/backlog/` o achado tangencial do plan §8 (`update()`/`destroy()` não selam) | doc | plan.md §8 | nenhum | Concluída (`implementation.md` §2) |
| TASK-496 | Revisar as duas rotas novas com o `security-reviewer` e registrar os resultados | backend | plan.md §5 | nenhum | Pendente |
| TASK-497 | Validar ponta a ponta na stack isolada, medir o custo da varredura e encerrar o ambiente | infra | plan.md §6 | antes do merge | Pendente |
| TASK-498 | Rodar o checklist pré-PR na branch integrada e abrir o PR único contra `dev` | infra | plan.md §5 e §7 (item 9) | antes do merge | Pendente |

Ordem: TASK-481 → 482 → 483 → 484 → 485 → 486 → 487 → 488 → 489 → 490 → 491 → 492 → 493 → 494 → 495 → 496 → 497 → 498. Dependências reais:
- A 484 usa a 481 (`viewAt`) e a 482 (`ClosingBreakdown`). A 483 não depende da 482.
- A 485 vem depois da 483 e a 486 depois da 484, porque os tipos copiam o formato real da resposta.
- A 490 usa a 486, a 487, a 488 e a 489. A 493 usa a 490, a 491 e a 492.
- A 494 e a 496 vêm depois das duas rotas (483 e 484). A 497 vem depois da 493. A 498 é a última.
- Se a revisão ou a validação apontar defeito, ele vira task nova, não amplia a task em andamento.

O ambiente de teste (contêiner MySQL em porta livre, `backend/.env.testing` descartável, `migrate`) **não é uma task**: é pré-condição da TASK-481 e fica registrado como desvio no `implementation.md` §1, com a limpeza obrigatória no fim (TASK-497). O `.env.testing` agora é ignorado pelo git (PR #238), então o `git status` **não avisa** se ele sobrar: apagar na limpeza.

## Critérios de aceite

Em toda task de backend: `vendor/bin/pint --test` limpo e suíte inteira do backend verde, com o número real de testes. Em toda task de frontend: `npx tsc --noEmit` limpo e `npx vitest run --maxWorkers=2` verde, com o número real. Nenhuma task cria migration (specify §2.5).

- **TASK-481** (feita **direto na branch da feature**, sem sub-branch, por ser a primeira):
  - **Ambiente pronto, com evidência no `implementation.md` §1:** contêiner MySQL no ar, `backend/.env.testing` descartável (**fora de qualquer commit**), `php artisan migrate --env=testing` sem migração pendente, e a suíte existente verde **antes** de qualquer mudança.
  - `CycleSummaryService::viewAt(Group, Carbon $start)` em `CycleSummaryServiceTest`: ciclo **selado** lê o snapshot por `cycle_start` **mesmo com o `closing_day` do grupo alterado depois**; ciclo **não selado** calcula ao vivo e devolve `status` correto (`closed`, `open`, `closed_manually`) e `in_grace`; `cycle_start` **futuro** e `cycle_start` que **não é limite de ciclo** são sinalizados de forma que o controller devolva `404` (o formato exato do sinal vai para o `implementation.md`); **não grava**: a contagem de `GroupCycleSnapshot` e de notificações é a mesma antes e depois.
  - `view($group, $cyclesAgo)` passa a delegar a `viewAt`. Os testes existentes de `view()`, de `FocusCycleTest`, de `PersonalOverviewServiceTest` e de `ExpenseControllerSummaryTest` seguem verdes **sem edição**: `git diff -- backend/tests` só **acrescenta** testes novos em `CycleSummaryServiceTest`.
  - **Mutação:** fazer `viewAt` ler sempre ao vivo (ignorando o snapshot) derruba o teste do snapshot com `closing_day` alterado; reverter e reler o trecho antes de seguir.
- **TASK-482** (branch de task a partir da branch da feature):
  - `tests/Unit/ClosingBreakdownTest`: dois credores cruzados (A pagou 100, B pagou 40, divididas entre os dois: B deve 50 a A e A deve 20 a B); entrada com `bornPaid` ignorada; credor resolvido por `userPayerId` e, na falta, por `payerName`; devedor = participante ≠ credor; fatia `value / max(count(participants), 1)` somada **sem arredondar** e arredondada no fim; `advanced_total` sem `bornPaid`; ordem (credores por `advanced_total` decrescente, devedores por `amount` decrescente); snapshot antigo sem `participantDetails` resolve id e avatar pelos `members` casando por nome; participante que não casa com nenhum membro vira devedor **sem `id`** (não lança exceção); entrada vazia devolve `creditors: []`.
  - Classe **pura**: sem acesso a banco nem HTTP (os testes não estendem `TestCase` com banco). `valuePerPerson` não é usado.
  - **Mutação:** arredondar cada fatia antes de somar derruba o teste de arredondamento.
- **TASK-483** (branch de task):
  - `ExpenseControllerPendingCyclesTest` (padrão de `ExpenseControllerCycleHistoryTest`: `Carbon::setTestNow`, `DatabaseTransactions`): **exclui** ciclo selado, vigente aberto, futuro e ciclo vazio (inclusive o que só tem parcela `bornPaid`); **inclui** fechado pendente, fechado manual ativo e carência (`in_grace`); `settlements_total`, `settlements_confirmed` e `pending_expenses_count` corretos; `awaiting_seal` quando a última pendência é excluída **na janela de carência** e o ciclo depois fecha por data (caminho (a) do plan §0.1.2); ordem mais recente primeiro e no máximo 13 itens; formato `{ "data": [...] }`.
  - Segurança: sem token → `401`; usuário que **não é membro** → `404`; a primeira linha do método é `Group::findOrFail` + `authorizeGroupMembership`.
  - **Nunca grava:** nenhum `GroupCycleSnapshot` criado e nenhuma notificação disparada pela chamada (`sealCycleIfSettled` não é chamado).
  - Sentinelas verdes **sem edição**: `ExpenseControllerCycleIsFullySettledTest`, `FocusCycleTest`, `ExpenseControllerSummaryTest`, `CycleSummaryServiceTest` e `PersonalOverviewServiceTest`.
  - **Mutação:** remover o `authorizeGroupMembership` derruba o teste do não-membro; reverter e reler o trecho.
- **TASK-484** (branch de task):
  - `ExpenseControllerClosingReportTest`: ciclo selado lê do snapshot; ciclo fechado pendente calcula ao vivo; ciclo vigente aberto vem como **Prévia** (`status = open`, sem `settled`); `cycle_start` futuro ou que não é limite de ciclo → `404`; `cycle_start` ausente ou fora de `Y-m-d` → `422`; snapshot de grupo cujo `closing_day` mudou continua abrindo; snapshot antigo sem `participantDetails` e `bornPaid` abre sem erro; a resposta traz `group`, `cycle`, `totals`, `expenses`, `balances`, `settlements` e `creditors`; `paymentNote` e `confirmedNote` presentes quando existem (D-N1).
  - **Reconciliação:** para todo par (A, B), `bruto(B→A) − bruto(A→B)` iguala o `settlement` líquido, com tolerância de R$ 0,01.
  - Segurança e efeito colateral como na 483: `401`, `404` para não-membro, **não grava** e não notifica; `summary()` continua selando (o `ExpenseControllerSummaryTest` segue verde sem edição).
  - **Mutação:** chamar `sealCycleIfSettled` dentro do `closingReport` derruba o teste de "não grava"; reverter e reler.
- **TASK-485** (branch de task):
  - `hooks/useGroupPendingCycles.ts` no padrão de `useGroupCycleHistory.ts`: axios com `Authorization`, `401` → `navigate('/')`, erro amigável, `loading`. Testes cobrem sucesso, lista vazia, erro e `401`.
  - `PendingCycle` reaproveita `SummaryCycle` e `SummaryTotals` de `useGroupCycle.ts`; `cycle.in_grace` e `awaiting_seal` entram como campos **opcionais**, de modo que o tipo `Summary` continua compilando sem edição.
- **TASK-486** (branch de task):
  - `hooks/useClosingReport.ts`: só busca quando há `ciclo`; refaz ao mudar o ciclo; `404` → mensagem "Ciclo não encontrado."; `401` → `navigate('/')`. Testes cobrem sem ciclo (não chama a API), troca de ciclo, `404` e `401`.
  - `ClosingReport` reaproveita os tipos de `useGroupCycle.ts` e acrescenta `creditors`.
- **TASK-487** (branch de task):
  - `components/CycleTotalsCards.tsx` extraído de `CycleDetailPanel.tsx` (cartões de total, pago e a pagar); `CycleDetailPanel` passa a usá-lo e **renderiza o mesmo conteúdo** de antes: os testes existentes que passam por ele (`GroupReports.test.tsx` e `GroupSummary.test.tsx`, as duas telas que o importam) seguem verdes **sem edição**. Teste novo do componente (valores formatados em pt-BR).
- **TASK-488** (branch de task):
  - `components/CycleExpenseList.tsx` extraído de `CycleDetailPanel.tsx` e usado nos dois lugares, sem duplicar. Mostra o `paymentNote` quando houver, como **texto puro** (`grep -rn dangerouslySetInnerHTML frontend/src` vazio); snapshot antigo sem o campo (`null` ou ausente) não renderiza nada extra. Testes: com nota, sem nota e com `null`.
- **TASK-489** (branch de task):
  - `SettlementList` ganha a prop opcional `showConfirmation` (default `false`). **Regressão:** sem a prop, a saída é a de hoje. O único uso atual é `SummarySidePanel`, que não tem teste próprio e é coberto por `ExpenseManager.test.tsx` e `GroupSummary.test.tsx` (via `CycleDetailPanel`): os dois seguem verdes **sem edição**, e um teste novo de `SettlementList` sem a prop trava a saída atual. Com a prop: chip "Confirmado em dd/mm" quando há `confirmedAt` e "Aguardando comprovante" quando não há; `confirmedNote` aparece como texto quando houver. Testes dos três casos.
- **TASK-490** (branch de task):
  - `components/ClosingReportPanel.tsx`, nesta ordem: cabeçalho (grupo, período, chip **Prévia** / **Aguardando pagamentos** / **Quitado**, "Emitido em dd/mm/aaaa" e o botão **Imprimir / Salvar PDF**), cartões de totais (`CycleTotalsCards`), "Valores por despesa (bruto)" (credores → devedores, "Adiantou R$ X" e "Fulano deve R$ Y"), "Acertos (líquido entre pares)" com a legenda de uma linha sobre bruto × líquido (`SettlementList showConfirmation`) e a lista de despesas (`CycleExpenseList`).
  - Clique em Imprimir chama `window.print` (espião no teste). O painel monta um `GlobalStyles` com `@media print` (`.no-print { display: none !important; }`, `body` branco, `@page` com margem de 12 mm) enquanto está na tela; o botão tem a classe `no-print`.
  - Layout com `Card` e `Stack`, sem `Table`, e strings em pt-BR. Testes: ordem das seções, cada chip de status, credores → devedores, cada tipo de acerto, estado de erro `404`, clique em Imprimir e a classe `no-print`.
- **TASK-491** (branch de task):
  - `layouts/Sidebar.tsx` (`component="nav"`, `:17`) e `layouts/group/GroupHeader.tsx` (`component="header"`, `:46`) ganham `className="no-print"` e **mais nada**. Testes existentes dos layouts verdes sem edição; um teste novo confirma a classe nos dois elementos. `git diff` dos dois arquivos mostra só o `className`.
- **TASK-492** (branch de task):
  - `components/PendingCyclesList.tsx`: um cartão por ciclo com o período, o chip de status (`cycleStatusChip[status]`; carência = "Fecha em dd/mm" com `color: 'info'`; `awaiting_seal` = "Quitado" com `color: 'success'`), barra de progresso `pago / total`, "Acertos confirmados X de Y" (**omitido quando Y = 0**), "X despesas pendentes" e o botão "Ver fechamento", que chama o callback com o `cycle.start`. Estados de carregando, erro e vazio ("Nenhum ciclo aguardando pagamento."). Testes de cada chip, do progresso, da omissão com Y = 0, do clique, do vazio e do erro.
- **TASK-493** (branch de task):
  - `pages/GroupReports.tsx` com o título "Relatórios" e `Tabs` MUI **Acompanhamento** | **Histórico**; `?aba=acompanhamento|historico` (ausente ou inválida = acompanhamento) e `?ciclo=YYYY-MM-DD` abre o `ClosingReportPanel`; trocar de aba limpa `ciclo`. O Histórico mantém a lista paginada atual, e clicar num item abre o Fechamento no lugar do `CycleDetailPanel`. Abas e lista de ciclos com `no-print`.
  - **`GroupReports.test.tsx` ajustado:** os testes do histórico entram com `initialEntries={['/?aba=historico']}` e o de detalhe passa a esperar o `GET closing-report`. Testes novos: aba padrão, `?aba` inválida, `?ciclo`, limpeza do `ciclo` ao trocar de aba.
  - `CycleDetailPanel` continua existindo para a Home do grupo (não é removido).
- **TASK-494** (branch de task):
  - `consultas-tela-relatorio.md` ganha uma seção por endpoint novo (`pending-cycles` e `closing-report`) com a cadeia de chamadas e as consultas, no formato das seções 1 a 3. As consultas vêm da **execução real** (`DB::listen` ou `toSql()` no banco de teste), não de memória; a seção diz isso. `git diff` só toca esse arquivo.
- **TASK-495** (branch de task):
  - Um arquivo em `docs/backlog/` no formato do `README.md`, com o ID sequencial livre **conferido na hora** em `origin/dev` e nas branches (hoje o próximo livre é 102): `update()`/`destroy()` de despesa e a edição de despesas `FIXED` não selam o ciclo; Origem, Criado em, Prioridade BAIXA, Status, Descrição, a consulta para medir em produção (ciclos fechados sem `settled_at` cujo `cycleIsFullySettled` é verdadeiro; o `awaiting_seal` do Acompanhamento já os revela) e a referência ao item 093. Índice de `docs/backlog/README.md` atualizado.
- **TASK-496** (branch de task):
  - Agent `security-reviewer` rodado sobre o diff, confirmando ou refutando: (a) as duas rotas estão dentro de `jwt.auth`; (b) a primeira linha de cada método é `findOrFail` + `authorizeGroupMembership`; (c) nenhum dado de outro grupo aparece nas respostas; (d) nada é gravado. Achados registrados **como vieram**, com a minha verificação de cada um e a decisão tomada com o usuário.
- **TASK-497** (branch de task; **gate humano antes do merge**):
  - Stack isolada (MySQL em porta livre, backend e Vite em portas livres) com um grupo semeado com 12 ciclos: um selado, um fechado pendente com acertos sem comprovante, um em carência e um quitado sem selo. Conferido no navegador: as duas abas, o Fechamento de cada tipo, a Prévia do ciclo vigente e o Ctrl+P (sidebar, cabeçalho, abas e botões somem na folha de impressão; a classe é coberta por teste, o resultado visual só se vê na impressão real). Console sem erros.
  - **Custo medido** da varredura do Acompanhamento com os 12 ciclos (número de consultas e tempo), registrado no `implementation.md`; se passar do aceitável, vira item de backlog, não amplia esta task.
  - **O app fica aberto e logado no Chrome do usuário até ele confirmar por escrito que validou.** Só depois: parar backend e Vite, remover o contêiner, **apagar `backend/.env.testing`** (o `git status` não avisa) e conferir `git status` limpo.
- **TASK-498** (branch de task; **gate humano antes do merge**):
  - Agent `pr-readiness-checker` rodado na **branch da feature já integrada**. Comandos reais e resultados no `implementation.md`: `vendor/bin/pint --test`, `php artisan test`, `npx tsc --noEmit`, `npx vitest run --maxWorkers=2`, `git diff origin/dev --stat` (sem segredo, sem migration, sem arquivo fora de Relatórios) e `implementation.md` com uma linha por task.
  - PR único da branch da feature contra `dev`, com a descrição listando as tasks 481 a 498 e referenciando `specify.md` e `plan.md`. **O PR não é aberto nem enviado sem a confirmação escrita do usuário da TASK-497.** Merge em `dev` é gate humano, sem auto-merge. Depois do merge, a pasta vai para `docs/feature/concluidas/202610/` (ADR-009), em PR de docs à parte.
