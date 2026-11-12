# Histórico de comprovantes do acerto e complemento com justificativa

ID: 150
Origem: pedido dele em 2026-11-11, durante a TASK-605 da feature `docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/`, em resposta ao limite que o achado 1 do `security-reviewer` impôs (só o devedor substitui o comprovante dele); `backend/app/Services/SettlementConfirmationService.php` (`submitProof`, `destroy`), `backend/app/Http/Controllers/ExpenseController.php` (`confirmSettlement`, `:1853`), `backend/app/Services/CycleSummaryService.php` (`withConfirmations`)
Criado em: 2026-11-11
Prioridade: MÉDIA
Status: Aberto

## Descrição
**O pedido dele:** quem paga por outra pessoa não consegue trocar a foto errada que o devedor mandou; deveria haver um campo de justificativa em que fosse possível **adicionar** novos comprovantes, mantendo assim o histórico para os cenários de recusa.

**Como é hoje (conferido no código em 2026-11-11):**
- O par (grupo, ciclo, devedor, credor) tem **uma linha** em `ex_settlement_confirmations` com o comprovante atual. O reenvio substitui o comprovante e **apaga o arquivo anterior** (`ProofStorage::delete($previousProofPath)`, `ExpenseController.php:1853`).
- A recusa (D4-B) mantém a linha e o arquivo, mas o resumo devolve `confirmedProofUrl`, `confirmedNote` e `confirmedAt` nulos para o recusado (`withConfirmations`): **ninguém consegue ver o que foi recusado**, e o arquivo some no primeiro reenvio. A prova do que o credor recusou, e por quê, não sobrevive.
- Depois da correção do achado 1 (TASK-605), quem envia por outra pessoa **não sobrescreve** o comprovante "enviado" do próprio devedor (422 "O devedor já enviou o comprovante deste acerto."). O limite só atinge o devedor que **usa o app** e manda a foto errada: no caso principal (a criança que não abre o app) o comprovante já é de quem paga por ela, e esse reenvia à vontade.
- O `note` (campo `descricao`, opcional) já existe no envio e vira a "justificativa" natural.

## Decisões
**D1 é dele (2026-11-11).** As demais são recomendações a aprovar na Specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde entra | A) no PR da feature atual, antes de mergear<br>B) feature própria, por este item e `/promover-backlog` | **B** (decidida por ele) | Passa de uma linha por par para várias e mexe em tudo que a feature atual construiu: resumo, `status()`, escopo do selo, aceitar, recusar, excluir, avisos e as telas das TASK-606 a 608 |
| D2 | O que se guarda | A) histórico só dos comprovantes substituídos, recusados e retirados; a linha do par segue sendo o comprovante atual<br>B) todos os comprovantes como lista, o atual é o último | **A** | Mantém o código atual intacto e entrega o histórico; B reescreve a leitura do estado |
| D3 | Complemento sobre o comprovante do devedor | A) ninguém (como hoje)<br>B) informativo, com justificativa obrigatória: entra no histórico, o credor e o devedor veem e são avisados, mas **não muda estado nem selo**<br>C) substitui e vira "aguardando aceite" | **B** | C reabre o achado 1 da revisão: qualquer membro se declara responsável e tira da contagem um comprovante válido |
| D4 | Motivo da recusa | A) o credor informa um motivo (opcional) ao recusar<br>B) sem motivo | **A** | É a metade do histórico que falta: hoje quem pagou só vê "recusado" |
| D5 | Teto | N comprovantes no histórico por par e ciclo | **10** | Impede encher o disco por quem se declara responsável; cada arquivo pode ter até 5 MB |

## O que a feature mexeria
- **Tabela nova** de histórico (append-only): caminho do arquivo, nota, quem enviou, estado no momento (`sent`, `awaiting_acceptance`, `accepted`, `refused`, `withdrawn`), quando enviou, quando saiu de cena e o motivo da recusa. Migration aditiva e reversível (ADR-008).
- **`submitProof`:** em vez de apagar o arquivo anterior, move o comprovante substituído para o histórico. `ProofStorage::deleteIfUnreferenced` passa a tratar o histórico como referência.
- **`destroy` (D7-A):** "excluir" vira "retirar" (vai ao histórico, o arquivo fica). Hoje é o único hard delete da feature e tem o aval dele; com o histórico a exclusão física deixa de ser necessária, o que combina com a Constitution §1.5. Confirmar com ele se o aval antigo fica sem uso.
- **`refuse`:** grava o motivo (D4-A). **Novo** endpoint para o complemento (D3-B), com justificativa obrigatória, que nunca altera `status()` nem o escopo `countsForSettlement`.
- **Leitura:** o resumo expõe o histórico só a quem é parte do par (credor, devedor e quem enviou); o arquivo segue o download assinado do ADR-005.
- **Avisos:** o complemento avisa o credor e o devedor (nunca o autor, R12 da feature atual).
- **Front:** `PayableSettlementList` e `PaymentSettlementsPanel` mostram o histórico, o motivo da recusa e o campo de justificativa do complemento.

## Perguntas abertas para a Specify
- Retenção: por quanto tempo os arquivos do histórico ficam (LGPD, direito de exclusão)? Hoje ninguém decide isso porque os arquivos são apagados.
- O credor pode ver o comprovante que ele próprio recusou? Hoje o resumo o esconde.
- Teto por par e ciclo, ou por grupo? Quem conta como "parte do par" para ler o histórico quando o responsável sai da lista?

## Por que importa
O histórico é a única forma de o credor e o pagador discutirem uma recusa com a prova na mão, e o complemento fecha, sem dar poder de sobrescrita, o caso do devedor que mandou a foto errada. Nenhum dos dois bloqueia a feature atual.

## Pré-requisitos
A feature `20261110-quem-paga-se-declara-e-credor-recusa` mergeada em `dev`. Nova revisão do `security-reviewer` (a leitura do histórico expõe arquivos financeiros) e gate humano da Constitution para a mudança de modelo de dados.
