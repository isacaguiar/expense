# Ações de pagamento identificam o ciclo por `cycles_ago` (relativo a "agora"), sem teto nem conferência

ID: 090
Origem: docs/feature/20261010-painel-financeiro-pessoal/implementation.md §8 (achado S5 da revisão de segurança da TASK-414) e §29; `backend/app/Http/Controllers/ExpenseController.php` (`pay`, `unpay`, `confirmSettlement`, `summary`, `grossDebts`)
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
As ações que mexem em dinheiro escolhem a competência por `cycles_ago`, um número **relativo ao instante da chamada** (`BillingCycle::cycleFor($group->closing_day, Carbon::now(), $cycles_ago)`):

| Rota | Validação de `cycles_ago` hoje |
|---|---|
| `POST /expenses/{id}/pay` (`pay`, linha 1021) | `nullable\|integer\|min:0` |
| `POST /expenses/{id}/unpay` (`unpay`, linha 1104) | `nullable\|integer\|min:0` |
| `POST /groups/{id}/settlements/confirm` (`confirmSettlement`, linha 1161) | `nullable\|integer\|min:0` |
| `GET /groups/{id}/summary` (linha 663) e `GET /groups/{id}/gross-debts` (linha 913) | `nullable\|integer` (aceita negativo, isto é, ciclo futuro; só leitura) |

Dois pontos:

1. **Sem teto.** Nenhuma rota limita o recuo. Medido com `BillingCycle::cycleFor(10, 2026-10-22, n)`: `n = 1000` resolve 11/06/1943 a 10/07/1943 e `n = 100000` resolve o ano -6307, sem exceção. O que `pay` e `confirmSettlement` fazem com uma competência dessas **não testei**; o esperado é `422` (sem ocorrência) ou acerto vazio, mas é inferência. Falta um limite coerente com o `FOCUS_LOOKBACK` do restante do sistema.
2. **O número envelhece.** O cliente guarda o `cycles_ago` que a tela mostrou e o reenvia na ação. Se o ciclo vira (o `closes_at` ou o dia de fechamento passa) entre o carregamento da tela e o clique, o **mesmo número aponta para outra competência**: `pay` pode marcar como paga a ocorrência da competência errada (numa despesa Fixa ou Parcelada, que tem ocorrência em ciclos vizinhos) e `confirmSettlement` recalcula o acerto no ciclo novo, podendo confirmar o de outro ciclo se o mesmo par tiver acerto lá. Nenhuma resposta avisa que o ciclo mudou. O Painel agrava a chance, porque é uma tela feita para ficar aberta e agir.

Não reproduzi o cenário de virada de ciclo: está lido no código, não testado. O payload já devolve `cycle.start` e `cycle.end` absolutos (`/me/overview`, `summary`), então o cliente tem o dado para se identificar sem ambiguidade.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Contrato **aditivo** (Constitution §4.1): quem hoje manda só `cycles_ago` continua funcionando. |
| R2 | A decisão de qual ciclo foi pago é do servidor, e o cliente nunca recalcula ciclo. A conferência só pode **recusar**, nunca escolher outro ciclo em silêncio. |
| R3 | Teste com `Carbon::setTestNow` que vira o ciclo entre o "carregamento" e a ação. Mutação: remover a conferência derruba o teste. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como o cliente se identifica | A) aceitar `cycle_start` (data absoluta, que o payload devolve) no lugar de `cycles_ago`<br>B) manter `cycles_ago` e aceitar `cycle_start` opcional **só como conferência**: se não bater com o ciclo calculado, `409` ("o ciclo mudou, atualize a tela")<br>C) só pôr teto em `cycles_ago` | **B** | Mexe o mínimo: o servidor continua resolvendo o ciclo como hoje e só recusa quando a tela está velha. A exige validar que a data é uma fronteira válida para o `closing_day` do grupo. C não resolve o envelhecimento. |
| D2 | Teto de `cycles_ago` (complemento de B) | A) `max` alinhado ao `FOCUS_LOOKBACK` (12)<br>B) `max` maior, por exemplo 120<br>C) sem teto | **B**, valor a confirmar | Recuar além de 12 ciclos é legítimo em Relatórios (histórico longo), então 12 seria restritivo demais. |
| D3 | `min:0` nas leituras `summary` e `gross-debts` | A) acrescentar<br>B) deixar | **B** | São só leitura. O botão "Próximo ciclo" do `GroupGrossDebtsPanel` não é desabilitado em 0 e envia `-1` (ciclo futuro), então hoje o web depende de aceitar negativo. Decidir junto, sem urgência. |

## Por que importa
Pagamento marcado no mês errado é dado financeiro incorreto, e desfazer exige outra ação do credor. A chance é pequena (precisa de uma tela aberta na virada do ciclo), mas o Painel e o futuro app (074) são telas de uso contínuo.

Relacionado: 093 (custo do `focus()`), 091.

Tipo sugerido: backend (+ frontend e app, que passam a enviar `cycle_start`)
