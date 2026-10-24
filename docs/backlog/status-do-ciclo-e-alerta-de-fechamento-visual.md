# Status do ciclo e alerta de fechamento: distinguir fechado, revisável e quitado, com mais destaque

ID: 100
Origem: docs/bugfix/20261024-alerta-ciclo-fechado-apos-pagamento.md (investigação do alerta incoerente; melhoria separada do bugfix por ser comportamento novo)
Criado em: 2026-10-24
Prioridade: BAIXA
Status: Aberto

## Descrição
Melhorar como as telas comunicam em que pé está o ciclo selecionado. Pontos medidos no código em 2026-10-24:

- **"Quitado" não tem estado próprio.** `cycleStatusChip` (`frontend/src/hooks/useGroupCycle.ts:91-99`) só conhece `closed`, `open`, `future` e `closed_manually`. Ciclo selado volta `status='closed'` com `settled=true` (`ExpenseController.php:678`), então "fechado com pendências" e "quitado e selado" mostram o mesmo chip cinza "Ciclo fechado". O `settled` só é lido para esconder botões (`Payments.tsx:344-348`).
- **O status nem sempre fica acima do alerta, nem existe em todas as telas.** `GroupSummary.tsx:56` renderiza o alerta antes do chip, que mora dentro de `CycleDetailPanel.tsx:37-44`. `ExpenseManager.tsx:646` mostra o alerta e **não tem chip**. `Payments.tsx:224-229` tem chip e **não renderiza o alerta**.
- **Destaque e contraste.** O chip "Ciclo fechado" é `color="default"` (cinza), sem ícone. O `Alert` do MUI já traz ícone + texto; o contraste de ambos **não foi medido**, e só deve ser afirmado depois de medir (WCAG 1.4.3, e a cor não pode ser o único sinal, 1.4.1).
- **Estado "acertos confirmados, despesas ainda pendentes".** Hoje o alerta de fechamento some nesse estado (decisão do bugfix). Se o produto quiser um aviso informativo ("falta o credor marcar N despesa(s) como paga(s)"), é texto e estado novos, e entra aqui.

Entregável provável: um indicador de status único (ícone + texto + cor) acima do alerta, nas três telas, com os estados Em andamento / Fechado / Fechado (revisável) / Quitado, e o aviso informativo opcional acima. O critério dos devedores do alerta **não** faz parte disto (é o bugfix).

## Por que importa
O usuário tem de ler o chip e o alerta para saber se ainda deve algo ou se o ciclo está encerrado; hoje "quitado" e "fechado com pendências" se confundem no chip. Risco baixo: só apresentação.

Tipo sugerido: frontend (comportamento novo → entra por `/promover-backlog`, não por BFF)
