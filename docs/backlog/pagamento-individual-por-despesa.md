# Pagamento individual por despesa (pagamento por participante)

ID: 075
Origem: conversa de 2026-10-09 (Norma e Isac pagaram à vista, fora do app, as parcelas da construção); `backend/app/Http/Controllers/ExpenseController.php` (`computeCycleSummary`, `collectCycleEntries`, `confirmSettlement`)
Criado em: 2026-10-09
Prioridade: ALTA
Status: Aberto

## Descrição
Hoje o app só sabe de dois estados: a parcela inteira está "paga" (o credor confirma, `pay()`), ou o devedor confirma o **acerto do ciclo** inteiro, o valor líquido par a par (`confirmSettlement`). Não existe "o devedor X já pagou a parte dele nesta despesa". Por isso o app cobra de novo o que foi pago por fora, e um `UPDATE` no banco não resolve quando há outras pessoas no rateio: `ex_quotas.paid` e `born_paid` valem para a parcela inteira (marcar perdoaria os demais), e a parte de cada um vem de `valor da parcela ÷ participantes` de `ex_expenses_payers`, que é por despesa (tirar alguém do rateio faria os outros pagarem mais e mudaria o histórico). O acerto também ignora `paid`: só `born_paid=1` tira a dívida da conta (`:1229-1235`).

Proposta: criar o **pagamento por participante e por parcela** e usá-lo como a peça que faltava.

- Na tela de Pagamentos, o devedor ganha **"Pagar esta despesa"** em qualquer despesa (À Vista, Fixa ou Parcelada). O app gera o Pix com a **parte dele** (valor da parcela ÷ participantes, mesmo arredondamento do resumo) para o credor da despesa.
- Depois da confirmação, grava-se o pagamento da parte, por parcela e participante: valor, data, comprovante e quem confirmou.
- `computeCycleSummary` e `collectCycleEntries` passam a **ignorar a parte paga** nos saldos e no acerto, **sem mudar o divisor dos demais**. A conta par a par continua correta, porque a parte sai dos dois lados.
- O **pagamento total** do ciclo (o acerto de hoje) cobra só o que não foi pago individualmente.
- Tabela nova de pagamento por participante, contrato aditivo (Constitution §4.1). Onde mexe: `ExpenseController` (resumo, acerto e selagem do ciclo) e a tela de Pagamentos do web.

Ordem: depois da 070 (que reescreve `store()` e `update()`). É pré-requisito da 076 (quitação antecipada da parcelada) e do app Flutter (074), que deve nascer com o contrato de Pagamentos certo.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Vale para À Vista, Fixa e Parcelada. Na Parcelada, paga-se a parcela da competência escolhida; na Fixa, a ocorrência é materializada como `pay()` faz hoje. |
| R2 | O divisor dos demais não muda: quem não pagou continua devendo `valor ÷ participantes`. |
| R3 | Valor nominal, sem desconto nem juros. |
| R4 | Ciclo selado não muda (a foto é congelada). |
| R5 | Par que zera por pagamentos individuais conta como quitado em `cycleIsFullySettled`. |
| R6 | Despesa com pagamento por participante tem a edição de valor, parcelas e participantes bloqueada, como já acontece com parcela paga. |
| R7 | Contrato aditivo (Constitution §4.1). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem confirma o pagamento | A) o credor confirma, e só então vale<br>B) vale na hora com o comprovante do devedor | **A** | É a trava contra o devedor se perdoar com comprovante falso. Custa um passo ao credor; enquanto não confirmado, a parte conta como não paga. |
| D2 | Acerto do par já confirmado naquela competência | A) bloquear o pagamento individual dessa competência<br>B) recalcular e reabrir o acerto | **A** | B desfaz um comprovante já aceito e confunde os dois lados. |
| D3 | Desfazer um pagamento registrado | A) só o credor desfaz, apagando o comprovante (espelha `unpay()`)<br>B) sem desfazer na v1 | **A** | Dá saída para erro sem criar um caminho novo de autorização. |
| D4 | Pagamento feito por fora do app (caso de Norma e Isac) | A) mesmo fluxo, com "já paguei por fora" e comprovante, confirmado pelo credor<br>B) registro direto pelo credor, sem Pix | **A** | Reaproveita D1 e deixa rastro do comprovante. |
| D5 | Comprovante | A) obrigatório quando o devedor paga<br>B) opcional | **A** | Igual a `confirmSettlement`. |
| D6 | Competências aceitas | A) vigente e passadas (como `pay()`)<br>B) também futuras | **A** | Parcelas futuras ficam para a quitação (076). |

## Por que importa
O app cobra de novo o que já foi pago por fora, todo mês, e não há correção limpa no banco quando o rateio tem mais de duas pessoas. O pagamento por participante é a peça que o modelo não tem, e a quitação antecipada (076) depende dela.

Tipo sugerido: backend + frontend
