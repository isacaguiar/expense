# Responsável que também é o credor recebe o aviso da própria confirmação

ID: 143
Origem: revisão final da branch da feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/` (REV-1; specify R14); `backend/app/Support/Notifier.php` (`fanOut` não pula o autor), `ExpenseController::confirmSettlement` (aviso quando a confirmação é criada) e `backend/app/Support/WhatsApp/WhatsAppNotifier.php` (`settlementProofConfirmed`, que escreve para o credor)
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Concluído (PR #274)

## Descrição
Se o responsável de um devedor é também o **credor** do acerto, o backend aceita o envio do comprovante (R14: sem efeito financeiro) e avisa o credor, que é o próprio autor: ele recebe no sino "X confirmou um pagamento de R$ … por Y" e, com o WhatsApp ligado e o aceite dele, também a mensagem de WhatsApp, sobre a ação que acabou de fazer. O teste `test_responsible_who_is_also_the_creditor_is_accepted` cobre a aceitação e o `confirmed_by`, não o aviso.

Só acontece por **chamada direta à API**: a interface não oferece os botões ao recebedor do acerto (R14), então pela tela o caso não existe.

## Por que importa
Aviso sem sentido e, no WhatsApp, uma mensagem a mais; não move dinheiro nem expõe dado de ninguém.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que fazer | A) pular o aviso do sino e o do WhatsApp quando quem enviou é o credor<br>B) deixar como está | **B** por ora, A se algum cliente (como o app Flutter) passar a chamar a rota nessa situação | Pela web o caso não ocorre; A exige teste e mutation check na rota financeira por um efeito que só a API direta alcança. |

Tipo sugerido: backend (só se a decisão for A).

## Resolução
Concluído em: 2026-11-12
Feature: docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/
Tasks: TASK-602
PRs: https://github.com/isacaguiar/expense/pull/274

Entregue como a D12-A do specify aprovou: `ExpenseController::confirmSettlement` não grava o sino `settlement_confirmed` nem dispara o WhatsApp quando `to_user_id` é quem chama (o credor que é responsável do devedor e envia o comprovante). O mesmo princípio vale para aceitar e recusar: ninguém é avisado da ação que ele mesmo fez.
