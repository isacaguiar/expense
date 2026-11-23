# Implementation — <Nome da Feature>

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: <AAAAMMDD>

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-663 | Concluída | 2026-11-23 | Claude | RED: `npx vitest run src/utils/cycleOrdering.test.ts` com os testes novos (em aberto antes de pago, aberta antiga acima de paga recente, `paid` ausente) = 3 falhas, 30 passam. GREEN: 33 passam. Testes de tela ajustados (`CycleDetailPanel`, `ExpenseManager`, `Payments`): `npx vitest run` nos 4 arquivos com `--maxWorkers=1` = 4 arquivos, 227 testes passam; `npx tsc --noEmit` limpo. Mutantes mortos: sem o critério `paid` (4 falhas), sentido invertido (4 falhas); arquivo restaurado e conferido com `cmp`. | Primeira tentativa de mutante não gravou o arquivo (caminho `/tmp` no Windows) e passou sem mutação: descartada e refeita no próprio arquivo, com backup. Comentários de `CycleDetailPanel`, `ClosingReportPanel`, `ExpenseManager` e `Payments` ficam nas tasks 664 a 667. |
| TASK-664 | Concluída | 2026-11-23 | Claude | `CycleDetailPanel.test.tsx`: teste novo `it.each` (ciclo aberto, fechado manualmente, fechado e acertado) com despesa em aberto antiga acima de pagas recentes; comentário do painel reescrito. `npx tsc --noEmit` limpo; `npx vitest run src/components/CycleDetailPanel.test.tsx` = 8 testes passam. Mutante morto: a Home voltando a ordenar só por data derruba 4 testes; arquivo restaurado e conferido com `cmp`. | Sem RED próprio: a regra já veio da TASK-663 (a Home herda a função), então o teste novo passou de primeira e a prova é o mutante. Primeira versão usava o status `sealed`, que não existe (`CycleStatus` = closed, open, future, closed_manually): corrigida para os três casos acima. |
| TASK-665 | Concluída | 2026-11-23 | Claude | `ExpenseManager.test.tsx`: 2 testes novos (tabela e cards) com massa própria `comBusca` e a busca "a" (deixa a em aberto antiga e a paga recente juntas); comentário da tela reescrito. `npx tsc --noEmit` limpo; `npx vitest run src/pages/ExpenseManager.test.tsx --maxWorkers=1` = 113 testes passam. Mutante morto: a tela ordenando só por data derruba 3 testes (inclui os 2 novos); arquivo restaurado e conferido com `cmp`. | Sem RED próprio (a regra veio da 663); a prova é o mutante. Duas tentativas descartadas: o filtro "Variáveis" dava a mesma ordem por data pura (o teste passava com o mutante) e o filtro por credor não funcionou porque a massa tem um só `userPayerId`; só a busca por texto discriminou. |
| TASK-666 | Concluída | 2026-11-23 | Claude | `Payments.test.tsx`: 1 teste novo (cards com a busca "a", massa `comBusca`); comentário da tela reescrito. `npx tsc --noEmit` limpo; `npx vitest run src/pages/Payments.test.tsx --maxWorkers=1` = 79 testes passam. Mutante morto: a tela ordenando só por data derruba 4 testes (inclui o novo); arquivo restaurado e conferido com `cmp`. | Sem RED próprio (regra da 663); a prova é o mutante. Reaproveitei a massa e a busca que discriminaram na 665. |
