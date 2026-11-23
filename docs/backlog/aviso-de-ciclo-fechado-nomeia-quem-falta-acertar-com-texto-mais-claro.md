# Aviso de ciclo fechado: deixar claro que falta o acerto e quem ainda não pagou

ID: 161
Origem: pedido dele em 2026-11-20 (conversa da feature `20261120-ver-comprovante-em-modal`); `frontend/src/components/CycleClosingAlert.tsx:37-77`
Criado em: 2026-11-20
Prioridade: BAIXA
Status: Aberto

## Descrição
Pedido dele, nas palavras: acrescentar uma **mensagem informativa do fechamento do ciclo** que informe que falta o acerto, mostrando **o credor que não pagou**, com a sugestão de texto "<nome> não possui conta não paga ou em aberto", a avaliar qual é a mais adequada.

O que existe hoje (conferido no código em 2026-11-20): `CycleClosingAlert`, na Home do grupo e na tela de Despesas, já tem duas mensagens:

- **Antes do fechamento** (`info`): "Este ciclo fecha em DD/MM. Registre e acerte as despesas até lá."
- **Depois do fechamento** (`warning`): "O ciclo fechou e ainda falta acertar: <nomes>." Aparece só com o ciclo `closed`/`closed_manually`, não selado, e com pelo menos um acerto sem confirmação; os nomes são os **devedores** de acertos sem comprovante confirmado (`pendingDebtorNames`).

Ou seja, a mensagem de "falta o acerto" com nomes **já existe**. O pedido precisa ser esclarecido antes de virar tarefa (decisões abaixo), porque pode ser (a) uma troca de texto, (b) um aviso a mais em outro lugar ou momento, ou (c) um leitura diferente de "credor".

## Decisões em aberto
A fechar com ele antes do specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem é nomeado | A) o **devedor** que ainda não pagou (o que o alerta faz hoje)<br>B) o **credor** a quem falta ser pago, junto com o devedor ("Maria deve a João")<br>C) só o credor | **B** | Quem lê "credor que não pagou" provavelmente quer dizer o devedor; "Fulano deve a Beltrano" cobre as duas leituras e diz a quem pagar. Confirmar com ele. |
| D2 | O que a mensagem diz | A) manter o texto atual<br>B) "<nome> ainda não pagou o acerto do ciclo" (um por devedor)<br>C) dizer também quem **não tem pendência** ("<nome> não possui conta em aberto") | **B** | Texto direto, uma linha por pessoa. C é a frase sugerida por ele, mas lista quem está em dia, o que pode virar ruído num grupo grande; avaliar. |
| D3 | Onde aparece | A) só no `CycleClosingAlert` atual (Home e Despesas)<br>B) também em Pagamentos e Relatórios | **A** | É o lugar do aviso de fechamento hoje; Pagamentos já mostra cada acerto. |
| D4 | Quando aparece | A) só depois do fechamento e antes de selar (hoje)<br>B) também no pré-fechamento, avisando os pendentes | **A** | O pré-fechamento já tem seu aviso, e o acerto só existe depois do fechamento. |

## Por que importa
O alerta atual lista só nomes soltos; dizer a quem cada pessoa deve e em linguagem direta ajuda a cobrar o acerto sem abrir a tela de Pagamentos.

Tipo sugerido: frontend (sem mudança de backend; o `summary` já traz `settlements` e `balances`)
