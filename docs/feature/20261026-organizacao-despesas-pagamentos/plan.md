# Plan — Organização de Despesas e Pagamentos

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261026

---

Escopo técnico: **só `frontend/` e documentação**. Nenhuma rota, controller, serviço, migration, contrato de API ou snapshot muda (specify R13, D1 = A); a conferência é `git diff --stat -- backend/` vazio. O `summary` já traz todos os campos necessários (`date`, `id`, `installmentNumber`, `confirmedAt`, `balances[].user_id`). Convenções que valem (`docs/sdd/05-context-frontend.md`): TypeScript estrito, sem `any` não justificado, MUI, `DespesasThemeScope`.

**Conformidade com "o cliente não duplica regra de negócio"** (`05-context-frontend.md` "Convenções fixas"; Constitution §1.1). Ordenar e repartir uma lista por campos que o backend já decidiu é apresentação, não regra de negócio: nenhum valor é calculado, arredondado ou liquidado no cliente. A única soma feita é o total do devedor na aba (R10), e ela serve de **chave de ordenação**, nunca é exibida (D8 = A, sem subtotal). O §9 acrescenta essa exceção por escrito às convenções, para o app Flutter saber que as regras de ordem do specify são dele também.

## 1. Utilitário puro de ordem e agrupamento (specify R1–R5, R8–R10, R12, R14; D1, D2, D4, D7)

Novo `frontend/src/utils/cycleOrdering.ts`, no padrão de `utils/expenseFilters.ts`: funções puras, sem React, importando só tipos de `hooks/useGroupCycle`. **Nunca muta o argumento** (`[...lista].sort(...)`): `summary.expenses` é estado do hook e o `sort` nativo reordena no lugar.

| Função | Contrato |
|---|---|
| `sortExpensesByOccurrence(expenses)` | `date` decrescente (comparação de string `YYYY-MM-DD`, sem `Date` nem locale), depois `id` decrescente (D2 = A), depois `installmentNumber ?? 0` decrescente. O terceiro critério existe porque `id` é o da **despesa**: duas parcelas da mesma despesa vencendo na mesma competência repetem `id` (specify §2.2). |
| `sortBalancesForViewer(balances, viewerId)` | Se `viewerId` bate com algum `user_id`, esse saldo vem primeiro. Os demais: saldo em **centavos** decrescente (`Math.round(balance * 100) \|\| 0`, o `\|\| 0` normaliza `-0`), depois `name.localeCompare(other, 'pt-BR')` (o mesmo critério de `expenseFilters.ts`), depois `user_id` crescente. `viewerId` nulo, indefinido ou ausente da lista → só a segunda regra (R14). |
| `isSettlementConfirmed(settlement)` | `Boolean(settlement.confirmedAt)`. Único lugar que define "pago" (R8); o mesmo critério já usado por `SettlementList`, `CycleClosingAlert` e `ClosingReportPanel`. |
| `splitSettlementsByConfirmation(settlements)` | `{ toPay, paid }` preservando a ordem de entrada. Aceita `null`/`undefined` e devolve duas listas vazias (R12). `toPay.length + paid.length === settlements.length` (R9). |
| `orderSettlementsForViewer(settlements, viewerId, nameFor)` | R10. Recebe **a lista de uma aba**. Bloco 1: `from_user_id === viewerId`, por centavos decrescente, `nameFor(to_user_id)` e `to_user_id`. Bloco 2: o resto, por **total do devedor na lista recebida** (soma de centavos por `from_user_id`) decrescente, depois `nameFor(from_user_id)` e `from_user_id`; dentro do mesmo devedor, centavos decrescente, `nameFor(to_user_id)`, `to_user_id`. Valores a receber (`to_user_id === viewerId`) ficam no bloco 2, dentro do grupo do devedor (D7 = A). `nameFor` vem de quem chama (o componente já monta o mapa de `balances`). |

Como a função recebe uma aba por vez, "total da respectiva aba" sai de graça: o total de Eva é 70,00 na lista "a pagar" e 50,00 na lista "pagos" (specify §5.3). Todas as comparações de dinheiro usam inteiros em centavos, para `-0,00` e arredondamento de ponto flutuante não mexerem na ordem.

**Testes** — `utils/cycleOrdering.test.ts` (novo), uma fixture por exemplo do specify:

- Despesas: o §5.1 (Mercado, Internet, Sofá, Luz); empate de data decidido por `id`; empate de `id` decidido por `installmentNumber` (e com ele ausente); não muta a entrada; lista vazia; datas de meses diferentes.
- Saldos: o §5.2 com Carla (negativa) e com Ana (positiva) como logadas; empate +90 decidido por nome ("Álvaro" antes de "Bruno", provando `pt-BR`); `-0` e `0` empatam e caem no nome; logado ausente; `viewerId` nulo.
- Acertos: partição por `confirmedAt` (com URL, sem URL, URL sem `confirmedAt`, `null`, `undefined`, string vazia); as duas listas ordenadas do §5.3, exatamente; total do devedor muda com a lista; empates por nome e por id; viewer sem saídas; viewer nulo.

## 2. Data curta compartilhada (specify §2.3, D11)

Quatro cópias idênticas de "dia + mês abreviado" existem hoje: `GroupSummary.tsx:13`, `CycleExpenseList.tsx:11`, `Payments.tsx:32` e `formatCycleBoundary` em `ExpenseManager.tsx:88`. Novo `frontend/src/utils/dates.ts` exporta `formatShortDate(dateStr)` com o mesmo corpo e o mesmo comentário sobre o deslocamento de fuso de `new Date('YYYY-MM-DD')`. As quatro cópias passam a importá-lo. O `formatDate` de `dd/mm/aaaa` do `ExpenseManager` (usado no detalhe) fica como está.

**Testes** — `utils/dates.test.ts`: `2026-10-03` contém `03` e `out` (sem fixar a pontuação do ICU), e um caso em fuso negativo que prova que o dia não recua (o teste fixa `TZ` ou compara com o construtor por componentes).

## 3. Usuário logado como hook (specify §2.5, D5, R14)

Novo `frontend/src/hooks/useCurrentUserId.ts`: estado `number | null`, um `GET /api/me` ao montar, `id` só se for `number` (a resposta de `GroupSummary.test.tsx` não traz `id`), `console.error('Erro ao carregar usuário autenticado:', err)` no erro, igual às duas cópias de hoje.

- **Substitui** o `useState` + `useEffect` de `ExpenseManager.tsx:123-131` e de `Payments.tsx:46-54`. A URL e o formato são os mesmos, então os testes dessas páginas continuam passando sem alteração.
- **Novo consumidor**: `GroupSummary` chama o hook e passa `currentUserId` a `CycleDetailPanel`, que repassa a `SummarySidePanel` (§6).
- **Custo**: +1 `GET /api/me` ao abrir a Home do grupo. `GroupShellLayout.tsx:38` já faz essa chamada, mas guarda só `name` e `avatar_url`, e as páginas são testadas sem o layout. Alternativa descartada: expor o `id` por contexto do layout; obrigaria Despesas e Pagamentos a depender do layout em teste e não elimina a chamada que elas já fazem.

**Testes** — `hooks/useCurrentUserId.test.tsx` (novo): `id` numérico; resposta sem `id` → `null`; erro de rede → `null` e `console.error`.

## 4. Filtros compartilhados (specify §2.7, §3.3, §3.4, R3, R6, R7)

A lógica pura (`utils/expenseFilters.ts`) **não muda**. Sai de `ExpenseManager.tsx` a parte React, em dois arquivos novos:

- `frontend/src/hooks/useExpenseFilters.ts` — `useExpenseFilters(expenses)`, onde `expenses` **já vem ordenada** (§5). Guarda os cinco estados (`search`, `typeFilter`, `statusFilter`, `creditorKeys`, `debtorKeys`), deriva `creditorOptions`, `debtorOptions`, `selectedCreditors` e `selectedDebtors` com `useMemo` (a poda da seleção ao trocar de competência continua derivada na renderização, não guardada, como em `ExpenseManager.tsx:141-153`) e devolve `filteredExpenses = filterExpenses(...)`. Como `filterExpenses` preserva a ordem de entrada, ordenar antes de filtrar basta (R3).
- `frontend/src/components/ExpenseFilterBar.tsx` — presentacional. Recebe o objeto do hook e desenha exatamente a barra de hoje: `TextField` "Buscar despesa" com o ícone, `Autocomplete` Credor e Devedores (`limitTags={1}`, `disableCloseOnSelect`, largura `{ xs: '100%', sm: 240 }`), `ToggleButtonGroup` com `aria-label` "Tipo" e "Status". Leva consigo `AUTOCOMPLETE_TEXTS` (`ExpenseManager.tsx:71-76`) e `renderPersonFilter` (`:433-453`). O invólucro `Box display="flex" gap={2} mb={3} flexWrap="wrap" alignItems="center"` vai junto.

`ExpenseManager.tsx` passa a usar os dois e perde os cinco `useState`, os quatro `useMemo`, `AUTOCOMPLETE_TEXTS`, `renderPersonFilter` e o bloco `:669-704`. **A refatoração não pode alterar nenhum teste de `ExpenseManager.test.tsx`**: os casos de "filtros de Credor, Devedores e Status" (`:349-612`) são a rede de segurança da extração, e a task só termina com eles verdes sem edição.

`Payments.tsx` ganha a mesma barra sob o título "Despesas do ciclo". Regras específicas:

- O `usePaymentActions(currentUserId, summary, …)` continua recebendo o `summary` **inteiro**; `canPay`/`canUnpay`/`canEditProof` não dependem do filtro.
- `editProofExpense` continua saindo de `summary.expenses` (a lista completa, hoje `Payments.tsx:195`), nunca da filtrada (R7). Por isso o `expenses` original fica como variável separada da lista exibida.
- `PayableSettlementList`/painel de abas recebem `summary.settlements` e `summary.balances` sem filtro (R7).
- Dois estados vazios com os mesmos textos de Despesas: `expenses.length === 0` → "Nenhuma despesa nesta competência." (texto atual de Pagamentos); lista filtrada vazia → "Nenhuma despesa encontrada para esse filtro.". Sem o quadro com ícone de Despesas: Pagamentos hoje usa só `Typography`.
- A barra aparece mesmo com a competência sem despesas, como em Despesas.
- Estado local da página, sem persistência e sem compartilhamento entre telas (R6). Ele vive em `Payments`, acima do `loading ? … : …`, então sobrevive a recarregar e a navegar de ciclo, e a poda continua valendo.

**Testes** — os de `ExpenseManager.test.tsx` seguem sem edição; os de Pagamentos estão no §8.

## 5. Ordem das despesas e data visível (specify §3.1, R1–R4, D2, D3, D11)

Cada página calcula `const sortedExpenses = useMemo(() => sortExpensesByOccurrence(summary?.expenses ?? []), [summary])` e entrega a lista ao `useExpenseFilters`. Cards e tabela de Despesas já consomem o mesmo `filteredExpenses` (`ExpenseManager.tsx:743` e `:760`); Pagamentos passa a mapear `filteredExpenses` em vez de `expenses` (`Payments.tsx:242`).

- **Data visível (D11)**: card de Despesas ganha uma linha "Data: {formatShortDate}" acima de "Valor:"; a tabela ganha a coluna "Data" como **primeira** (a chave de ordem à vista); o card de Pagamentos ganha "Data: …" abaixo do título. Mesma tipografia secundária dos textos vizinhos; nenhuma cor, fonte ou token muda.
- **Chaves de React**: os mapeamentos usam `key={exp.id}`. Com duas parcelas da mesma despesa na competência o `id` se repete e o React avisa chave duplicada. Nas três listas tocadas (cards e tabela de Despesas, cards de Pagamentos) a chave vira `` `${exp.id}-${exp.installmentNumber ?? 0}-${exp.date}` ``.
- **Home e Relatório (D3 = B)**: `CycleDetailPanel` e `ClosingReportPanel` chamam `sortExpensesByOccurrence` antes de passar a lista a `CycleExpenseList`. O `CycleExpenseList` continua **só renderizando** na ordem recebida, o que preserva o teste "keeps the order it received" (`CycleExpenseList.test.tsx:40`). Ciclo selado, cujo snapshot está congelado em ordem crescente, é reordenado na exibição, sem tocar no snapshot (R4).
- **Painel `/painel`**: não muda (D3 = B). `MyItemsList` segue na ordem do servidor.
- O `ExpenseControllerSummaryTest.php:922` continua válido: a API devolve a mesma ordem de hoje.

**Testes**

- `ExpenseManager.test.tsx:176` clica `getAllByRole('button', { name: 'Ver detalhes' })[0]` esperando abrir o "Aluguel" (id 9, `2026-08-01`, o único com comprovante, `:125`). Com a data decrescente o primeiro passa a ser o "Mercado" (id 10, `2026-08-05`), e o modal que abre não tem o link de comprovante da asserção seguinte. A task troca o índice por uma busca pelo card/linha do "Aluguel", mantendo a asserção do modal.
- Casos novos em `ExpenseManager.test.tsx`: ordem dos cards (viewport `< sm`) e da tabela (o `renderExpenseCards` é escolhido por `useMediaQuery`, como os testes de cards de `:111` já fazem); empate de data resolvido por `id`; despesa pendente não sobe; a coluna "Data" e a linha "Data:"; ordem preservada sob filtro ativo.
- `CycleDetailPanel.test.tsx` e `ClosingReportPanel.test.tsx`: lista exibida em data decrescente mesmo quando o `summary` chega crescente (como um ciclo selado).

## 6. Saldo com o usuário logado primeiro (specify §2.5, §3.2, R5, R14; D4, D5)

`SummarySidePanel.tsx` ganha a prop opcional `currentUserId?: number | null` e faz `useMemo(() => sortBalancesForViewer(balances, currentUserId), …)` antes de `<BalanceCards balances={ordered} />`. `BalanceCards` não muda. A aba "À pagar" (`SettlementList`) também não (D10 = A).

Chamadores: `ExpenseManager.tsx:803` passa o `currentUserId` que já tem (agora do hook, §3); `CycleDetailPanel` ganha a prop opcional `currentUserId` e a repassa; `GroupSummary` a obtém do hook. A prop é opcional, então `CycleDetailPanel.test.tsx` e os demais testes que montam o componente sem ela continuam válidos: a ordem cai na regra dos demais (R14).

**Testes**

- `SummarySidePanel.test.tsx` (novo; hoje o painel não tem teste próprio): as quatro ordens do §5.2 (duas logadas × proposta; a variante B não é implementada), logado nulo, texto "a receber"/"a pagar" e cores preservados, troca para a aba "À pagar" intacta.
- `GroupSummary.test.tsx`: com `/api/me` devolvendo `id`, o Saldo da Home abre com o logado primeiro, e as despesas aparecem em data decrescente. O mock atual devolve `{ name, email }` sem `id`, então os testes existentes não mudam.
- `CycleDetailPanel.test.tsx`: a prop é repassada.

## 7. `PayableSettlementList`: critério, rótulos e vazio (specify §2.8, R8, R11, D7, D8)

Em `frontend/src/components/PayableSettlementList.tsx`:

- `const confirmed = isSettlementConfirmed(settlement)` no lugar de `Boolean(settlement.confirmedProofUrl)` (`:63`). A nota (`confirmedNote`) continua aparecendo só com o acerto confirmado.
- O chip "Comprovante enviado" aparece quando `confirmed`; **só vira link** (`component="a"`, `href`, `clickable`) se `confirmedProofUrl` existir. Sem URL, é o mesmo chip estático, sem link quebrado (R8).
- Marcador de valor a receber (D7 = A): se `currentUserId === settlement.to_user_id`, um `Chip` `size="small"` `variant="outlined"` `color="info"` com ícone e **texto** "Deve pagar a você" (acerto sem confirmação) ou "Pagou a você" (confirmado), na mesma linha de ações. Texto e ícone, nunca só cor. Quem é o credor não é devedor, então os botões Pix/Enviar comprovante não aparecem para ele, pela condição `isDebtor && canConfirm` que já existe.
- Nova prop opcional `emptyMessage?: string`, com o texto atual ("Ninguém deve nada a ninguém neste ciclo.") como padrão, para o painel de abas dar mensagens por aba sem mexer no Painel.
- **Não ordena**: renderiza na ordem recebida. `GroupPositionCard` (Painel) continua passando só as saídas do usuário (`to_pay`), na ordem do servidor, e pelo critério novo `confirmedAt` (que ele já alimenta, `GroupPositionCard.tsx:110-111`); como `to_pay` nunca tem `to_user_id` do próprio usuário, nenhum rótulo novo aparece lá (D6 = A).

**Testes** — `PayableSettlementList.test.tsx`, mantendo os 7 casos de hoje (todas as fixtures confirmadas trazem `confirmedAt` e `confirmedProofUrl` juntos, então passam): (a) `confirmedAt` preenchido e `confirmedProofUrl` nulo → "Reenviar comprovante" e chip sem link; (b) `confirmedProofUrl` preenchido e `confirmedAt` nulo → "Enviar comprovante", sem chip; (c) "Deve pagar a você" / "Pagou a você" só quando `to_user_id === currentUserId`; (d) `emptyMessage`; (e) a lista respeita a ordem recebida.

## 8. Abas de acertos em Pagamentos (specify §3.5, §3.6, R8–R12; D6–D9)

Novo `frontend/src/components/PaymentSettlementsPanel.tsx`, **controlado**: props `settlements`, `balances`, `currentUserId`, `canConfirm`, `cycleSettled`, `tab`, `onTabChange`, `onPayWithPix`, `onSendProof`.

- `useMemo` de `splitSettlementsByConfirmation(settlements)` e, para a aba ativa, de `orderSettlementsForViewer(lista, currentUserId, nameFor)`, com `nameFor` montado de `balances` (mesmo mapa e mesmo `'Desconhecido'` que `PayableSettlementList` usa).
- `Tabs` do MUI como em `SummarySidePanel` (`variant="fullWidth"`, `aria-label="Acertos entre membros"`), duas `Tab`: **"Valores a pagar (n)"** e **"Valores pagos (n)"** (D9). `id`/`aria-controls` em cada aba e um `Box role="tabpanel"` com `aria-labelledby` envolvendo a lista, para leitor de tela e para `getByRole('tabpanel')` nos testes. O MUI já dá `role="tab"`, `aria-selected` e navegação por setas.
- Dentro do painel, `<PayableSettlementList settlements={ordenada} … emptyMessage={…}/>`. Vazio: sem nenhum acerto no ciclo → texto atual nas duas abas; com acertos mas aba vazia → "Nenhum valor a pagar neste ciclo." ou "Nenhum valor pago neste ciclo ainda.".
- **Onde mora a aba escolhida**: `useGroupCycle` faz `setLoading(true)` a cada busca (`useGroupCycle.ts:152`, navegação de ciclo e `reload`) e `Payments` troca o conteúdo por `CircularProgress` enquanto carrega, desmontando o painel. Por isso `tab` é estado de **`Payments`** (`useState<'toPay' | 'paid'>('toPay')`), acima do `loading ? …`, e o painel só o recebe. É o que faz a aba escolhida sobreviver à navegação de ciclo e ao envio de comprovante (D9, CA36 e CA28).
- Em `Payments.tsx:335-352` o título "Valores a pagar" e o `PayableSettlementList` dão lugar ao painel; `canConfirm` (`:344-347`), `cycleSettled`, `onPayWithPix` (`handleSelectSettlement`) e `onSendProof` (`openConfirmSettlementDialog`) seguem como estão. Depois de enviar o comprovante, `confirmSettlementPayment` já chama `reload()` (`Payments.tsx:183`): o acerto reaparece com `confirmedAt` e migra de aba sozinho (CA28), sem código novo.
- Layout: a coluna continua `Grid size={{ xs: 12, md: 5 }}`. A 375 px as duas abas dividem a largura; o rótulo com contador cabe em duas linhas se precisar (verificado na validação manual).

**Testes**

- `PaymentSettlementsPanel.test.tsx` (novo): as duas listas do §5.3 exatamente, por aba; contador; troca de aba; partição por `confirmedAt` independente de `confirmedProofUrl` e de `Quota.paid` (CA21–CA23); soma das contagens (CA24); vazios por aba e sem nenhum acerto; `settlements` `undefined`/`null` (CA27); rótulos de valor a receber; botões só para o devedor com `canConfirm` (CA34).
- `Payments.test.tsx`: abre em "Valores a pagar"; aba mantida ao navegar de ciclo; acerto migra de aba após o envio (mock de `settlements/confirm` + novo `summary`); Pix e envio de comprovante com os mesmos pedidos (CA34–CA35); filtros (os casos do §4 do plano de 20261010 adaptados: combinação E/OU, snapshot sem `participantDetails`, poda ao navegar, nenhum GET novo, dois estados vazios); **com filtro ativo as abas, os saldos e `usePaymentActions` mostram os mesmos dados** (CA18); "Editar comprovante" com filtro ativo e "Desfazer pagamento" com o filtro "Pagas" (CA19); ordem das despesas e a linha "Data:".

## 9. Documentação (specify §2.1, D1)

- `docs/sdd/05-context-frontend.md`, em "Convenções fixas": uma linha dizendo que **ordem e agrupamento de apresentação** (despesas, saldos, acertos) vivem em `frontend/src/utils/cycleOrdering.ts`, não no backend, por serem apresentação e não cálculo; e que as regras são as de `docs/feature/20261026-organizacao-despesas-pagamentos/specify.md` (R1, R5, R8–R10).
- `docs/sdd/07-context-mobile.md`, em "Convenções fixas": a mesma regra apontando para o specify, para o app Flutter repeti-la (consequência declarada da D1).
- `implementation.md`: log com comando e resultado reais de cada task.
- **Não** editar: `01-specify.md` (baseline), Constitution, `03-tasks.md`, docs arquivados em `concluidas/` (a supersessão de §2.7 de `20260902-pagamento-ciclo-fechado` está registrada no specify novo). Os pontos pré-existentes do specify §2.9 e §9 seguem **sem backlog** até o usuário pedir.

## 10. Validação e registro (`docs/sdd/04-implementation.md` §1 itens 3 e 5)

- Comandos do checklist pré-PR, por task e ao final: `cd frontend && npx vitest run <arquivos tocados>`, `npm test` (suíte completa) e `npx tsc --noEmit` (o `vite build` sozinho não verifica tipos). Cada resultado vai para `implementation.md` com o comando real. `eslint` não está configurado (backlog 033).
- `git diff --stat -- backend/` vazio ao final (specify CA41). Sem mudança de backend não há `pint` nem `php artisan test` obrigatórios; rodar `ExpenseControllerSummaryTest` e `OverviewControllerTest` só como regressão se algum arquivo de backend for tocado por engano.
- O agente `pr-readiness-checker` roda antes do PR. O `security-reviewer` não se aplica: nenhuma rota, controller ou middleware muda.
- **Validação manual local** na stack isolada da máquina do usuário (a porta 3306 é de outro projeto), com a limpeza ao final. Roteiro: grupo com pelo menos quatro membros; despesas dos três tipos, com datas repetidas e uma parcela retroativa; ciclo aberto, ciclo fechado com acertos confirmados e não confirmados, e ciclo selado; ver como **devedor**, como **credor** (para os rótulos "deve pagar a você"/"pagou a você") e como membro sem acertos; Saldo na Home e em Despesas; filtros em Pagamentos com cada combinação; navegar de ciclo com filtro e aba ativos; enviar um comprovante e ver o acerto migrar para "Valores pagos"; 375 px sem scroll horizontal; navegação das abas por teclado.
- Gate humano: **push e PR só depois que o usuário validar localmente**; merge do PR em `dev` é gate humano (`00-constitution.md` §5.2).

## 11. Riscos e pontos de atenção

| Risco | Tratamento |
|---|---|
| `useMemo`/hooks depois de retorno antecipado | Nenhuma das duas páginas tem retorno antecipado antes dos hooks (o `loading` é ternário no JSX). Manter assim: `sortedExpenses`, `useExpenseFilters` e o `useState` da aba entram junto dos outros estados, no topo. |
| `summary?.expenses ?? []` cria um array novo a cada render e invalida `useMemo` | A dependência do `useMemo` de `sortedExpenses` é `summary`, não o array. |
| Chave de React duplicada em despesa com duas parcelas na competência | Chave composta nas três listas tocadas (§5). |
| Regra de ordem duplicada entre web e app Flutter | Consequência aceita da D1; registrada no §9 e em `07-context-mobile.md`. |
| +1 `GET /api/me` na Home | Custo conhecido (§3); a chamada é barata e já é feita por Despesas e Pagamentos. |
| Backend devolver `settlements = null` de snapshot antigo e dar 500 antes de chegar ao cliente | Pré-existente (specify §2.9), fora do escopo; o cliente novo só trata `null` como lista vazia. |
| Extração dos filtros regredir Despesas | Os testes de filtro de `ExpenseManager.test.tsx` não podem ser editados na task da extração. |
| Rótulo de aba com contador quebrar a 375 px | `variant="fullWidth"`, texto pode quebrar em duas linhas; conferido na validação manual. |

## 12. Ordem de execução

Há dependência técnica entre os itens, e nenhuma depende do backend. Grafo:

| Passo | Plano | Depende de |
|---|---|---|
| A | §1 utilitário de ordem + §2 data curta | — |
| B | §3 hook `useCurrentUserId` e adoção em Despesas e Pagamentos | — |
| C | §4 extração dos filtros (`useExpenseFilters` + `ExpenseFilterBar`), só em `ExpenseManager`, **sem alterar testes** | — |
| D | §5 ordem e data em Despesas | A, C (o hook recebe a lista já ordenada) |
| E | §6 Saldo + ordem na Home e no Relatório (`SummarySidePanel`, `CycleDetailPanel`, `GroupSummary`, `ClosingReportPanel`) | A, B |
| F | §7 `PayableSettlementList` | A |
| G | §4 e §5 aplicados a Pagamentos (filtros, ordem, data) | A, B, C |
| H | §8 painel de abas em Pagamentos | A, F, G |
| I | §9 documentação e validação manual | todos |

Critério de ordenação em `tasks.md`: primeiro o que não depende de nada e destrava os demais (A), depois o que reduz risco de regressão (C, refatoração protegida por testes existentes), depois as telas na ordem Despesas → Home → Pagamentos. Não há paralelismo útil com um executor só: C e D, G e H editam os mesmos arquivos.

Fluxo de branch conforme `04-implementation.md` §1: a primeira task roda direto na branch da feature (`feature/20261026-organizacao-despesas-pagamentos`); as seguintes, em sub-branches `…-TASK-0xx` integradas na da feature com `git merge --no-ff`, sem PR. No fim, um único PR contra `dev`. Numeração: a próxima TASK livre era a 499 em 2026-10-26; reconfirmar no momento de escrever o `tasks.md` (`git log --all` e `docs/`), porque outra feature em andamento pode reservar IDs.
