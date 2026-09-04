# Plan — Reestruturação do Resumo do Grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Layout de duas colunas em `GroupSummary.tsx` (specify §2.1, §2.5, R1, R2, R5)

- Envolver os blocos "Despesas do ciclo" e o novo painel lateral (item 2) num único `<Grid container spacing={3}>`, logo após os cards de totais existentes (`GroupSummary.tsx:87-131`, inalterados).
- Coluna esquerda ("Despesas do ciclo"): `<Grid size={{ xs: 12, sm: 12, lg: 8 }}>` — o bloco `Typography` + `Paper`/`List` já existente (`GroupSummary.tsx:133-168`) é movido pra dentro, sem mudar a lógica de renderização de cada item (descrição, valor, data, pagador, participantes, ícone Fixa/Variável, chip Paga/Pendente — R2 preservado por não tocar nesse trecho).
- Coluna direita (painel lateral): `<Grid size={{ xs: 12, sm: 12, lg: 4 }}>` — novo componente do item 2.
- Breakpoints: reaproveita o par `xs`/`lg` (em vez de só `xs`/`md` como os cards de totais, achado 2.5) porque o pedido distingue 3 comportamentos (mobile empilhado, tablet com proporção ajustada, desktop 65-70%/30-35%) — decisão: usar `lg` (1200px, MUI default) como corte pra a proporção final 8/4 (66,7%/33,3%, dentro da faixa pedida), e manter `xs:12` (empilhado) até `lg`. Isso simplifica pra 2 estados em vez de 3 (sem tier intermediário de "tablet com proporção diferente de desktop") — como não há nenhum precedente de layout de 3 tiers no projeto (achado 2.3) e a diferença visual de um tier a mais não parece justificar a complexidade extra, a barra de corte fica em `lg` só pra evitar colunas espremidas demais em tablets estreitos (600-1200px), que continuam empilhados junto com mobile. Se o usuário validar o resultado visual e achar as colunas ainda apertadas nessa faixa, ajusta-se o breakpoint na revisão do PR, não é uma decisão que trava a implementação.
- Sem rolagem horizontal: `Grid` v2 (`size={{...}}`) já reflow de acordo com a largura do container pai, mesmo padrão já usado nos cards de totais — não introduz overflow novo.

## 2. Painel lateral com abas Saldo/À pagar — novo componente (specify §2.2, §2.3, R3, R4, R6)

- Novo arquivo `frontend/src/components/SummarySidePanel.tsx` (mesmo diretório e convenção de nome curto de `BalanceCards.tsx`/`SettlementList.tsx`), recebendo `balances: SummaryBalance[]`, `settlements: SummarySettlement[]`, `cycleStatus: CycleStatus` como props (tipos já existentes em `useGroupCycle.ts`, sem novo tipo de dado).
- Estrutura: `<Card variant="outlined">` (mesmo padrão visual de `BalanceCards`/`SettlementList`) contendo, de cima pra baixo: (a) o selo de volatilidade do item 3; (b) `<Tabs value={tab} onChange={...}>` com dois `<Tab label="Saldo" value="balance" />` / `<Tab label="À pagar" value="settlement" />`; (c) o conteúdo da aba ativa.
- Estado da aba: `useState<'balance' | 'settlement'>('balance')` local ao componente — troca de aba só atualiza esse state, não dispara nenhuma chamada de API nem navegação (R4 cumprido por construção, já que `balances`/`settlements` vêm inteiros via props desde o primeiro load).
- Conteúdo da aba "Saldo": `<BalanceCards balances={balances} />` (componente existente, sem alteração — R6 preservado porque as cores verde/vermelho já estão em `BalanceCards.tsx:30-36`).
- Conteúdo da aba "À pagar": se `settlements.length > 0`, `<SettlementList settlements={settlements} balances={balances} />` (existente, sem alteração); caso contrário, mensagem de estado vazio `<Typography color="text.secondary">Nenhuma pendência entre os membros neste ciclo.</Typography>` — decisão nova desta feature: hoje o bloco inteiro some quando `settlements` está vazio (`GroupSummary.tsx:175`); com a aba fixa (R3 exige as duas abas sempre presentes), precisa de conteúdo pro caso vazio em vez de esconder a aba.
- Sem `TabPanel` customizado com `role`/`aria-*` completo — MUI não fornece um pronto e não há precedente no projeto (achado 2.3); render condicional simples (`{tab === 'balance' ? <BalanceCards .../> : <>...</>}`) é suficiente pro escopo desta feature (só UI/UX, R3/R4), sem introduzir abstração de acessibilidade não pedida.
- `GroupSummary.tsx` passa a importar e renderizar `<SummarySidePanel balances={summary.balances} settlements={summary.settlements} cycleStatus={summary.cycle.status} />` dentro da coluna direita do item 1, no lugar dos blocos "Saldos por pessoa" (`170-173`) e "Quem paga a quem" (`175-182`) que saem de `GroupSummary.tsx`.

## 3. Selo de valor volátil/definitivo (specify §2.7, R8)

- Dentro de `SummarySidePanel`, acima da `Tabs` (não duplicado em cada aba — como o status do ciclo é o mesmo para as duas abas ao mesmo tempo, achado 2.7, um único selo no topo do card cumpre R8 sem repetir o mesmo ícone/texto duas vezes): `isVolatile = cycleStatus === 'open' || cycleStatus === 'future'`.
- Volátil (`isVolatile === true`): `<Chip size="small" variant="outlined" color="info" icon={<UpdateIcon fontSize="small" />} label="Prévia" />` — `color="info"` reaproveita a mesma cor já usada pro status `open` em `cycleStatusChip.open` (`useGroupCycle.ts:53`), consistência de paleta.
- Definitivo (`isVolatile === false`, ou seja `closed`/`closed_manually`): `<Chip size="small" variant="outlined" color="success" icon={<PaidOutlinedIcon fontSize="small" />} label="Definitivo" />` — `color="success"` reaproveita a mesma semântica já usada no chip "Paga" de cada despesa (`GroupSummary.tsx:161`, `color={expense.paid ? 'success' : 'warning'}`).
- Ícones confirmados disponíveis em `@mui/icons-material@^7.1.1` (já instalado): `Update`, `PaidOutlined` (`node_modules/@mui/icons-material/{Update,PaidOutlined}.js`) — sem dependência nova.
- Não reaproveita `AutorenewOutlinedIcon` (achado 2.7) — ele continua só como marcador de despesa Fixa em `GroupSummary.tsx:146-147`, sem mudança.

## 4. Testes frontend (specify §2.4)

- `frontend/src/pages/GroupSummary.test.tsx:200-210` ("does not show the 'quem paga a quem' block when settlements is empty"): reescrever para verificar o estado vazio da aba "À pagar" em vez da ausência do bloco — precisa clicar na aba "À pagar" (`userEvent.click(screen.getByRole('tab', { name: 'À pagar' }))`) antes de checar `screen.getByText('Nenhuma pendência entre os membros neste ciclo.')`, já que ela deixa de estar sempre visível (fica atrás da aba "Saldo", selecionada por padrão).
- `frontend/src/pages/GroupSummary.test.tsx:212-231` ("shows the 'quem paga a quem' block resolving names from balances"): mesma adaptação — clicar na aba "À pagar" antes das asserções de nome/valor, já que o conteúdo não aparece mais sem interação.
- Novo teste: aba "Saldo" selecionada por padrão ao carregar a tela (R4) — conteúdo de `BalanceCards` visível sem nenhum clique.
- Novo teste: clicar em "À pagar" e depois voltar pra "Saldo" não dispara nova chamada `axios.get` (R4 — troca de aba é só client-side) — mesmo padrão de asserção de `vi.mocked(axios.get).mock.calls` já usado nos testes de navegação de ciclo (`GroupSummary.test.tsx:120-138`).
- Novo teste parametrizado (mesmo padrão `it.each` de `GroupSummary.test.tsx:160-174`): para `status` em `open`/`future` mostra o selo "Prévia"; para `closed`/`closed_manually` mostra "Definitivo".
- Teste novo (ou movido) pro componente `SummarySidePanel` isoladamente é opcional — como a lógica é pequena e já teria cobertura via `GroupSummary.test.tsx` (que já monta a árvore inteira com dados mockados), não é necessário duplicar em um arquivo de teste próprio só pra este componente, salvo se `tasks.md` decidir o contrário.

## N. Ordem de execução

Sem dependência externa (backend não muda, R7) — dentro do frontend, ordem sequencial por depender de código: item 1 (Grid de duas colunas) precisa existir antes de item 2 ter onde encaixar (`SummarySidePanel` é renderizado dentro da coluna criada no item 1), item 3 é uma adição dentro do componente já criado no item 2 (não faz sentido codar o selo antes do card/abas existirem), e item 4 (testes) fecha depois que 1-3 estiverem implementados, já que várias asserções dependem do comportamento final das abas e do selo juntos. Ordem sugerida pra `tasks.md`: 1 → 2 → 3 → 4.
