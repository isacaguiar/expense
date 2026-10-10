# Corrida entre o pagamento e a edição de uma despesa pode apagar a quota paga

ID: 078
Origem: docs/feature/20261008-rateio-parcelas-no-backend/ (achado A2 da revisão de segurança da TASK-403); `backend/app/Http/Controllers/ExpenseController.php` (`update()` e `pay()`)
Criado em: 2026-10-09
Prioridade: MEDIA
Status: Aberto

## Descrição
O `update()` decide se pode refazer as quotas lendo `$anyQuotaPaid` (nenhuma quota paga) e só depois apaga e recria as quotas (`quotas()->delete()` e `create`). Entre a leitura e o `delete` não há trava. Se o credor chamar `pay()` nesse intervalo, a quota paga é apagada e recriada com `paid=false`: o pagamento confirmado se perde, e o comprovante anexado deixa de apontar para uma quota.

A troca de tipo já tinha esse risco. A regeneração da TASK-401 o estende a qualquer edição de valor, data ou número de parcelas. Desde a TASK-405 a escrita roda numa transação, mas a leitura de `$anyQuotaPaid` continua fora dela, então a transação sozinha não fecha a janela.

Proposta: reler as quotas **dentro** da transação, com `lockForUpdate()`, imediatamente antes de apagar, e recusar com o mesmo 422 de hoje se alguma estiver paga. A janela é de milissegundos, mas o dado é financeiro e a falha é silenciosa.

Caminho provável: é defeito latente em comportamento existente, então vai por `/novo-bug` (BFF) com a Triagem; se marcar algum gatilho, vira feature.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A conferência de "alguma quota paga" que vale é a feita dentro da transação, com trava; a de antes da transação continua para responder rápido. |
| R2 | Achando quota paga, devolve o mesmo 422 e as mesmas mensagens de hoje, sem escrever nada. |
| R3 | Despesa FIXED continua fora, como hoje. |

## Decisões em aberto
A fechar na Triagem.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem trava | A) só o `update()` relê com `lockForUpdate()`<br>B) o `pay()` também trava a despesa antes de marcar a quota | **A** | Basta para não apagar quota paga: o `pay()` que chegar depois do `delete` encontra a quota nova e a marca. B é mais amplo e toca o caminho de pagamento. |
| D2 | Escopo | A) troca de tipo e regeneração juntas<br>B) só a regeneração | **A** | As duas apagam e recriam no mesmo bloco; separar deixaria o risco antigo aberto. |
| D3 | Como testar | A) simular o `pay()` no meio, com um evento de modelo disparado entre a releitura e o `delete`<br>B) teste de concorrência real, com duas conexões | **A** | B não é determinístico. |

## Por que importa
Um pagamento que o credor confirmou desaparece sem erro nem aviso, e o saldo do grupo volta a cobrar o que já foi pago. A chance é pequena, mas a consequência é dado financeiro perdido e só recuperável a mão.

Tipo sugerido: backend
