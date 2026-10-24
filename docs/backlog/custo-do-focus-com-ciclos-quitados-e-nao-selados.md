# Custo do `focus()` com ciclos quitados e nunca selados: 2.352 consultas e 7,4 s com 50 grupos

ID: 093
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/implementation.md §29 (achado R1 da revisão final, medido pelo autor) e §9; `backend/app/Services/CycleSummaryService.php` (`focus()`, `FOCUS_LOOKBACK = 12`), `backend/app/Services/PersonalOverviewService.php`
Criado em: 2026-10-22
Prioridade: MEDIA
Status: Aberto

## Descrição
`GET /api/me/overview` chama `CycleSummaryService::focus()` e `view()` uma vez por grupo (até `MAX_GROUPS = 50`). O `focus()` varre até `FOCUS_LOOKBACK = 12` ciclos para trás procurando o primeiro com pendência. Um ciclo **selado** sai de uma consulta em lote ao snapshot. Um ciclo **fechado, totalmente quitado e nunca selado** não tem snapshot e passa por `compute()` a cada chamada (cerca de 4 consultas por ciclo). O `focus()` só pula, sem calcular, os ciclos **anteriores à primeira entrada** do grupo (`firstPossibleEntryDate()`).

**Medido em 2026-10-22** (banco de testes MySQL em Docker, `artisan serve` no Windows, "agora" = 15/10/2026, grupos com uma despesa antiga já quitada e sem snapshot; sonda descartável, apagada):

| Grupos | Consultas | Tempo |
|---|---|---|
| 1 | 49 | 473 ms |
| 10 | 472 | 1.656 ms |
| 50 | **2.352** | **7.390 ms** |

Não medi em produção, e lá (PHP-FPM, banco local) o tempo deve ser menor; o número de consultas é o mesmo. O "~300 consultas no pior caso" que constava na documentação valia só para grupos **vazios** e foi corrigido (`implementation.md` §9, §23, §29 e `plan.md` §8).

**De onde vêm ciclos quitados e não selados.** Dois casos identificados no código:

1. **Ciclo só com parcela retroativa `bornPaid`.** Por desenho nunca sela: `sealCycleIfSettled` não congela ciclo sem despesa real nem acerto, e o `focus()` o pula "mesmo sem selar" (comentário no método). Um grupo com parcelamento retroativo (caso real do produto) pode ter vários desses ciclos; não contei quantos existem em produção.
2. **Ciclo quitado por um caminho que não chama a selagem** e que ninguém abriu depois em Pagamentos. A selagem é preguiçosa: roda em `summary()`, `pay()`, `confirmSettlement()` e `close()`. O `/me/overview` **não sela** (decisão da feature: GET só lê), então quem usa só o Painel não ajuda a selar. Quantos grupos reais caem aqui, não sei. O plan de Relatórios (`docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md` §0.1.2 e §8) lista três caminhos concretos: (a) editar ou excluir despesa **na janela de carência**, porque `update()` e `destroy()` não selam e a trava de ciclo fechado ainda não vale; (b) despesa `FIXED`, que `rejectIfCycleClosed` dispensa; (c) ciclo quitado antes de a selagem existir (feature `20260902`). Esse plan também mostra o estado como "Quitado" no Acompanhamento e prevê registrar no backlog que `update()`/`destroy()` deveriam selar.

**Mitigações que já existem:** teto de 50 grupos com `truncated` (D9), limite de 30 chamadas por minuto por usuário (D10) e o `focus()` sem varredura de ciclos anteriores à primeira entrada. O pior caso fica fixo, mas fixo em 2.352 consultas por chamada.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Corrigir o **custo**, não a conta: o resultado de `focus()` e de `view()` não muda (mesma suíte de equivalência `focus` × varredura que a feature já tem). |
| R2 | Leitura segue sem gravar snapshot nem notificação, a menos que o specify decida o contrário e passe pelo gate (selar dispara `Notifier::cycleSettled`). |
| R3 | O contrato de `/me/overview` não muda (aditivo, Constitution §4.1). |

## Decisões em aberto
A fechar no specify, **depois de medir**.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Medir antes | A) uma consulta de leitura em produção contando grupos com ciclos fechados, quitados e sem snapshot (e quantos ciclos cada)<br>B) decidir sem medir | **A** | A prioridade MEDIA só se justifica se o caso for comum. Exige acesso de leitura a produção, que é gate humano. |
| D2 | Como baixar o custo | A) memorizar "ciclo fechado e quitado" (cache ou coluna) com invalidação em `unpay`, edição e reabertura<br>B) selar fora da leitura (comando agendado) os ciclos fechados e quitados, **sem** `Notifier` no retroativo<br>C) decidir em lote, com uma consulta por grupo, os ciclos que não têm nenhuma cota viva além das `bornPaid` nem confirmação de acerto (quitados por definição, sem `compute()`), e calcular só os demais<br>D) baixar o teto de grupos ou o `FOCUS_LOOKBACK` | **C para o caso 1; B para o caso 2**, este só se a medição (D1) mostrar muitos | B não cobre o caso 1 (`bornPaid` nunca sela por desenho). C não cobre o caso 2: um ciclo com todas as cotas pagas ainda pode ter acerto sem confirmação, e isso só o `compute()` diz. A exige invalidação correta, e um "quitado" velho mostra a pessoa como quitada sem estar. D esconde o custo e piora o produto (grupos somem do Painel). |

## Por que importa
Hoje o custo está contido (50 grupos, 30 chamadas por minuto), mas uma chamada de 7 s de CPU de PHP e 2.352 consultas por usuário é o tipo de coisa que aparece como lentidão do Painel e do futuro app (074) em quem tem muitos grupos. Quanto mais grupos com histórico, pior.

Relacionado: 090 (outra decisão sobre o ciclo em foco), 074.

Tipo sugerido: backend
