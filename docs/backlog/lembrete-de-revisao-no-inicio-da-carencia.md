# Lembrete de revisão no início da carência do ciclo

ID: 110
Origem: docs/feature/20261027-pagamento-no-cadastro-despesa/specify.md §3.4 (D12); `backend/app/Http/Controllers/ExpenseController.php` (`summary()`, `sealCycleIfSettled`), `backend/app/Support/Notifier.php`, `backend/app/Support/BillingCycle.php`
Criado em: 2026-10-27
Prioridade: MEDIA
Status: Aberto

## Descrição
Quando o ciclo termina, começam os 5 dias de carência (`BillingCycle::GRACE_DAYS`), em que ele segue editável até a data de corte (`closes_at`). Hoje ninguém é avisado dessa virada: nada roda por data (o agendador do `Kernel` está vazio, ADR-006 e ADR-008), e o único aviso é o `CycleClosingAlert`, passivo, que só quem abre o app vê. Proposta: avisar todos os membros do grupo, uma vez por ciclo, no início da carência, pedindo para revisar ou lançar despesas antes do encerramento.

Segue o padrão de hoje, **sem cron**: na primeira leitura de `ExpenseController::summary()` durante a carência (`status` `open` e `in_grace`), se não houver marcador de envio para aquele grupo e ciclo, dispara um `Notifier` novo a todos os membros e grava o marcador, como `sealCycleIfSettled` faz com `cycleSettled` (a checagem `wasSealed`). O gancho fica **só** em `summary()`: o `view()` do Painel é deliberadamente sem efeito colateral.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Um envio por (grupo, ciclo), com marcador que sobrevive a reinício e a leituras concorrentes. |
| R2 | Só dispara com o ciclo em carência; ciclo selado, fechado ou futuro não dispara. |
| R3 | Tipo novo de notificação in-app; sem template novo na Meta. |
| R4 | Contrato aditivo (Constitution §4 item 1). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde guardar o marcador | A) coluna nova em `ex_group_cycle_snapshots`<br>B) tabela própria<br>C) consulta de existência em `ex_notifications` | **B** | O snapshot só existe depois de selar ou fechar; C acopla a regra ao texto da notificação. |
| D2 | Canal | A) só in-app<br>B) in-app e WhatsApp | **A** | WhatsApp exige template novo aprovado na Meta. |

## Por que importa
Dispara na primeira abertura do app na carência, não no instante exato. Mesmo assim fecha a lacuna de ninguém saber que o ciclo está para encerrar, e dá ao credor a chance de corrigir uma compra paga no cadastro antes de ela virar histórico (a janela de correção da feature `20261027-pagamento-no-cadastro-despesa` acaba com o encerramento do ciclo).

Tipo sugerido: backend + frontend
