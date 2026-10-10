# `update()` de despesa não confere se a nova data cai numa competência fechada

ID: 077
Origem: docs/feature/20261008-rateio-parcelas-no-backend/implementation.md (achado da TASK-401); `backend/app/Http/Controllers/ExpenseController.php` (`update()`, `rejectIfCycleClosed`, `store()`, `rejectIfCompetenceClosed`)
Criado em: 2026-10-09
Prioridade: MEDIA
Status: Aberto

## Descrição
O `store()` recusa criar uma despesa À Vista ou Fixa com `date_payment` numa competência fechada (`rejectIfCompetenceClosed`) e uma Parcelada inteira em competências fechadas. O `update()` só confere a competência da data **já gravada** (`rejectIfCycleClosed($expense)`, no topo): a **nova** `date_payment` do payload não é conferida. Um `PUT` pode mover uma despesa de uma competência aberta para uma fechada, e as quotas vão junto.

Já valia antes da feature `rateio-parcelas-no-backend` para quem mandava `quotas` com `date_expected` em ciclo fechado ou trocava o tipo. Desde a TASK-401 basta mandar só `date_payment`, porque o servidor refaz as quotas na data nova. Ao que tudo indica o web também alcança isso, pelo campo Data do modo de edição (item 040); confirmar na Triagem.

Efeito: altera os valores de um mês já fechado. Se o ciclo não está selado, o resumo recalcula ao vivo e passa a mostrar a despesa ali; se está selado, a foto fica congelada e a quota nova fica fora dela.

Caminho provável: é defeito em comportamento existente, então vai por `/novo-bug` (BFF) com a Triagem; se marcar algum gatilho, vira feature.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A conferência olha o **resultado final** da edição (data final e quotas finais), não só o payload. |
| R2 | Quem não muda a data não é afetado: o `update()` atual, sem `date_payment` novo, segue igual. |
| R3 | Reaproveita as mensagens e os 422 que o `store()` e o `rejectIfCycleClosed` já devolvem. |

## Decisões em aberto
A fechar na Triagem.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Regra para À Vista e Fixa | A) a mesma do `store()` (`rejectIfCompetenceClosed` sobre a nova data)<br>B) só recusar se a competência estiver selada | **A** | Uma regra só para criar e editar; B deixa editar mês fechado que ainda recalcula ao vivo. |
| D2 | Regra para Parcelada | A) a do `store()`: recusar se **todas** as quotas finais caem em ciclo fechado<br>B) recusar se **qualquer** quota nova cair em ciclo fechado | **A** | A Parcelada retroativa é permitida de propósito (ao menos uma parcela em ciclo aberto, `20260903-despesa-parcelada-retroativa`); B a quebraria na edição. |
| D3 | Quotas enviadas pelo cliente (`quotas.*.date_expected`) | A) conferir também<br>B) conferir só a `date_payment` | **A** | Senão a brecha continua por `quotas` com `date_expected` em ciclo fechado. |

## Por que importa
O histórico de uma competência fechada é imutável por desenho: `rejectIfCycleClosed` no `update()` e `destroy()`, e `rejectIfCompetenceClosed` no `store()`. Editar a data é um caminho em que essa garantia falha em silêncio, e, desde a TASK-401, ele não exige mais montar `quotas` à mão.

Tipo sugerido: backend
