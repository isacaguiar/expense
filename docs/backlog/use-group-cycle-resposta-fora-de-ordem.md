# `useGroupCycle` aceita resposta fora de ordem e mantém o resumo antigo durante o reload

ID: 101
Origem: docs/bugfix/20261024-alerta-ciclo-fechado-apos-pagamento.md (hipótese "dados desatualizados", verificada à parte da causa do relato)
Criado em: 2026-10-24
Prioridade: BAIXA
Status: Aberto

## Descrição
Dois achados em `frontend/src/hooks/useGroupCycle.ts`, ambos por leitura de código em 2026-10-24 (**nenhum reproduzido em execução**):

1. **Resposta fora de ordem.** O efeito de `:149-171` dispara um `GET summary` por mudança de `cyclesAgo`/`reloadToken` e aplica o resultado com `setSummary(res.data)` sem cancelar a requisição anterior nem descartá-la. Um `reload()` e uma troca de ciclo em voo ao mesmo tempo podem aplicar um `summary` de outro ciclo do que o `cyclesAgo` atual. Dado de ciclo errado, não só aviso errado.
2. **Resumo antigo durante o reload.** `:152` faz `setLoading(true)` sem limpar `summary`. `ExpenseManager.tsx:646` renderiza `CycleClosingAlert` fora do ramo `loading` (`:659`), então o aviso do estado anterior fica na tela, ao lado do spinner, até a resposta chegar. Se o reload falhar, `error` é preenchido e o `summary` antigo segue vivo.

Correção provável: descartar respostas de efeitos já substituídos (flag no cleanup ou `AbortController`) e decidir, na tela, se o alerta some enquanto `loading`. Antes de mexer, reproduzir o item 1 com um teste que atrasa a primeira resposta.

## Por que importa
O hook é compartilhado por `GroupSummary`, `ExpenseManager` e `Payments`. Hoje a janela é de uma requisição, então o dano é pequeno; a corrida só aparece com rede lenta e cliques rápidos nas setas de ciclo.

Tipo sugerido: frontend (provável BFF se a reprodução confirmar o item 1)
