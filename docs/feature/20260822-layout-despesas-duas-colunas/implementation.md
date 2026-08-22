# Implementation — Layout de Duas Colunas na Página de Despesas

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/ExpenseManager.test.tsx` — 32/32 testes passaram (Test Files 1 passed, Tests 32 passed). | `ExpenseManager.tsx:353` e `:484` trocados de `{ xs: 12, md: 8 }`/`{ xs: 12, md: 4 }` para `{ xs: 12, sm: 12, lg: 8 }`/`{ xs: 12, sm: 12, lg: 4 }`, igual ao par já usado em `GroupSummary.tsx`. Nenhum outro conteúdo alterado. |
| TASK-002 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/ExpenseManager.test.tsx` — 31/32 passaram, 1 falha esperada (`ExpenseManager.test.tsx:192-193`, prefixos "Credor: "/"Pagadores: " não existem mais — achado 2.5/plan.md §2, correção é escopo de TASK-004). | Grid de cards (`Card`/`CardActionArea`/`CardActions`/`CardContent`) substituído por `TableContainer`/`Table` com colunas Tipo (ícone com `Tooltip`, sem chip de texto redundante), Despesa (link via `MuiLink component={Link}`), Valor, Data, Credor, Pagadores (com `Tooltip`), Status, Ações (mesmos `IconButton`s/condições de antes). Imports `Card*` removidos; `Table*`/`Paper`/`Link as MuiLink` adicionados. |
| TASK-003 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/ExpenseManager.test.tsx` — 30/32 passaram, 2 falhas esperadas (a de TASK-002 + `ExpenseManager.test.tsx:847`, texto "Saldo por pessoa" não existe mais — ambas escopo de TASK-004). | Bloco `<Typography>Saldo por pessoa</Typography>` + `<BalanceCards/>` substituído por `<SummarySidePanel balances={summary.balances} settlements={summary.settlements} cycleStatus={summary.cycle.status} />`. Import `BalanceCards` trocado por `SummarySidePanel`. Nenhum dado novo — mesmo `useGroupCycle` já em uso. |
| TASK-004 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/ExpenseManager.test.tsx` — 32/32 passaram (Test Files 1 passed, Tests 32 passed). | `shows status chip, credor and pagadores` (linhas 171-194 na numeração anterior): `getByText(/Credor: Isac/)`/`getByText('Pagadores: Isac, Maria')` trocados por `getByText('Isac')`/`getByText('Isac, Maria')` (sem prefixo, coluna já dá o rótulo). Teste de fluxo completo (linha 847 na numeração anterior): `getByText('Saldo por pessoa')` trocado por `getByRole('tab', { name: 'Saldo' })`. `summaryResponse()` do fixture de teste ainda não tem `settlements` — nenhum teste atual toca a aba "À pagar" (default é "Saldo"), então não crasha; isso é escopo de TASK-005. |
| TASK-005 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/ExpenseManager.test.tsx` — 33/33 passaram. `npx vitest run` (suíte completa do frontend) — 130/130 passaram (Test Files 20 passed, Tests 130 passed), sem flake desta vez. | `summaryResponse()`/`mockGetResponses()` ganharam parâmetro `extra` (terceiro, opcional, default `{}`) pra permitir sobrescrever `balances`/`settlements` no fixture; `settlements: []` virou default explícito no retorno base. Novo `describe('ExpenseManager - aba "À pagar" do painel lateral')` com 1 teste: mocka `balances`+`settlements`, clica na aba "À pagar" e confere nomes/valor/"deve pagar" — mesmo padrão de `GroupSummary.test.tsx`. |
