# Acompanhamento: `pending-cycles` faz 127 a 182 consultas e leva 0,7 a 0,9 s por abertura da aba

ID: 104
Origem: docs/feature/20261006-relatorios-acompanhamento-fechamento/implementation.md §4.2 (medição da TASK-497) e §3.2 achado 1 (revisão de segurança da TASK-496); `backend/app/Services/CycleSummaryService.php` (`pendingCycles`, `view`, `viewAt`, `compute`, `entries`)
Criado em: 2026-10-25
Prioridade: BAIXA
Status: Aberto

## Descrição
`GET /api/groups/{groupId}/expenses/pending-cycles`, a rota da aba **Acompanhamento** (a que abre por padrão em Relatórios), varre até 13 ciclos e **calcula ao vivo cada ciclo que não está selado**. Medido em 2026-10-25 num MySQL 8.0.17 em contêiner Docker no Windows, máquina compartilhada, `php -S` com opcache, dados semeados descartáveis (`implementation.md` §4.1):

| Cenário | Consultas | HTTP, ponta a ponta (mediana de 5, após a 1ª) |
|---|---|---|
| Grupo misto (13 ciclos: 3 selados, 2 quitados sem selo, os demais pendentes) | **127** | **0,73 s** |
| Pior caso (12 ciclos pendentes, com despesa à vista, parcelada e fixa projetada) | **182** | **0,92 s** |
| Rotas de leitura vizinhas (`summary`, `focus-cycle`, `cycles`) | 5 a 24 | 0,30 a 0,42 s |

O piso de latência deste MySQL é de 2,0 ms por consulta, então ~370 ms dos ~890 ms do pior caso são ida e volta ao banco **neste ambiente**. **Não medi em produção**, onde o banco costuma estar mais perto do PHP; a contagem de consultas é a mesma lá, o tempo deve ser menor.

**Onde estão as consultas** (grupo misto, 127): `ex_users` 44, `ex_expenses` 29, `ex_quotas` 22, `ex_settlement_confirmations` 15, `ex_group_cycle_snapshots` 13, mais 4 fixas. No pior caso a proporção é parecida: `ex_users` é **35 a 42%**, porque as cargas antecipadas de pagador e participantes se repetem **a cada ciclo**. Mapa completo por tabela e por ciclo em `consultas-tela-relatorio.md` seção 4.

**O que dá para cortar sem mudar o resultado** (hipóteses, nenhuma implementada ou medida):

1. **Não calcular o ciclo vigente**, que o laço computa (9 a 15 consultas) e descarta (`pendingCycles` o pula depois: ainda aberto, fora da carência).
2. **Ler os snapshots dos 13 ciclos numa consulta** e pular o selado sem chamar `view()`, como `focus()` já faz (13 → 1, e some o par snapshot + confirmações de cada ciclo selado).
3. **Carregar os usuários da despesa uma vez** (ou reaproveitar `$group->members`), não por ciclo: é a maior fatia.
4. **Não repetir a leitura de confirmações** no ciclo quitado sem selo (`withConfirmations` e `summaryIsFullySettled` leem a mesma linha).

A conta que sustenta o teto: ~5 consultas fixas + até 13 ciclos × 9 a 15. O agente `security-reviewer` estimou ~200 no pior caso (todas as fontes populadas em todos os ciclos); o que medi foi 182.

## Por que importa
Pouco hoje: é menos de 1 s no pior caso medido, a rota é de leitura, e o limitador `api` já a protege em 60 chamadas por minuto por IP (a rota não tem limite próprio, ao contrário de `me/overview`, com 30 por minuto por usuário). Mas é a aba padrão de Relatórios, faz 5 a 9 vezes as consultas das rotas vizinhas e cresce com o número de ciclos pendentes de um grupo. Se um dia o grupo típico tiver muitos ciclos abertos, ou o banco de produção estiver mais longe do PHP, a aba fica lenta antes das outras. Reavalie com um número de produção.

## Decisões em aberto
A fechar no specify, **depois de medir em produção** (acesso de leitura é gate humano).

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Medir em produção antes | A) contar, por grupo, quantos ciclos o Acompanhamento varre de fato e quanto a rota leva<br>B) decidir sem medir | **A** | A prioridade BAIXA só se sustenta se o grupo típico tiver poucos ciclos pendentes. Medido aqui só com dados semeados. |
| D2 | Como reduzir | A) os 4 cortes acima, nessa ordem (1 e 2 são os mais baratos e seguros)<br>B) limite de taxa próprio, como o do `overview`, sem mexer na varredura<br>C) memorizar o resultado por grupo com invalidação | **A**, e **B** só se a medição de produção pedir | A e B não mudam o resultado. C exige invalidar em `pay`, `unpay`, edição, confirmação e reabertura, e um "pendente" velho engana a tela. |

Regra para qualquer correção: **o resultado de `pendingCycles` não muda** (a suíte `ExpenseControllerPendingCyclesTest`, `FocusCycleTest` e `CycleSummaryServiceTest` seguem verdes sem edição) e a leitura continua sem gravar snapshot nem notificar.

Relacionado: 093 (custo do `focus()` com os mesmos ciclos quitados e não selados), 102 (esses ciclos nascem de `update`/`destroy`/`FIXED`, que não selam: selar fora da leitura também baratearia esta rota), `consultas-tela-relatorio.md` seção 4.

Tipo sugerido: backend
