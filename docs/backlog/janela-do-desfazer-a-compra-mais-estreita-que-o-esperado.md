# A janela do "desfazer pagamento da compra" é mais estreita do que parece: decidir quem pode desfazer e o que fazer com fechamento manual

ID: 115
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/specify.md §3.4 (D4, D11) e implementation.md (TASK-532, achado N7); `backend/app/Services/ExpensePaymentService.php` (`windowOpen()`)
Criado em: 2026-10-29
Prioridade: MEDIA
Status: Aberto

## Descrição
A salvaguarda da feature para quem cadastrou uma compra parcelada paga por engano (D11) vale **menos do que o texto sugere**:

- **Janela curta.** `windowOpen()` fecha quando qualquer parcela paga cai em ciclo fechado por data (depois da carência), com fechamento manual ativo ou selado. Depois disso o `unpay()` por parcela só alcança a ocorrência do ciclo visível e **nunca as parcelas futuras** (não existe pagamento antecipado, R6): essas ficam pagas sem caminho de correção.
- **Fechamento manual.** O `store()` de Parcelada só confere o fechamento **por data**. Num grupo com fechamento manual ativo, uma parcela da compra recém-cadastrada pode cair num ciclo fechado à mão, e a janela já nasce fechada: o desfazer é recusado desde o primeiro minuto (por leitura de código, não reproduzido).
- **Quem pode.** Só o **credor** vê e usa a ação (R19). Quem cadastra em nome de outra pessoa (D4) e erra **não corrige o próprio cadastro**; precisa pedir ao credor.

Nada disso é defeito de código: é consequência das decisões aprovadas, que valem ser revistas com uso real.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Qualquer mudança mantém a regra de que o pagamento é do credor (R4, R5) e de que não há pagamento antecipado (R6). |
| R2 | Depois do ciclo encerrado o histórico não muda (D11); este item não propõe reabrir ciclo. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem pode desfazer a compra | A) só o credor (hoje)<br>B) o credor e quem cadastrou, enquanto a janela está aberta | **B** | Quem registrou em nome do credor é quem erra na prática; o credor continua podendo e é avisado, como já é no cadastro. |
| D2 | Grupo com fechamento manual ativo | A) manter (a janela pode nascer fechada)<br>B) avisar no cadastro, quando a compra cai em ciclo fechado à mão, que o desfazer não estará disponível<br>C) só o fechamento por data conta para a janela | **B** | Muda pouco e é honesto com o usuário; C reabre histórico que o fechamento manual quis congelar. |
| D3 | Parcelas futuras depois da janela | A) nada (hoje)<br>B) permitir ao credor limpar parcelas futuras pelo `unpay` | **A** | B reintroduz pagamento antecipado (R6) e mexe em dado de ciclo ainda não aberto. |

## Por que importa
É a única rede de segurança contra um cadastro pago errado numa compra no cartão, e ela some cedo. Decidir agora, com o uso real da feature, evita descobrir os limites quando o usuário já está com a compra errada travada.

Tipo sugerido: backend + frontend
