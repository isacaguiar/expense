# Implementation — Aviso de ciclo fechado nomeia quem falta acertar

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261123

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui; a TASK-670 inclui o ajuste dos testes que afirmavam o texto antigo, como o plan.md §2 previu.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-670 | Concluída | 2026-11-23 | Claude | RED: testes reescritos para as linhas novas, `npx vitest run --maxWorkers=1 src/components/CycleClosingAlert.test.tsx` = 9 falhas, 5 passam. GREEN: `CycleClosingAlert.tsx` com `pendingSettlementLines` (uma linha por acerto sem `confirmedAt`, nomes de devedor e credor por `balances`, "Desconhecido" de reserva) e `<ul>` dentro do `Alert` `warning`; `npx tsc --noEmit` limpo; `CycleClosingAlert.test.tsx` + `ExpenseManager.test.tsx` = 2 arquivos, 127 testes passam. Mutantes mortos (arquivo restaurado e conferido com `cmp`): credor com `from_user_id` (9 falhas), deduplicar por devedor (1 falha), `confirmedAt` invertido (10 falhas). | Casos novos: devedor com dois credores (duas linhas), credor ausente em `balances`, ordem de `settlements` diferente da de `balances`. Suíte cheia no HEAD: `npx tsc --noEmit` limpo e `npx vitest run --maxWorkers=1` = 110 arquivos, 1375 testes passam. |
| TASK-671 | Concluída | 2026-11-23 | Claude | `docs/sdd/05-context-frontend.md` v1.7 → v1.8 (aviso de ciclo fechado "devedor → credor" por acerto pendente, junto às regras de apresentação); `docs/sdd/01-specify.md` v1.10 → v1.11 (bullet "Aviso de ciclo fechado nomeia quem falta acertar" em §3.3). `git diff --stat` lista só esses dois arquivos de `docs/sdd`. | O caminho da feature nos dois docs é `docs/feature/20261123-...`; troca para `concluidas/202611/` no arquivamento (ADR-009). |
