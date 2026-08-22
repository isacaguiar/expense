# Implementation — Reestruturação do Resumo do Grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 20260822 | IA (Claude Code) | `cd frontend && npx tsc --noEmit` — sem erro. `npx vitest run src/pages/GroupSummary.test.tsx` — 12/12 testes passaram (Test Files 1 passed, Tests 12 passed). Verificação visual manual feita pelo usuário em `localhost:3000` (login próprio, fora do escopo do agente). | `Grid container spacing={3}` com `Grid size={{ xs: 12, sm: 12, lg: 8 }}` (despesas) e `Grid size={{ xs: 12, sm: 12, lg: 4 }}` (Saldos por pessoa + Quem paga a quem) em `GroupSummary.tsx`, sem alterar conteúdo/lógica de nenhum bloco — só reposicionamento. Abas Saldo/À pagar ainda não existem (escopo de TASK-002). |
| TASK-002 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/GroupSummary.test.tsx` — 11/12 passaram, 1 falha esperada (`GroupSummary.test.tsx:224`, "shows the 'quem paga a quem' block resolving names from balances") — o texto "Quem paga a quem" não existe mais fora da aba "À pagar" (achado 2.4 do specify.md); correção é escopo de TASK-004, não desta task. | Novo `frontend/src/components/SummarySidePanel.tsx` (Card + Tabs Saldo/À pagar, reaproveita `BalanceCards`/`SettlementList` sem alteração; estado vazio novo "Nenhuma pendência entre os membros neste ciclo." quando `settlements` vazio). `GroupSummary.tsx` importa e renderiza `SummarySidePanel` na coluna direita, removendo os imports diretos de `BalanceCards`/`SettlementList`. |
| TASK-003 | Concluída | 20260822 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/GroupSummary.test.tsx` — 11/12 passaram, mesma 1 falha pré-existente de TASK-002 (`GroupSummary.test.tsx:224`), sem regressão nova. | `SummarySidePanel` ganhou prop `cycleStatus: CycleStatus`; `Chip` "Prévia" (`UpdateIcon`, cor `info`) quando `open`/`future`, "Definitivo" (`PaidOutlinedIcon`, cor `success`) quando `closed`/`closed_manually`, acima da `Tabs`. `GroupSummary.tsx` passa `cycleStatus={summary.cycle.status}`. |
