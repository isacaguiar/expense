# Exibir a descrição do pagamento (`payment_note`, `confirmed_note`) nas listas do Painel

ID: 094
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/specify.md §5 (decisão D14, 2026-10-22) e implementation.md §29 e §30; `backend/app/Services/PersonalOverviewService.php`, `backend/tests/Feature/OverviewControllerTest.php`
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature "comprovante ao marcar paga" (já na `dev`) permite acompanhar o pagamento de uma **descrição** de até 500 caracteres: `pay()` grava `ex_quotas.payment_note` e `confirmSettlement()` grava `ex_settlement_confirmations.note`. O `GET /groups/{id}/summary` as expõe (`paymentNote` por despesa, `confirmedNote` por acerto), e o `PaymentProofDialog` as envia.

O Painel **envia** a descrição (usa o mesmo diálogo, com o campo "Descrição") mas **não a mostra** em nenhuma lista. Foi decisão do usuário (D14, 2026-10-22): não agora, vai para o backlog. `PersonalOverviewService` monta cada item com chaves explícitas e não repassa as notas; dois testes travam isso de propósito em `OverviewControllerTest`: `test_payment_notes_never_reach_the_overview_from_a_live_cycle` e `test_payment_notes_never_reach_the_overview_from_a_sealed_snapshot` (usam o auxiliar `assertNotesStayOutOfTheOverview()` e os marcadores `MARCADOR-NOTA-DO-PAGAMENTO` e `MARCADOR-NOTA-DO-ACERTO`).

Proposta quando for feita:

- Backend: `my_items[].payment_note` (a descrição que o credor deu ao marcar a despesa como paga) e `to_pay[].confirmed_note` / `to_receive[].confirmed_note` (a descrição que o devedor deu ao enviar o comprovante). Aditivo (Constitution §4.1).
- Frontend: mostrar em `MyItemsList` e nas linhas de acerto de `GroupPositionCard` / `ReceivableList`, como texto (nunca HTML), com truncamento e "ver mais" para textos longos.
- Os dois testes de trava **mudam de propósito**: passam a afirmar que a nota **do próprio item** aparece e que a de despesa em que o usuário não participa **continua fora**.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A nota só aparece em item ou acerto que já aparece no Painel para aquele usuário (`my_items` só tem despesa em que ele participa ou que pagou; `to_pay`/`to_receive` só acerto dele). Nunca a nota de outra pessoa em despesa alheia. |
| R2 | A nota é dado digitado por outro membro: renderizada como texto, sem `dangerouslySetInnerHTML`, e fora de qualquer log ou evento de analytics. |
| R3 | O teste que trava a D14 é alterado **no mesmo passo** que a exibição, com mutação provando que a nota alheia continua fora. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde mostrar | A) nos itens e nos acertos<br>B) só no acerto confirmado<br>C) só nos itens | **A** | As duas notas respondem perguntas diferentes: a do item diz "como o credor recebeu", a do acerto diz "o que o devedor informou". |
| D2 | Texto longo (até 500 caracteres) | A) mostrar tudo<br>B) truncar com "ver mais" | **B** | Preserva a densidade da lista no celular. |
| D3 | Nota de despesa quitada que sai do Painel | A) nada a fazer (o ciclo todo quitado some do Painel e fica em Pagamentos)<br>B) manter visível | **A** | Mantém o Painel sobre o que está em aberto. |

## Por que importa
Quem recebe um comprovante com descrição ("paguei por fora, no dinheiro") hoje precisa abrir Pagamentos para ler. É conveniência, não risco; por isso BAIXA.

Relacionado: 084 (anexar ou editar comprovante e descrição depois de paga).

Tipo sugerido: backend + frontend
