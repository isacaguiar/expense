# Quitação antecipada de despesa parcelada

ID: 076
Origem: conversa de 2026-10-09 (Norma e Isac pagaram à vista, fora do app, as parcelas da construção); depende do item 075
Criado em: 2026-10-09
Prioridade: ALTA
Status: Aberto

## Descrição
Numa despesa **Parcelada**, o devedor pode **quitar de uma vez o valor total que tem em aberto**. Na tela de Pagamentos, a despesa ganha **"Quitar despesa"**: o app soma a parte do devedor em todas as parcelas ainda não pagas (da atual em diante, valor da parcela ÷ participantes, mesmo arredondamento do resumo) e gera um único Pix com o total para o credor.

Depois da confirmação, grava o pagamento de cada parcela com o mecanismo da 075. As parcelas futuras deixam de entrar nos acertos, e o que já foi pago individualmente **não entra** no total.

Ordem: depois da 075. Recomendada antes do release do app Flutter (074), mas não bloqueia o início dele.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Só o devedor (participante que não é o credor) pode quitar. |
| R2 | Valor nominal: a soma das partes, sem desconto. |
| R3 | Uma parcela já paga individualmente (075) não entra no total. |
| R4 | Confirmação, comprovante e desfazer seguem o que for decidido na 075 (D1, D3 e D5). |
| R5 | Ciclo selado não muda, e a edição da despesa fica bloqueada (R4 e R6 da 075). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Primeira parcela incluída | A) a da competência vigente, se ainda não paga<br>B) só a partir da próxima | **A** | Evita cobrar duas vezes a parcela do mês. |
| D2 | Quitação parcial | A) tudo ou nada<br>B) escolher quantas parcelas pagar | **A** | B é uma v2 e cabe em outro item. |
| D3 | Credor recusa a confirmação | A) as parcelas voltam a ser devidas e o devedor é avisado<br>B) a quitação fica pendente até alguém agir | **A** | Estado final claro, sem parcela em limbo. |

## Por que importa
É o caso real de Norma e Isac: eles pagaram à vista, por fora do app, todas as parcelas restantes da construção. Sem a quitação antecipada, teriam de registrar parcela por parcela, ou continuariam sendo cobrados de novo a cada mês.

Tipo sugerido: backend + frontend
