# Specify — Layout de Duas Colunas na Página de Despesas

> Feature: aplica na página `/groups/{groupId}/expenses` (`ExpenseManager.tsx`) a mesma distribuição de duas colunas fechada em `docs/feature/20260822-reestruturacao-resumo/`, trocando a listagem de despesas de cards para tabela e o bloco de saldo por um painel com abas Saldo/À pagar (reaproveitando `SummarySidePanel`) — só estruturação de layout, nenhum link/ação/API existente é removido ou alterado. Origem: pedido novo do usuário nesta conversa, sem task/épico prévio em `03-tasks.md`.

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

A página `/groups/{groupId}/expenses` (`ExpenseManager.tsx`) já tem um grid de duas colunas (introduzido pela `TASK-173` de `docs/feature/20260821-melhoria-despesas/`), mas com proporção diferente da que acabou de ser fechada em `GroupSummary.tsx` (`docs/feature/20260822-reestruturacao-resumo/`): breakpoints `xs`/`md` 8/4 em vez de `xs`/`sm`/`lg`, listagem em grid de cards em vez de tabela, e bloco de saldo mostrando só `BalanceCards`, sem a aba "À pagar" (liquidação par-a-par) que `GroupSummary.tsx` já tem. O usuário quer unificar a distribuição visual entre as duas telas.

## 2. Achados confirmados

### 2.1 Estado atual do grid em `ExpenseManager.tsx`

`ExpenseManager.tsx:352-490`: `<Grid container spacing={3}>` com `<Grid size={{ xs: 12, md: 8 }}>` (busca, filtro, listagem) e `<Grid size={{ xs: 12, md: 4 }}>` (título "Saldo por pessoa" + `<BalanceCards balances={summary.balances} />`, sem nenhuma aba). A listagem (`filteredExpenses`, linhas 380-481) é outro `<Grid container spacing={2}>` de cards (`Grid size={{ xs: 12, sm: 6 }}` por despesa).

### 2.2 Decisão anterior registrada explicitamente contra tabela tradicional — esta feature reverte por pedido direto do usuário

`docs/feature/20260821-melhoria-despesas/specify.md:21`: "O estilo de card da imagem é a base visual da listagem principal (**não vira tabela tradicional linha/coluna**)". Essa decisão foi tomada com base no mockup `tela-despesas.png` na época. O usuário pediu explicitamente nesta conversa a reversão só para `ExpenseManager.tsx` ("mudar a apresentação para tabular ao invés dos cards") — registrado aqui para não parecer descuido, é reversão deliberada e escopada a esta única tela.

### 2.3 `SummarySidePanel` já existe, pronto e reutilizável sem mudança

`frontend/src/components/SummarySidePanel.tsx` (criado em `docs/feature/20260822-reestruturacao-resumo/`, já em `dev`): recebe `balances`, `settlements`, `cycleStatus` via props, renderiza `Tabs` "Saldo"/"À pagar" (reaproveitando `BalanceCards`/`SettlementList`) e o selo "Prévia"/"Definitivo". `ExpenseManager.tsx` já usa o mesmo hook `useGroupCycle` (linha 59) que expõe `summary.balances`, `summary.settlements` e `summary.cycle.status` — os mesmos dados que `GroupSummary.tsx` passa pro componente. Não precisa de nenhum dado novo nem chamada de API adicional.

### 2.4 Cada card hoje é um link + ações condicionais — tudo isso precisa sobreviver na tabela

`ExpenseManager.tsx:384` (`CardActionArea component={Link} to={`/groups/${groupId}/expenses/${exp.id}`}`) faz o card inteiro navegar pro detalhe da despesa. `CardActions` (417-476) mostra, condicionalmente: remover despesa fixa (`exp.isFixed && cycleIsOpen`), editar (`canEdit`), excluir (`canDelete`), marcar como paga (`canPay`), desfazer pagamento (`canUnpay`) — cada `can*` já combina `cycleIsOpen` com posse/credor (linhas 279-282). Isso não pode ser removido nem ter a condição alterada — só a apresentação (linha de tabela em vez de card) muda.

### 2.5 Campos hoje exibidos por despesa (viram colunas da tabela)

Por card: ícone de tipo (`AutorenewOutlinedIcon`/`ReceiptOutlinedIcon`, linhas 388-392), descrição (393), chip "Fixa"/"Variável" (396), chip status "Paga"/"Pendente" (397-401), valor formatado (404-406), data + credor (`payerName`, 407-409), lista de participantes com tooltip (410-414).

### 2.6 Busca e filtro por tipo já operam client-side sobre a lista renderizada

`search`/`typeFilter` (linhas 73-74) produzem `filteredExpenses` (259-265), consumido diretamente pela listagem (377-481). A tabela nova consome a mesma `filteredExpenses` sem mudar a lógica de filtro.

## 3. Requisitos

- **R1**: A listagem de despesas na coluna esquerda passa a ser uma tabela (uma linha por despesa), com uma coluna por campo hoje exibido no card (achado 2.5): tipo, descrição, valor, data, credor, participantes, status. Preserva a navegação para o detalhe da despesa e todos os ícones de ação condicionais hoje em `CardActions` (achado 2.4) — nenhuma ação ou link é removido.
- **R2**: Busca e filtro por tipo continuam filtrando a mesma lista (`filteredExpenses`, achado 2.6), agora renderizada como linhas de tabela — sem mudança na lógica de filtro.
- **R3**: A coluna direita passa a usar `SummarySidePanel` (achado 2.3) no lugar do bloco atual só com `BalanceCards`, ganhando a aba "À pagar" e o selo "Prévia"/"Definitivo" — sem duplicar cálculo, reaproveitando os mesmos dados que `useGroupCycle` já expõe nesta página.
- **R4**: Nenhum botão, link, diálogo ou fluxo hoje existente na página (Nova Despesa, navegação de competência, fechar/reabrir mês, diálogos de remoção/exclusão, snackbars de sucesso/erro) é removido ou tem comportamento alterado — a mudança é só de apresentação.
- **R5**: O grid principal (`ExpenseManager.tsx:352`) passa a usar o mesmo esquema de breakpoints já fechado em `GroupSummary.tsx` (`xs`/`sm`/`lg`, ~66%/33% em telas largas) no lugar do atual `xs`/`md` 8/4 — unifica a distribuição visual entre as duas telas.

## 4. Fora de escopo desta feature

- Alterar API, regra de negócio de despesas, saldo ou liquidação — reaproveita exatamente o que `useGroupCycle` já expõe (achado 2.3).
- Alterar o estilo de card em qualquer outra tela do sistema — a reversão da decisão "sem tabela tradicional" (achado 2.2) vale só para a listagem de `ExpenseManager.tsx`.
- Paginação ou ordenação de colunas na tabela nova — a listagem hoje não pagina nem ordena, a tabela não introduz isso.
- Redesenho de navegação global (sidebar) ou rebrand visual — já fora de escopo nas duas features anteriores desta mesma tela/família (`docs/feature/20260818-resumo-grupo-dashboard/specify.md` §4, `docs/feature/20260822-reestruturacao-resumo/specify.md` §4) e não reaberto aqui.
