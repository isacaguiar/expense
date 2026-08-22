# Plan — Layout de Duas Colunas na Página de Despesas

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Breakpoints do grid principal (specify §2.1, R5)

- `ExpenseManager.tsx:353` (`<Grid size={{ xs: 12, md: 8 }}>`) e `:484` (`<Grid size={{ xs: 12, md: 4 }}>`) trocam para `{ xs: 12, sm: 12, lg: 8 }` e `{ xs: 12, sm: 12, lg: 4 }` — exatamente o par já usado em `GroupSummary.tsx:134,173` (`docs/feature/20260822-reestruturacao-resumo/plan.md §1`), mesma razão já registrada lá: tablets estreitos (600-1200px) ficam empilhados junto com mobile em vez de espremer duas colunas.
- `<Grid container spacing={3}>` (linha 352) não muda.

## 2. Tabela de despesas no lugar do grid de cards (specify §2.4, §2.5, §2.6, R1, R2)

- Substitui `ExpenseManager.tsx:380-481` (`<Grid container spacing={2}>` de `Card`s) por `<TableContainer component={Paper} elevation={3}>` + `<Table>` com `<TableHead>`/`<TableBody>`, iterando `filteredExpenses` (mesma variável, mesma lógica de filtro — R2 cumprido por não tocar em `search`/`typeFilter`/`filteredExpenses`, achado 2.6).
- Colunas (uma por campo hoje exibido no card, achado 2.5): **Tipo** (ícone `AutorenewOutlinedIcon`/`ReceiptOutlinedIcon`, sem texto — mesmo ícone de hoje), **Despesa** (descrição), **Valor**, **Data**, **Credor** (`payerName`), **Pagadores** (participantes, com `Tooltip` pro texto completo — mesmo padrão de hoje, `noWrap` na célula), **Status** (`Chip` "Paga"/"Pendente", mesma lógica de cor), **Ações** (ícones condicionais).
- **Header vira o rótulo, não mais texto embutido**: hoje a célula mistura rótulo+valor ("Credor: Isac", "Pagadores: Isac, Maria" — achado 2.5); numa tabela de verdade o header da coluna já é o rótulo, então a célula passa a ter só o valor (`Isac`, `Isac, Maria`). Efeito colateral conhecido: os 2 testes que buscam essas strings coladas (`GroupSummary`... não, `ExpenseManager.test.tsx:192-193`) precisam ser reescritos — não é regressão, é decorrência direta de R1 (uma coluna por campo). Registrado aqui pra não parecer descuido, igual fizemos com o achado 2.4 de `reestruturacao-resumo`.
- **Link de navegação (achado 2.4, `CardActionArea` de hoje)**: a célula "Despesa" vira `<Link component={RouterLink} to={`/groups/${groupId}/expenses/${exp.id}`}>{exp.description}</Link>` — mantém o mesmo contrato que o teste existente já verifica (`getByRole('link', { name: /Aluguel/ })` com `href`). Decisão: o link fica só na célula de descrição, não a linha inteira — o teste atual já testava só isso (nunca testou a área clicável do card inteiro), e é o padrão mais comum/acessível pra tabela (evita `<tr>` inteiro virar link, que é semântica de HTML questionável).
- **Ações condicionais (achado 2.4)**: célula "Ações" reúne, na mesma ordem de hoje, os `IconButton`s de remover despesa fixa / editar / excluir / marcar paga / desfazer pagamento — mesmas condições (`canEdit`, `canDelete`, `canPay`, `canUnpay`, `exp.isFixed && cycleIsOpen`) e mesmos `aria-label`/`Tooltip`, sem alterar nenhuma regra de exibição (R4).
- **Estados vazios** (`expenses.length === 0` / `filteredExpenses.length === 0`, hoje linhas 375-378): continuam como `Typography` fora da tabela — não mostra `<Table>` vazia, mesmo padrão de hoje.
- **Sem rolagem horizontal na página**: `TableContainer` já isola o overflow-x da tabela em si (scroll só dentro do componente, se a tabela for mais larga que a coluna) — consistente com a regra já seguida em `reestruturacao-resumo` de nenhum scroll horizontal na página toda.

## 3. Painel lateral com `SummarySidePanel` (specify §2.3, R3)

- `ExpenseManager.tsx:484-489` (`<Typography>Saldo por pessoa</Typography>` + `<BalanceCards balances={summary.balances} />`) é substituído por `<SummarySidePanel balances={summary.balances} settlements={summary.settlements} cycleStatus={summary.cycle.status} />` — mesmo componente de `docs/feature/20260822-reestruturacao-resumo/`, já em `dev`, sem alteração nele.
- `summary.settlements` e `summary.cycle.status` já vêm do mesmo `useGroupCycle` que `ExpenseManager.tsx` já consome (linha 59) — nenhum campo novo, nenhuma chamada de API adicional (achado 2.3).
- Import novo: `import SummarySidePanel from '../components/SummarySidePanel';` no lugar do `import BalanceCards from '../components/BalanceCards';` (que deixa de ser usado direto nesta página).

## 4. Testes frontend (`ExpenseManager.test.tsx`)

- **Efeito colateral esperado de item 2** (achado 2.5): `shows status chip, credor and pagadores` (`ExpenseManager.test.tsx:171-194`) precisa trocar `screen.getByText(/Credor: Isac/)` e `screen.getByText('Pagadores: Isac, Maria')` por asserções sobre o valor puro da célula (ex.: `screen.getByText('Isac')` pro Credor — único nesse cenário isolado — e `screen.getByText('Isac, Maria')` pros Pagadores, sem prefixo).
- **Efeito colateral esperado de item 3**: `renders the grid with balances...` (`ExpenseManager.test.tsx:791-875`, linha 847 `screen.getByText('Saldo por pessoa')`) precisa trocar pra uma asserção que bata com `SummarySidePanel` (ex.: `screen.getByRole('tab', { name: 'Saldo' })`), já que o título "Saldo por pessoa" deixa de existir.
- **Demais testes de ações condicionais** (`ExpenseManager.test.tsx:196-782`) não deveriam precisar de mudança — mesmos `aria-label`/`role` (`button`/`link`), só a estrutura ao redor (célula de tabela em vez de `CardActions`) muda, e `getByRole`/`getByText` não dependem disso.
- **Teste novo**: cenário com `settlements` não vazio mockado no `summaryResponse` de teste (hoje o fixture não inclui `settlements` — ver `summaryResponse()` em `ExpenseManager.test.tsx:43-56`, precisa ganhar o campo), clicando na aba "À pagar" e conferindo que o conteúdo de liquidação aparece — confirma que `SummarySidePanel` está de fato recebendo dado real desta página, não só que o componente existe.

## 5. Ajustes pós-revisão na tabela (pedido do usuário durante revisão, antes do PR)

- Remove as colunas **Data** e **Pagadores** da tabela de despesas (item 2) — ficam **Tipo | Despesa | Valor | Credor | Status | Ações**.
- Coluna **Credor** ganha um `Avatar` com iniciais (`getInitials`, `frontend/src/layouts/group/getInitials.ts`) e cor de marca (`brandColors.primaryLight`/`primary`, `frontend/src/theme/brandColors.ts`) ao lado do nome — mesmo padrão visual já usado em `BalanceCards`/`SettlementList` (não é foto real, é o mesmo avatar de iniciais já estabelecido no app).
- Novo ícone "Ver detalhes" (`InfoOutlinedIcon`) na coluna **Ações** abre um `Dialog` (modal) com o detalhamento da despesa: descrição, tipo (chip), status (chip Paga/Pendente), valor, data (`formatDate`, que deixa de ser usada na tabela e passa a ser usada só aqui) e participantes (`exp.participants` — mesmo dado que existia na coluna "Pagadores" removida, continua acessível via o modal, não se perde). Sem chamada de API nova: usa o mesmo objeto `exp` (`SummaryExpense`) já carregado via `useGroupCycle`.
- O link de navegação da célula "Despesa" (pra `ExpenseView.tsx` via `/groups/{groupId}/expenses/{id}`) continua existindo sem mudança — o modal é um atalho adicional de leitura rápida, não substitui a navegação pra tela cheia (que também permite editar).

## N. Ordem de execução

Sem dependência externa (nenhuma mudança de API/backend). Sequencial dentro do frontend: item 1 (breakpoint) é independente e pode vir em qualquer ordem, mas mantemos primeiro por ser o menor risco; item 2 (tabela) e item 3 (painel lateral) são independentes entre si (mexem em metades diferentes do mesmo `Grid`), mas item 4 (testes) só fecha depois dos dois, já que depende do resultado final de ambos. Item 5 (ajustes pós-revisão) depende do item 2 já estar pronto (mexe na mesma tabela) e vem por último. Ordem sugerida pra `tasks.md`: 1 → 2 → 3 → 4 → 5.
