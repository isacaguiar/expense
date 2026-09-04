# Plan — Redesign Visual das Telas de Despesas

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260825

---

## 1. Tema com escopo restrito (specify §R1)

- Criar `frontend/src/theme/despesasTheme.ts`, exportando uma função `createDespesasTheme(baseTheme: Theme): Theme` que usa `createTheme(baseTheme, { ... })` (a forma de dois argumentos do MUI mescla com o tema recebido em vez de substituí-lo) para sobrescrever:
  - `palette.primary` → `brandColors.primary`/`brandColors.primaryDark`/`brandColors.primaryLight` (mesmos tokens já usados no sidebar/header, sem inventar cor nova).
  - `shape.borderRadius` → um valor maior que o padrão (10) para cards/tabela/diálogos.
  - `components.MuiButton.styleOverrides.root` / `MuiChip.styleOverrides.root` / `MuiToggleButton.styleOverrides.root` → `borderRadius: 999` (pill), preservando `textTransform`/tamanho padrão do MUI (nenhuma prop nova, só CSS).
  - `components.MuiOutlinedInput.styleOverrides.root` → radius pill (999) para casar com o campo de busca do mockup — usado tanto pelo `TextField` de busca quanto pelos campos dos formulários (mesmo componente, sem diferenciar por tela).
  - `components.MuiPaper.styleOverrides.root` → sombra mais suave (`boxShadow` customizado) substituindo a elevação padrão do MUI, já que o mockup usa sombra leve, não a elevação 3 "pesada" hoje usada em `TableContainer`/`Card`.
- Criar `frontend/src/theme/DespesasThemeScope.tsx`: componente `{ children: React.ReactNode }` que lê o tema ativo via `useTheme()` e envolve `children` em `<ThemeProvider theme={createDespesasTheme(outerTheme)}>`. Ficam de fora do escopo (fora do `<Outlet>` que renderiza a página) o `GroupSidebar`/`GroupHeader` de `GroupShellLayout.tsx` — o wrap acontece dentro de cada página, não no layout, então shell nunca entra no tema novo (specify §4).
- Cada uma das 4 páginas (`ExpenseManager.tsx`, `ExpenseView.tsx`, `ExpenseForm.tsx`, `ExpensesEntry.tsx`) envolve seu retorno JSX de nível superior com `<DespesasThemeScope>`. Não muda nenhuma outra linha de lógica dessas páginas além dessa borda de apresentação.
- Por que essa abordagem e não `sx` espalhado em cada componente: o mockup exige mudança consistente em ~6 tipos de componente MUI (Button, Chip, ToggleButton, OutlinedInput, Paper, Dialog usa Paper) repetidos dezenas de vezes nas 3 telas; um tema com escopo local aplica a mudança uma vez, sem risco de esquecer um `sx` e sem duplicar a definição de cor/radius em cada arquivo. `SummarySidePanel` (fora de escopo, specify §4) herda o tema automaticamente só quando renderizado dentro de `ExpenseManager.tsx`, sem precisar editar o arquivo dele.

## 2. `ExpenseManager.tsx` — busca, filtro, tabela, estado vazio (specify §R2, §R3)

- Busca: `TextField` ganha `InputProps={{ startAdornment: <SearchIcon .../> }}` (ícone novo, puramente visual) — o radius pill já vem do tema (item 1), não precisa de `sx` de radius aqui.
- `ToggleButtonGroup`: radius pill já vem do tema; ajustar só `sx` de padding/gap se necessário para bater com a proporção do mockup.
- `TableContainer`: `TableHead` ganha `sx={{ bgcolor: 'grey.50' }}` e `TableCell` do header ganha peso de fonte médio (`fontWeight: 600`, `color: 'text.secondary'`) — só isso, sem trocar `Table`/`TableRow`/`TableCell` por outro componente.
- `TableRow`: manter `hover`, ajustar cor do hover via `sx` se o padrão do tema não bastar.
- `IconButton`s de ação: `sx={{ '&:hover': { bgcolor: 'action.hover' } }}` (já é o padrão MUI, então normalmente não precisa de mudança) — revisar visualmente e só ajustar se destoar.
- Estado vazio (`expenses.length === 0`): acrescentar um ícone (ex.: `SavingsOutlinedIcon` ou `AccountBalanceWalletOutlinedIcon` do `@mui/icons-material`, já uma dependência existente) em um círculo com `bgcolor: brandColors.primaryLight`, `color: brandColors.primary`, acima do `Typography` já existente — sem trocar o texto.

## 3. `ExpenseView.tsx` (specify §R4)

- Dentro do único `Card` (visualização e edição), revisar `sx` de espaçamento (`gap`, `mb`) e tipografia (peso do título, tamanho do valor em destaque) para dar mais hierarquia, igual à proporção do mockup — sem adicionar `Grid`/segundo `Card`.
- Chip de status/tipo: radius pill vem do tema (item 1) automaticamente.
- Avatar do credor: já usa `brandColors.primaryLight`/`brandColors.primary` via `sx` direto (não depende do tema) — mantido como está, já correto.

## 4. `ExpenseForm.tsx` (specify §R5)

- Mesmo tratamento do item 3: espaçamento/tipografia dentro do `Card` único existente. Toggle de tipo continua como `TextField select` (não vira `ToggleButtonGroup` novo) — manter o componente atual, só herdar radius/cor do tema.

## 5. Diálogos e Snackbars (specify §R6)

- Nenhuma mudança de código além de já estarem dentro do `DespesasThemeScope` (item 1) — `Dialog`/`Alert`/`Chip` internos herdam radius/cor automaticamente por serem descendentes React do `ThemeProvider` local.

## N. Ordem de execução

Sem dependência circular, mas há uma dependência sequencial clara: o tema (item 1) precisa existir e estar aplicado nas 4 páginas antes de qualquer ajuste fino de `sx` nos itens 2-4 fazer sentido (senão o ajuste fino seria feito duas vezes, uma vez "no escuro" sem o tema, outra depois). Ordem de tasks: tema + aplicação nas 4 páginas → `ExpenseManager.tsx` (maior superfície) → `ExpenseView.tsx` → `ExpenseForm.tsx` → verificação final (tsc + testes existentes + captura visual).
