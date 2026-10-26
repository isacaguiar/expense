# Tasks — Organização de Despesas e Pagamentos

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.1 · Criado em: 20261026 · Revisado em: 20261026 (TASK-500: critério ajustado à contagem real de cópias)

IDs a partir de TASK-499: o maior em qualquer branch do repositório e em `docs/` é TASK-498 (feature `20261006-relatorios-acompanhamento-fechamento`), conferido em 2026-10-26 com `grep` nos documentos e nas mensagens de commit de todas as branches. Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

Escopo: **só `frontend/` e documentação**. Nenhuma task toca `backend/` (specify R13, D1 = A).

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-499 | Criar o utilitário puro `cycleOrdering.ts` (ordem de despesas, saldos e acertos, critério `confirmedAt`) com testes unitários | frontend | plan.md §1 | nenhum | Concluída (2026-10-26) |
| TASK-500 | Extrair `formatShortDate` para `utils/dates.ts` e substituir as quatro cópias locais | frontend | plan.md §2 | nenhum | Concluída (2026-10-26) |
| TASK-501 | Criar o hook `useCurrentUserId` e adotá-lo em `ExpenseManager` e `Payments` | frontend | plan.md §3 | nenhum | Concluída (2026-10-26) |
| TASK-502 | Extrair a interface de filtros de `ExpenseManager` para `useExpenseFilters` e `ExpenseFilterBar`, sem alterar os testes existentes | frontend | plan.md §4 | nenhum | Concluída (2026-10-26) |
| TASK-503 | Ordenar as despesas de Despesas por data da ocorrência e mostrar a data em cards e tabela | frontend | plan.md §5 | nenhum | Concluída (2026-10-26) |
| TASK-504 | Mostrar o usuário logado primeiro na aba Saldo, em Despesas e na Home do grupo | frontend | plan.md §6 | nenhum | Pendente |
| TASK-505 | Ordenar as despesas da Home do grupo e do Relatório de fechamento por data da ocorrência | frontend | plan.md §5 | nenhum | Pendente |
| TASK-506 | Ajustar `PayableSettlementList`: critério `confirmedAt`, chip sem link quebrado, rótulos de valor a receber e mensagem de vazio | frontend | plan.md §7 | nenhum | Pendente |
| TASK-507 | Ordenar as despesas de Pagamentos por data da ocorrência e mostrar a data nos cards | frontend | plan.md §5 | nenhum | Pendente |
| TASK-508 | Acrescentar os filtros de Despesas a Pagamentos, afetando só a lista de despesas | frontend | plan.md §4 | nenhum | Pendente |
| TASK-509 | Criar `PaymentSettlementsPanel` com as abas "Valores a pagar" e "Valores pagos" e a ordem dos acertos, e integrá-lo em Pagamentos | frontend | plan.md §8 | nenhum | Pendente |
| TASK-510 | Registrar a convenção de ordem de apresentação em `05-context-frontend.md` e `07-context-mobile.md` | doc | plan.md §9 | nenhum | Pendente |
| TASK-511 | Validar a feature localmente na stack isolada e registrar o resultado | frontend | plan.md §10 | antes do merge (o usuário valida localmente antes do push e do PR) | Pendente |

## Dependências e ordem

Ordem de execução: **499 → 500 → 501 → 502 → 503 → 504 → 505 → 506 → 507 → 508 → 509 → 510 → 511** (plan.md §12). Critério: primeiro o que não depende de nada e destrava o resto (499–501), depois a refatoração protegida por testes existentes (502), depois as telas na ordem Despesas → Home → Pagamentos.

| Task | Depende de | Por quê |
|---|---|---|
| TASK-499 | — | Base dos demais |
| TASK-500 | — | Independente |
| TASK-501 | — | Independente |
| TASK-502 | — | Refatoração pura de `ExpenseManager` |
| TASK-503 | 499, 500, 502 | Usa `sortExpensesByOccurrence`, `formatShortDate` e entrega a lista já ordenada ao `useExpenseFilters` |
| TASK-504 | 499, 501 | Usa `sortBalancesForViewer` e o hook de usuário logado (`GroupSummary` passa a usá-lo) |
| TASK-505 | 499 | Usa `sortExpensesByOccurrence` |
| TASK-506 | 499 | Usa `isSettlementConfirmed` |
| TASK-507 | 499, 500, 501 | Mesmo papel de TASK-503, em `Payments` |
| TASK-508 | 502, 507 | Reaproveita a barra extraída e recebe a lista ordenada |
| TASK-509 | 499, 506, 508 | Usa as funções de acerto e o `PayableSettlementList` ajustado; edita o mesmo `Payments.tsx` |
| TASK-510 | 499 | Documenta o que o utilitário faz; escrita por último para citar o que de fato foi entregue |
| TASK-511 | todas | Roda sobre a branch da feature com tudo integrado |

Não há paralelismo útil com um executor só: 502/503 e 507/508/509 editam os mesmos arquivos.

Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: a branch da feature `feature/20261026-organizacao-despesas-pagamentos` já existe localmente, criada de `dev`, com os documentos do SDD ainda não commitados. TASK-499 roda **direto nela**. Antes de começar, conferir `git fetch origin` e, se `origin/dev` tiver avançado, integrar `dev` na branch da feature (`04-implementation.md` §1 item 1 manda atualizar `dev` antes de criar a branch, o que ainda não foi feito). As demais tasks nascem em `feature/20261026-organizacao-despesas-pagamentos-TASK-0xx`, integradas na branch da feature com `git merge --no-ff`, sem PR. O commit dos documentos do SDD e de cada task só acontece com autorização do usuário. No fim, **um único PR** contra `dev`, depois da TASK-511.

Cada task, antes de integrar: `cd frontend && npx vitest run <arquivos tocados>`, `npm test` e `npx tsc --noEmit`, com comando e resultado reais no `implementation.md` (`04-implementation.md` §1 item 3). Nenhum critério abaixo repete isso por extenso, exceto onde o critério o torna específico.

## Critérios de aceite

- **TASK-499**:
  - `cd frontend && npx vitest run src/utils/cycleOrdering.test.ts` verde, com casos que provam:
    - (a) despesas: o exemplo do specify §5.1 sai **Mercado, Internet, Sofá, Luz**; empate de `date` decidido por `id` decrescente; empate de `id` decidido por `installmentNumber` decrescente, tratando ausente como 0; lista vazia; datas de meses diferentes; a entrada **não é mutada** (o teste passa um array congelado com `Object.freeze`).
    - (b) saldos: com os saldos do §5.2, logada **Carla** dá Carla, Ana, Bruno, Diego, Eva, Fábio e logada **Ana** dá Ana, Bruno, Diego, Carla, Eva, Fábio; o empate Ana/Bruno em +90 se resolve por nome em `pt-BR` (caso extra com "Álvaro" antes de "Bruno"); `0` e `-0` empatam e caem no nome; logada ausente da lista e `viewerId` `null` não geram erro e usam só a regra dos demais.
    - (c) `isSettlementConfirmed`: `true` com `confirmedAt` preenchido, **independente** de `confirmedProofUrl`; `false` com `null`, `undefined` e string vazia, mesmo com `confirmedProofUrl` preenchido.
    - (d) `splitSettlementsByConfirmation`: separa por `confirmedAt`, preserva a ordem de entrada, devolve duas listas vazias para `null` e `undefined`, e `toPay.length + paid.length` e a soma dos valores batem com a entrada.
    - (e) `orderSettlementsForViewer`, com a logada **Carla**: a lista "a pagar" do §5.3 sai exatamente Carla→Ana 60,00 · Diego→Ana 90,00 · Diego→Carla 40,00 · Eva→Bruno 70,00, e a "pagos" sai Carla→Bruno 25,00 · Eva→Ana 30,00 · Eva→Carla 20,00 · Bruno→Diego 10,00; o total de Eva é 70,00 numa lista e 50,00 na outra; empates por nome e por id; viewer sem saídas; viewer `null`.
  - `npx tsc --noEmit` sem erro; o arquivo não importa React nem usa `any`.
  - Nenhum outro arquivo de `frontend/src/` foi alterado nesta task.
- **TASK-500**:
  - `cd frontend && npx vitest run src/utils/dates.test.ts` verde: `formatShortDate('2026-10-03')` casa com `03 … out`, e os casos de virada de mês e de ano provam que o dia **não recua** no fuso da suíte (`America/Sao_Paulo`, fixado em `vite.config.js:46`); com a versão ingênua `new Date(string)` o teste falha (`02 de out.`).
  - As quatro cópias planejadas (`GroupSummary.tsx`, `CycleExpenseList.tsx`, `Payments.tsx`, `formatCycleBoundary` em `ExpenseManager.tsx`) importam `formatShortDate`; `grep -rn "month: 'short'" frontend/src` encontra só `utils/dates.ts` e as **cinco cópias fora do escopo** (`CycleTotalsCards`, `GroupGrossDebtsPanel`, `MyItemsList`, `PendingCyclesList`, `GroupReports`, ver plan.md §2). O `formatDate` de `dd/mm/aaaa` do `ExpenseManager` continua local.
  - `npm test` (suíte completa) verde **sem edição** de nenhum teste existente: o comportamento é idêntico.
- **TASK-501**:
  - `cd frontend && npx vitest run src/hooks/useCurrentUserId.test.tsx` verde: `id` numérico é devolvido; resposta sem `id` dá `null`; erro de rede dá `null` e chama `console.error('Erro ao carregar usuário autenticado:', …)`.
  - `ExpenseManager.test.tsx` e `Payments.test.tsx` passam **sem edição**; no `git diff` dessas duas páginas não sobra o `useState<number | null>` de usuário nem o `useEffect` de `GET /api/me`.
  - `GroupSummary` ainda não usa o hook (é a TASK-504).
- **TASK-502**:
  - `ExpenseManager.test.tsx` fica verde **sem nenhuma edição** (`git diff -- frontend/src/pages/ExpenseManager.test.tsx` vazio), incluindo todo o `describe` "filtros de Credor, Devedores e Status".
  - `cd frontend && npx vitest run src/hooks/useExpenseFilters.test.tsx` verde (novo): `filteredExpenses` preserva a ordem de entrada; a seleção de credor e de devedor é podada quando a pessoa não existe mais nas despesas recebidas; os cinco critérios combinam com E entre filtros e OU dentro de Credor e Devedores.
  - No `git diff` de `ExpenseManager.tsx` não sobram os cinco `useState` de filtro, os quatro `useMemo` de opções e seleção, `AUTOCOMPLETE_TEXTS`, `renderPersonFilter` nem o bloco da barra; a página usa `useExpenseFilters` e `<ExpenseFilterBar>`. Os `aria-label` "Tipo" e "Status" e os textos em português do `Autocomplete` seguem iguais.
  - `Payments.tsx` ainda não foi alterado.
- **TASK-503**:
  - `cd frontend && npx vitest run src/pages/ExpenseManager.test.tsx` verde. Único teste existente editado: o de `:176`, que passa a abrir o detalhe pelo card/linha do "Aluguel" em vez de `[0]`. Todo o `describe` de filtros segue **sem edição**.
  - Casos novos: (a) cards (viewport `< sm`) e tabela mostram a ordem de `date` decrescente; (b) empate de `date` resolvido por `id` decrescente; (c) despesa pendente não sobe nem desce por estar pendente; (d) um `summary` chegando em ordem **crescente** (como um ciclo selado) é exibido em ordem decrescente; (e) a ordem é a mesma com um filtro ativo; (f) a tabela tem a coluna **"Data"** como primeira e cada card tem a linha "Data:" com o dia e o mês abreviado; (g) duas parcelas da mesma despesa (mesmo `id`, `installmentNumber` diferente) renderizam sem aviso de chave duplicada (`console.error` espionado); (h) o array `summary.expenses` recebido não é mutado (fixture congelada).
  - Nenhuma chamada nova à API (mesma contagem de GETs dos testes atuais).
- **TASK-504**:
  - `cd frontend && npx vitest run src/components/SummarySidePanel.test.tsx` verde (arquivo novo; o painel não tinha teste próprio): a aba Saldo abre com o logado primeiro nas duas variantes de logada do §5.2; `currentUserId` `null` e ausente usam só a regra dos demais; os textos "a receber" / "a pagar" e o valor de cada card seguem iguais; a aba "À pagar" continua listando os acertos na ordem recebida (D10 = A).
  - `GroupSummary.test.tsx`: com `GET /api/me` devolvendo `id`, o Saldo da Home abre com o logado primeiro; sem `id` na resposta (como o mock atual), a ordem cai na regra dos demais e os testes existentes passam sem edição. Há exatamente um `GET /api/me` a mais.
  - `CycleDetailPanel.test.tsx`: a prop `currentUserId` chega ao `SummarySidePanel`; sem ela, o componente renderiza como antes.
  - `ExpenseManager.test.tsx`: o Saldo da aba de Despesas abre com o logado primeiro.
  - O `BalanceCards` não foi alterado.
- **TASK-505**:
  - `CycleDetailPanel.test.tsx` e `ClosingReportPanel.test.tsx` verdes: com despesas chegando em ordem crescente, a lista "Despesas do ciclo" sai em `date` decrescente, com empate por `id`.
  - `GroupSummary.test.tsx`: a Home mostra as despesas em ordem decrescente.
  - `CycleExpenseList.test.tsx` passa **sem edição**, inclusive "keeps the order it received": o componente continua só renderizando, e a ordenação é de quem o chama.
  - `MyItemsList`, `GroupPositionCard` e `MyPanel` não foram alterados (D3 = B).
- **TASK-506**:
  - `cd frontend && npx vitest run src/components/PayableSettlementList.test.tsx` verde, com os 7 casos de hoje intactos e casos novos: (a) `confirmedAt` preenchido e `confirmedProofUrl` nulo mostra "Reenviar comprovante" (quando o devedor pode confirmar) e o chip "Comprovante enviado" **sem** `href`; (b) `confirmedProofUrl` preenchido e `confirmedAt` nulo mostra "Enviar comprovante" e nenhum chip; (c) "Deve pagar a você" e "Pagou a você" aparecem só quando `to_user_id === currentUserId`, como **texto**, e nunca ao devedor; (d) a prop `emptyMessage` substitui o texto de lista vazia, e sem ela o texto atual permanece; (e) a lista respeita a ordem recebida.
  - `GroupPositionCard.test.tsx` e `MyPanel.test.tsx` passam **sem edição** (Painel inalterado; D6 = A).
  - `Payments.test.tsx` passa sem edição.
- **TASK-507**:
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx` verde, com os testes existentes **sem edição** e casos novos: (a) os cards de despesas saem em `date` decrescente; (b) empate de `date` por `id` decrescente; (c) cada card tem a linha "Data:"; (d) um `summary` em ordem crescente é exibido decrescente; (e) duas parcelas da mesma despesa renderizam sem aviso de chave duplicada; (f) `summary.expenses` não é mutado.
  - `Payments.tsx` usa `useCurrentUserId` e `formatShortDate` das tasks anteriores; `usePaymentActions` continua recebendo o `summary` inteiro.
- **TASK-508**:
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx` verde, com casos novos: (a) a barra tem busca, Credor, Devedores e os grupos "Tipo" e "Status", com os mesmos rótulos de Despesas; (b) Credor único e múltiplo (OU); Devedores incluindo o credor; Status Pagas, Pendentes e Todas; combinação E com a busca; (c) competência sem `participantDetails` filtra Devedores por nome; (d) trocar de competência com uma pessoa selecionada que não existe na outra tira o chip e não restringe a lista; (e) **filtrar não dispara nenhuma chamada nova** a `focus-cycle`, `expenses/summary` nem `members` (contagem de GETs igual antes e depois); (f) dois estados vazios com os textos "Nenhuma despesa nesta competência." e "Nenhuma despesa encontrada para esse filtro."; (g) **com filtro ativo**, o painel "Valores a pagar", os saldos e os acertos mostram exatamente os mesmos dados que sem filtro; (h) "Editar comprovante" abre e salva com filtro ativo, e "Desfazer pagamento" com o filtro "Pagas" tira a despesa da lista filtrada sem erro.
  - `ExpenseManager.test.tsx` segue sem edição.
  - A barra é o mesmo `ExpenseFilterBar`, não uma cópia; `grep -rn "AUTOCOMPLETE_TEXTS" frontend/src` encontra uma única definição.
- **TASK-509**:
  - `cd frontend && npx vitest run src/components/PaymentSettlementsPanel.test.tsx` verde (arquivo novo): (a) as duas listas do §5.3, **exatamente nessa ordem**, por aba; (b) rótulos "Valores a pagar (4)" e "Valores pagos (4)" com o contador; (c) `role="tablist"`, `role="tab"` com `aria-selected` e `role="tabpanel"` com `aria-labelledby`; trocar de aba por clique e por teclado (seta); (d) partição por `confirmedAt` nos quatro cruzamentos (`confirmedAt` só, URL só, os dois, nenhum), sem usar mais nada; (e) vazio por aba ("Nenhum valor a pagar neste ciclo." / "Nenhum valor pago neste ciclo ainda.") e, sem nenhum acerto, o texto "Ninguém deve nada a ninguém neste ciclo." nas duas abas; (f) `settlements` `undefined` e `null` não geram erro; (g) rótulos de valor a receber; (h) Pix e "Enviar/Reenviar comprovante" só para o devedor com `canConfirm`.
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx` verde, com casos novos: (a) abre em "Valores a pagar"; (b) uma despesa `paid = true` com acerto sem `confirmedAt` aparece em "a pagar", e uma despesa pendente com acerto confirmado aparece em "pagos"; (c) a aba escolhida **persiste** ao navegar para outro ciclo e ao recarregar o resumo; (d) depois do `POST settlements/confirm` bem-sucedido e do novo `summary` com `confirmedAt`, o acerto sai de "a pagar" e aparece em "pagos"; (e) Pix e envio de comprovante fazem os mesmos pedidos de hoje (URL, corpo e `cycles_ago`); (f) ciclo selado mostra as duas abas.
  - Os testes existentes de Pagamentos (lista a pagar com Pix, comprovante do devedor) seguem verdes. Só se editam para clicar em "Valores pagos" onde o acerto já confirmado agora mora noutra aba, e cada edição fica listada no `implementation.md`.
  - No `git diff` de `Payments.tsx`, o título "Valores a pagar" e o `PayableSettlementList` direto deram lugar ao painel; `canConfirm`, `handleSelectSettlement` e `openConfirmSettlementDialog` não mudaram; a aba escolhida é `useState` de `Payments`, acima do `loading`.
- **TASK-510**:
  - `docs/sdd/05-context-frontend.md` e `docs/sdd/07-context-mobile.md` ganham, cada um, uma linha em "Convenções fixas" dizendo que ordem e agrupamento de apresentação (despesas, saldos, acertos) vivem em `frontend/src/utils/cycleOrdering.ts` e seguem as regras R1, R5 e R8–R10 do specify desta feature, e que isso é apresentação, não cálculo.
  - Os caminhos citados existem (conferidos com `ls`); `git diff --stat -- docs/` lista só esses dois arquivos e a pasta da feature. `01-specify.md`, a Constitution, `03-tasks.md` e os docs arquivados não foram alterados.
- **TASK-511**:
  - Roteiro do `plan.md` §10 executado na stack isolada: grupo com ao menos quatro membros; despesas dos três tipos, com datas repetidas e uma parcela retroativa; ciclo aberto, ciclo fechado com acertos confirmados e não confirmados, e ciclo selado; ver a tela como **devedor**, como **credor** (rótulos "deve pagar a você" / "pagou a você") e como membro sem acertos; Saldo na Home e em Despesas; cada filtro sozinho e combinados em Pagamentos; navegar de ciclo com filtro e aba ativos; enviar um comprovante e ver o acerto migrar para "Valores pagos"; largura de 375 px sem scroll horizontal; abas operadas por teclado; modo cartões e modo tabela em Despesas.
  - Resultado de cada item do roteiro registrado no `implementation.md`, com o que foi observado e não só "ok".
  - `cd frontend && npm test` (suíte completa) e `npx tsc --noEmit` verdes na branch da feature já integrada; `git diff --stat -- backend/` vazio.
  - O agente `pr-readiness-checker` rodado antes do PR, com o resultado no `implementation.md`.
  - Stack isolada encerrada ao final (processos e banco de validação derrubados, portas liberadas).
  - O usuário confirma por escrito que validou localmente. Sem essa confirmação, **não há push nem PR**.
