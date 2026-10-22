# Anexar ou editar comprovante e descrição depois de a despesa estar paga

ID: 084
Origem: docs/feature/concluidas/202610/20261021-comprovante-ao-marcar-paga/specify.md §2.1 (item 6 da auditoria) e §4; `frontend/src/hooks/usePaymentActions.ts` (`canPay`), `backend/app/Http/Controllers/ExpenseController.php` (`pay`, `unpay`)
Criado em: 2026-10-21
Prioridade: MEDIA
Status: Aberto

## Descrição
Depois que o credor marca uma despesa como paga, ele não consegue mais acrescentar ou trocar a foto do comprovante nem a descrição do pagamento. Na interface web, `canPay` exige `!exp.paid` (`usePaymentActions.ts:60-61`), então o ✓ e o botão "Confirmar pagamento" somem assim que a despesa fica paga. O único caminho é **desfazer o pagamento** e refazê-lo, o que apaga foto e descrição, perde o `paid_at` original e, se o ciclo estava selado, o dessela (`unsealIfBroken`).

A API até aceita um novo `pay()` numa quota já paga (`$wasPaid`, `ExpenseController.php:1083`), mas esse caminho tem dois defeitos que a interface nunca exercita:

- grava a foto nova e **não apaga a antiga**: `ProofStorage::store` roda sem `ProofStorage::delete($quota->payment_proof_path)` antes, e o arquivo antigo fica órfão no disco privado;
- um novo `pay()` sem `descricao` mantém a nota anterior, mesmo quando a foto foi trocada.

Proposta: um modo "editar comprovante" para despesa paga, que reaproveita o `PaymentProofDialog` já preenchido com a descrição atual, troca a foto (apagando a antiga) e/ou a descrição, sem mexer em `paid`, `paid_at` nem `paid_by`.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Só o credor (`user_payer_id`) edita, como em `pay()`/`unpay()`. |
| R2 | A edição não altera `paid`, `paid_at`, `paid_by` nem a selagem do ciclo. |
| R3 | A foto substituída é apagada do disco (`ProofStorage::delete`), sem deixar arquivo órfão. |
| R4 | Mesmos limites de hoje: foto `image|max:5120`, descrição até 500 caracteres. |
| R5 | Contrato aditivo (Constitution §4.1). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como a API recebe a edição | A) reaproveitar `POST /expenses/{id}/pay` numa quota já paga<br>B) rota nova (`PUT /expenses/{id}/payment`) | **B** | `pay()` tem efeito de transição (notifica, sela o ciclo) e hoje não limpa a foto antiga. Uma rota própria deixa a regra de edição explícita e não muda o contrato de `pay()`. |
| D2 | Poder remover a descrição ou a foto | A) sim, a edição aceita limpar qualquer um dos dois<br>B) não, só substituir | **A** | O credor pode ter anexado a foto errada. A regra "foto OU descrição" da interface continua valendo: não deixa salvar sem nenhum dos dois. |
| D3 | Avisar os pagadores por WhatsApp ao trocar a foto | A) sim, mesmo gatilho de `pay()` com foto<br>B) não, só no primeiro pagamento | **B** | O re-`pay()` do mesmo quota já não notifica (`$wasPaid`); trocar a foto não é um fato novo para quem deve. |
| D4 | Ciclo selado | A) permitir editar<br>B) bloquear (a foto do ciclo é imutável) | **B** | O ciclo selado é histórico congelado (feature `20260902-pagamento-ciclo-fechado` §2.3). Para corrigir, o credor desfaz o pagamento, o que já dessela. |

## Por que importa
Hoje o credor que marcou como paga e depois recebe o comprovante, ou digitou a descrição errada, só tem a saída destrutiva de desfazer o pagamento. Com a regra "foto OU descrição" da feature `20261021-comprovante-ao-marcar-paga`, um pagamento registrado só com texto passa a ser comum, e anexar a foto depois é o complemento natural. O arquivo órfão do re-`pay()` é um defeito de armazenamento que só aparece quando alguém chama a API diretamente.

Tipo sugerido: backend + frontend
