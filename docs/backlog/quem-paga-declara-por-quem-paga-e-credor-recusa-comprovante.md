# Quem paga declara por quem paga, sem aceite do devedor, e o credor pode recusar o comprovante

ID: 144
Origem: validação local da feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/` (pedido dele em 2026-11-10); specify daquela feature (D1, R2, RK2, RK4 e §2.2); `backend/app/Http/Controllers/ExpenseController.php` (`confirmSettlement`, `sealCycleIfSettled`) e `backend/app/Services/CycleSummaryService.php` (`summaryIsFullySettled`)
Criado em: 2026-11-10
Prioridade: MÉDIA
Status: Aberto

## Descrição
Hoje só o **devedor** escolhe quem paga por ele (D1-A e R2 da feature `responsavel-do-devedor`): o vínculo é uma permissão que ele dá, e é isso que impede um membro de mandar comprovante em nome de outro. Ele pediu o caminho inverso: **quem paga poder declarar "eu pago por Ana e Davi"**, sem a confirmação do dependente. O caso que motiva: uma criança que não acessa o app nunca aceitaria um pedido, então o vínculo ficaria pendente para sempre (por isso a D1-B da specify original, "o responsável propõe e o devedor aceita", não serve).

Declarar sem consentimento reabre o risco que a D1-A fechava: um membro se declarar pagador de outro e enviar um comprovante falso. A confirmação é auto-declarada e conta para selar o ciclo (`summaryIsFullySettled` só olha se existe `SettlementConfirmation` por par), e o credor **não tem como recusar nem excluir** um comprovante: `POST /groups/{id}/settlements/confirm` é a única rota sobre `SettlementConfirmation` e só cria ou reenvia.

A rede de segurança que ele propôs: **o credor poder recusar o pagamento** de um comprovante indevido, devolvendo-o a "pendente", para o pagador corrigir ou excluir, até o credor aceitar.

## Regra dele
**A recusa só funciona em ciclo que ainda não foi encerrado.** O ciclo encerrado (selado, `settled_at` preenchido) é histórico imutável e esta feature **não** o reabre (não usa `unsealIfBroken`).

**Leitura a confirmar na specify:** na tela, "Ciclo fechado" já é o estágio em que se envia comprovante, e comprovante só existe a partir dele; por isso "ainda não foram fechados" foi lido como "ainda não encerrados" (não selados).

**Consequência que a specify precisa tratar:** `sealCycleIfSettled` sela o ciclo assim que o último comprovante chega, então o credor não teria janela para recusar justamente o último. Para a recusa valer, o comprovante enviado por responsável (`confirmed_by` não nulo) só pode contar para selar depois de o credor aceitar; o que o devedor envia por conta própria segue selando como hoje.

## Por que importa
Sem a recusa, a autonomia do responsável abre uma porta para selar o ciclo de outra pessoa sem pagamento (RK2 da feature anterior, que o consentimento fechava). Com ela, o credor, que é quem confere o dinheiro, tem a última palavra enquanto o ciclo está aberto para acerto.

## Decisão a tomar na triagem
A D1 e a D5 são dele; as demais são recomendações a aprovar na specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem vincula | A) o responsável se adiciona sem aceite<br>B) o responsável propõe e o devedor aceita | **A** (decidida por ele) | B trava o caso da criança que não abre o app |
| D2 | Avisar o devedor | A) sim, aviso in-app novo<br>B) não | **A** | É a única chance de um adulto notar; o devedor continua podendo remover o responsável pelo diálogo que já existe |
| D3 | Registrar quem criou o vínculo | A) coluna `created_by` em `ex_group_member_responsibles`<br>B) nada | **A** | Audita e permite endurecer depois sem perder dado |
| D4 | Como o credor recusa | A) apaga a confirmação e o arquivo e avisa<br>B) mantém a linha com `returned_at` | **A** | Todo o código lê "existe confirmação"; B mudaria `summaryIsFullySettled`, `withConfirmations` e o front. Custo de A: não guarda histórico, só o aviso |
| D5 | Até quando a recusa vale | A) só em ciclo ainda não encerrado; o encerrado é imutável e nunca reabre<br>B) também em ciclo encerrado, reabrindo-o por `unsealIfBroken` | **A** (regra dele) | Histórico selado não muda depois |
| D6 | Quem precisa do aceite do credor | A) só comprovante enviado por responsável (`confirmed_by` não nulo): só conta para selar depois do aceite<br>B) todo comprovante<br>C) ninguém (a recusa não teria janela no último comprovante) | **A** | Faz a D5-A funcionar sem mudar o fluxo de quem paga por conta própria. Limite: a recusa vale só para comprovante de responsável |
| D7 | Quem exclui o comprovante antes do aceite | A) quem enviou (`confirmed_by`) e o devedor<br>B) só quem enviou | **A** | É o "retificar e excluir" que ele pediu |
| D8 | O responsável larga o papel | A) pode remover o próprio vínculo<br>B) fora de escopo (como na RK4 atual) | **A** | Custa pouco e fecha a RK4 |
| D9 | Aviso ao recusar | A) sino para quem enviou e para o devedor<br>B) só para quem enviou | **A** | Os dois são afetados |

## Peças existentes a reaproveitar
Backend: `MemberResponsibleService` (acrescentar `attach`/`detach`; `replace` continua sendo do devedor), `GroupMemberResponsibleController`, `Notifier::guard` e `fanOut`, `ExpenseController::confirmSettlement` e `sealCycleIfSettled`, `SettlementConfirmation` (ganha um marcador de aceite), `ProofStorage::delete`, `CycleSummaryService::summaryIsFullySettled` e `withConfirmations` (o selo passa a contar só comprovante de responsável aceito), e a janela de 24 h sem reaviso do convite (`notified_at`) contra aviso repetido. Front: `PaymentResponsibleDialog`, `PayableSettlementList` (visão do credor: Aceitar e Recusar) e `notificationText.ts`. Exige nova revisão do `security-reviewer` e atualização da specify da feature anterior (R2 e D1).

Tipo sugerido: feature (backend e frontend), por `/promover-backlog 144`, com aprovação entre Specify, Plan e Tasks.
