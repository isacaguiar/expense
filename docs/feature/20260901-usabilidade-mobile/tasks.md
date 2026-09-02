# Tasks — Usabilidade Mobile das Telas Internas

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260901

> Gate humano por feature: um único PR da branch `frontend/20260901-usabilidade-mobile` → `dev`, cujo merge é aprovação humana (Constitution §5.2). Nenhuma task abaixo toca produção, segredo ou migration, então a coluna "Gate humano" é `nenhum` em todas — o gate é o PR da feature.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-236 | Fazer o cluster de controles do `GroupHeader` caber em 375px (wrap + esconder nome em `xs` + `Select` elástico) | frontend | plan.md §2 | nenhum | Concluída |
| TASK-237 | Tornar o `mt`/`mb` do `<Container>` responsivo nos dois shells | frontend | plan.md §5 | nenhum | Concluída |
| TASK-238 | Deixar a barra de ações do `ExpenseManager` quebrar linha (`flexWrap`) | frontend | plan.md §4 | nenhum | Concluída |
| TASK-239 | Deixar o rótulo do seletor de competência ceder espaço em `ExpenseManager` e `Payments` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-240 | Adicionar polyfill de `window.matchMedia` em `setupTests.ts` | infra | plan.md §0.3 | nenhum | Concluída |
| TASK-241 | `Dashboard`: renderizar lista de grupos como cartões abaixo de `sm` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-242 | `ExpenseManager`: renderizar lista de despesas como cartões abaixo de `sm` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-243 | Verificar `ExpenseView`/`ExpenseForm`/`GroupMembersForm`/diálogos em 375px com sessão real | doc | plan.md §6 | nenhum | Pendente — aguarda sessão autenticada (backend + login); ver implementation.md §2 |

Ordem de execução (plan.md §7): 236 → 237 → 238 → 239 → 240 → 241 → 242 → 243. Sem dependência técnica, exceto **TASK-240 antes de TASK-241/242**. A 1ª task (236) nasce na branch da feature; as demais em sub-branches `frontend/20260901-usabilidade-mobile-TASK-0xx` com merge local `--no-ff` (ADR-003).

## Critérios de aceite

- **TASK-236**: em viewport 375×812, numa rota `/groups/:id/summary` com ≥1 grupo carregado, `document.documentElement.scrollWidth === document.documentElement.clientWidth` (sem scroll horizontal). O nome do usuário não é renderizado (`display:none` em `xs`); o `<Avatar>` continua visível. `GroupHeader.test.tsx` verde + caso novo que verifica que o nome tem `display:{xs:'none',sm:'block'}` (ou equivalente testável) e que o `<Select>` de grupo não é renderizado quando `groups=[]`.
- **TASK-237**: `SimpleShellLayout.tsx` e `GroupShellLayout.tsx` passam `mt`/`mb` responsivo (`{ xs: 2, md: 4 }`) ao `<Container>`. `SimpleShellLayout.test.tsx` e `GroupShellLayout.test.tsx` verdes. Verificação visual em 375px: o conteúdo começa a ~16px abaixo do header (antes ~32px).
- **TASK-238**: o `<Box>` do cabeçalho do `ExpenseManager` (linha ~267) tem `flexWrap:'wrap'` e `rowGap`. Em largura de 320px os botões "Fechar mês" e "Nova Despesa" quebram para duas linhas sem `scrollWidth > clientWidth`. `ExpenseManager.test.tsx` verde.
- **TASK-239**: o `<Typography>` central do seletor de competência em `ExpenseManager` e `Payments` tem `flexGrow:1`, `minWidth:0`, `textAlign:'center'` e `fontSize` responsivo (`{ xs:'1rem', md:'1.25rem' }`). Em 375px o intervalo de datas fica centralizado entre as duas setas sem ser cortado nem gerar scroll horizontal. `ExpenseManager.test.tsx` e `Payments.test.tsx` verdes.
- **TASK-240**: `setupTests.ts` define `window.matchMedia` como mock (retorna objeto com `matches` configurável por teste, `media`, e `addEventListener`/`removeEventListener`/`addListener`/`removeListener` no-op). `npm test` continua com a suíte inteira verde (≥175). Um teste que monta um componente com `useMediaQuery` roda sem warning de `matchMedia is not a function`.
- **TASK-241**: com `window.matchMedia` retornando `{ matches: true }` (viewport < `sm`), `Dashboard` renderiza um `<Card>` por grupo — sem `role="table"` — contendo nome (clicável para o mesmo destino da linha), "Responsável", "Integrantes" e os 4 botões de ação (editar, participantes, despesas, excluir). Com `{ matches: false }`, a `<Table>` atual é renderizada e `Dashboard.test.tsx:111-113` (`columnheader`) seguem verdes. Casos novos: um clique numa ação do cartão dispara o mesmo handler que a ação da tabela (navegação / abre diálogo de exclusão); o chevron de expandir abre o painel de pendências do grupo. Verificação visual em 375px sem scroll horizontal.
- **TASK-242**: com `matches: true`, `ExpenseManager` renderiza um `<Card variant="outlined">` por despesa com ícone de tipo, descrição, `Chip` de status, "Valor", "Credor" e as ações com `canEdit`/`canDelete` respeitados. Com `matches: false`, a `<Table>` de 6 colunas é preservada e os testes atuais de `ExpenseManager.test.tsx` seguem verdes. Casos novos cobrem o ramo cartão (ação de editar/excluir/detalhe dispara o mesmo handler). Verificação visual em 375px sem scroll horizontal; `<TableContainer>` com `overflowX:'auto'` explícito.
- **TASK-243**: `implementation.md` registra uma linha por tela verificada (`ExpenseView`, `ExpenseForm` incl. lista "quem participa", `GroupMembersForm`, `PixPaymentDialog`, diálogos de confirmação de `ExpenseManager`/`Payments`) em viewport 375×812 com login real — cada linha com resultado concreto (ok / descrição do problema + screenshot), não "revisado". Para cada tela que quebrar, uma `TASK-244+` é aberta neste arquivo seguindo os padrões de F1–F5 (sem redesign).
