# Plan — Despesas em aberto primeiro

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui. Só frontend: nenhum endpoint, rota ou migration muda.

Versão: 1.0 · Criado em: 20261123

Decisões do specify aprovadas por ele em 2026-11-23: **D1-C** (as quatro telas), **D2-B** (a função atual passa a ordenar em aberto primeiro), **D3-A** (sem separador visual), **D4-A** (vale em ciclo aberto, fechado e selado).

---

## 1. `sortExpensesByOccurrence` passa a ordenar em aberto primeiro (specify §2.1, D2-B, D4-A)

- Arquivo: `frontend/src/utils/cycleOrdering.ts:24`. Nenhum outro arquivo de produção precisa trocar a chamada: Home (`CycleDetailPanel.tsx:39`), relatório (`ClosingReportPanel.tsx:212`), Despesas (`ExpenseManager.tsx:109`) e Pagamentos (`Payments.tsx:255`) já chamam essa função (specify §2.2).
- Abordagem: o comparador atual (data decrescente, `id` decrescente, parcela decrescente) fica como está e ganha um critério **antes** dele: `paid` falso antes de verdadeiro (`Number(a.paid) - Number(b.paid)`). Continua uma só ordenação sobre uma cópia (`[...expenses].sort`), então o argumento e o `summary` nunca mudam.
- Por que não duas funções: com a regra valendo nas quatro telas, uma função só evita duas ordens que se afastam com o tempo. Por que não "partir em dois grupos e ordenar cada um": o `sort` com o critério à frente dá o mesmo resultado, em menos código.
- `paid` vem do `summary` de qualquer ciclo, inclusive do snapshot selado (`SummaryExpense.paid`, `hooks/useGroupCycle.ts:38`); snapshot antigo sem o campo cairia em `undefined`, que `Number(undefined)` não resolve (NaN). Por isso o critério usa `Boolean(a.paid)`: ausente vale "em aberto".
- O comentário do JSDoc de `sortExpensesByOccurrence` é reescrito: deixa de dizer "o status não decide a posição" e passa a descrever a regra nova e a reversão da R1. O nome da função fica (decisão dele).
- Testes: `utils/cycleOrdering.test.ts:50-111`. O caso "sem priorizar pendentes (§5.1)" (linha 51) é substituído por "em aberto primeiro, dentro de cada grupo por data"; os de desempate por `id` e parcela passam a ser rodados dentro de um mesmo grupo (mesmo `paid`); acrescenta-se: lista sem `paid` tratada como em aberto, e entrada não mutada.

## 2. As quatro telas herdam a regra (specify §2.2)

- Sem mudança de código de produção em `CycleDetailPanel.tsx`, `ClosingReportPanel.tsx`, `ExpenseManager.tsx` e `Payments.tsx`: só os **comentários** que afirmam a ordem antiga são atualizados (`CycleDetailPanel.tsx:37-38`, `ClosingReportPanel.tsx:208-210`, `ExpenseManager.tsx:105-108`, `Payments.tsx:251-254`).
- Os filtros de Despesas e Pagamentos (`useExpenseFilters(sortedExpenses)`) filtram sobre a lista já ordenada e preservam a ordem; o plano não os toca, e o teste de cada tela confirma a ordem com um filtro ativo.
- Testes que **mudam** porque afirmam a ordem antiga:
  - `components/CycleDetailPanel.test.tsx:53` (Home): passa a esperar em aberto primeiro, com um par pago/em aberto.
  - `pages/ExpenseManager.test.tsx:2620` e `pages/Payments.test.tsx:1187` ("não prioriza pendentes"): trocam o título e o esperado.
  - `components/ClosingReportPanel`: se não houver teste de ordem, acrescenta um (ciclo selado com despesa paga e em aberto).
- Por que testar tela a tela e não só a função: o risco real é uma tela futura ou atual passar a ordenar por conta própria e perder a regra; o teste por tela barra isso.

## 3. Documentação (specify §2.2)

- `docs/sdd/05-context-frontend.md:36`: a frase "despesas por data da ocorrência decrescente" passa a "despesas em aberto primeiro e, em cada grupo, por data da ocorrência decrescente (reverte a R1 da feature de organização)", citando esta feature.
- `docs/sdd/01-specify.md`: acrescenta um bullet curto em §3.3 (ordem das despesas do ciclo), citando `docs/feature/20261123-home-despesas-em-aberto-primeiro/` (virará `concluidas/202611/` no arquivamento), e sobe a versão.
- O specify arquivado de `20261026-organizacao-despesas-pagamentos` é snapshot e **não** é editado; a reversão fica registrada aqui e em `01-specify.md`.

## 4. Verificação (todas as seções)

- TDD por task: RED antes de GREEN, mutantes (remover o critério `paid`; inverter o sentido) com backup e `cmp` para restaurar.
- `npx tsc --noEmit`, `npx vitest run --maxWorkers=1` nos arquivos tocados e, no fechamento, a suíte cheia (se um worker estourar o tempo por carga da máquina, rodar o arquivo sozinho).
- Validação local dele: stack isolada (MySQL 3307, backend 8001, Vite 3001), com um grupo de despesas pagas e em aberto de datas misturadas, conferindo Home, Despesas, Pagamentos e o relatório de um ciclo fechado.
- Sem backend, sem migration: `security-reviewer` não se aplica.
