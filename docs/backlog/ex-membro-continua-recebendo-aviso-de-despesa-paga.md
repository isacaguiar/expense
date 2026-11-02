# Quem saiu do grupo continua recebendo o aviso de despesa paga (in-app e, em tese, WhatsApp) das despesas em que ainda participa

ID: 127
Origem: TASK-556 (revisão de segurança) da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/`; `backend/app/Support/Notifier.php` (`expensePaid`, `:133-155`) e `backend/app/Support/WhatsApp/WhatsAppNotifier.php` (`:49`)
Criado em: 2026-10-31
Prioridade: BAIXA
Status: Aberto

## Descrição
- `Notifier::expensePaid` monta os destinatários a partir de `$expense->payers` (`:138-140`), e quem sai do grupo continua em `ex_expenses_payers`. Depois de sair, o devedor recebe `expense_paid` com `groupName`, descrição, valor e nome de quem pagou. O revisor **confirmou o aviso in-app** por requisição.
- `WhatsAppNotifier` usa os mesmos `payers` (`:49`). **Só lido no código, não executado** (a feature flag estava desligada).
- Não vaza acesso: o link da notificação leva a uma rota que dá 404 ao ex-membro. É texto sobre despesa que ele ainda deve.

## Por que importa
É defensável (ele continua devedor, a D3-B deixa sair quem só deve) e ele já conhecia esses dados quando era membro. Mesmo assim, o usuário que saiu para parar de receber coisas do grupo continua recebendo.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Avisar quem saiu | A) manter, ele ainda é devedor<br>B) não avisar quem não é membro<br>C) avisar sem `groupName` e descrição | **A**, com B se o produto preferir | Quem sai sem pagar continua devendo; B respeita o desejo de sair, mas o devedor deixa de saber que o credor marcou a parcela. |

Tipo sugerido: decisão de produto, depois bugfix pequeno no `Notifier` e no `WhatsAppNotifier`.
