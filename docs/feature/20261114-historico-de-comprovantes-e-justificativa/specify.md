# Specify — Histórico de comprovantes e justificativa

> Feature: guardar o **histórico dos comprovantes do acerto** (substituídos, recusados e retirados, em vez de apagar o arquivo), deixar o credor informar o **motivo da recusa** e permitir um **complemento com justificativa** sobre o comprovante do devedor, sem mudar estado nem selo. Origem: backlog **150**, pedido dele em 2026-11-11 durante a TASK-605 da feature `docs/feature/concluidas/202611/20261110-quem-paga-se-declara-e-credor-recusa/`; agrupa o item **147** (decidido por ele em 2026-11-14, ao promover).

Versão: 1.3 · Criado em: 20261114

**v1.3 (2026-11-14): rascunho, aguarda a aprovação dele.** O `security-reviewer` da TASK-639 achou um ALTO (A5): o orçamento de quem envia (`replaced` e `withdrawn`) é **um só** para o devedor e para todo pagador declarado, então um terceiro auto-declarado repete "enviar, retirar" 10 vezes e o devedor leva 422 ao enviar o **próprio** comprovante (o A1 pela via de `replaced`/`withdrawn`). **Decidido por ele: orçamento próprio para quem paga por outro.** Muda a **R8**, **F3**, **RK3** e **RK9**, acrescenta a **D14** e a **RK10**, e a D13 deixa de valer na parte "10 complementos à parte" (a D14 a substitui). Nada foi implementado desta revisão.

**v1.2 (2026-11-14): aprovado por ele** ("aprovado, siga com a TASK-638"), depois do achado **A1** da revisão de segurança da TASK-628 (o teto de 10 era somado: quem se declara pagador do devedor enchia o histórico com 10 complementos e o devedor recebia 422 ao corrigir o **próprio** comprovante) e da escolha dele pela **opção A**. O teto de R8 deixa de ser "10 no total" e passa a ser **dois orçamentos de 10** (R8, D5, nova D13, F3, RK3, nova RK9, CA-5). Nada mais muda. Nada foi implementado desta revisão; o código das TASK-616 a 628 fica como está até as TASK-638 e 639.

**v1.1 (2026-11-14): aprovado por ele** ("aprovado, siga com as recomendadas"): **D2 a D11 valem como recomendadas**, incluindo as quatro de custo real (D6, D7, D8 e D11). A pergunta aberta sobre o texto da política de privacidade (§6, último item) **não foi respondida**: segue **fora de escopo**, como no rascunho. Nada foi implementado.

**v1.0 (2026-11-14): rascunho para aprovação.** As decisões **D1** (feature própria, 2026-11-11) e **D12** (agrupar o 147, 2026-11-14) são dele; D2 a D11 eram recomendações a aprovar.

---

## 1. Problema

O pedido dele, em 2026-11-11: quem paga por outra pessoa não consegue trocar a foto errada que o **devedor** mandou, e "deveria ter um campo de justificativa, no qual fosse possível adicionar novos comprovantes", para manter "o histórico para os cenários de recusa".

Hoje o comprovante do acerto é uma **linha única por par** (grupo, ciclo, devedor, credor), e o que sai dela some:

- o **reenvio** substitui o comprovante e **apaga o arquivo anterior** (§2.1);
- o comprovante **recusado** continua no banco, mas o resumo o **esconde** de todos, e o arquivo some no primeiro reenvio (§2.2): a prova do que o credor recusou, e por quê, não sobrevive, e o credor recusa **sem dizer o motivo** (§2.4);
- **excluir** é um `DELETE` físico da linha e do arquivo (§2.5), com um diálogo que promete "excluído de vez e não poderá ser recuperado";
- quem paga por outra pessoa recebe 422 "O devedor já enviou o comprovante deste acerto." se tentar corrigir a foto do devedor (§2.3): o limite que a revisão de segurança impôs na feature anterior, e que o pedido dele quer contornar **sem** dar poder de sobrescrita.

### 1.1 Verificação prévia

- O ponto de partida é a feature anterior, em produção na tag `v1.5.0` (`dev` e `main` em `6c984ead62`): aceitar, recusar, excluir, o estado derivado `status()` e o selo que só conta comprovante que vale.
- Esta feature **não** muda quem decide, o selo nem as regras da anterior (R5 a R9 e R12). Ela acrescenta memória (histórico) e duas informações (motivo e complemento) ao que já existe.
- O item **147** entra por mexer nos mesmos métodos: `refuse` passa a gravar o motivo e é um dos dois `UPDATE` que a hipótese do 147 atinge (§2.9).

---

## 2. Achados confirmados

Cada item foi conferido no código desta branch (`dev` em `6c984ead62`).

### 2.1 O reenvio apaga o arquivo anterior
`SettlementConfirmationService::submitProof` (`:180-200`) faz `updateOrCreate` pela chave do par e devolve o `previousPath`; `ExpenseController::confirmSettlement` apaga esse arquivo logo depois, com `ProofStorage::delete($previousProofPath)` (`ExpenseController.php:1852-1854`). Note que é `delete`, não `deleteIfUnreferenced`: o arquivo antigo some mesmo que outra coisa o referenciasse. O `note` (campo `descricao`, `max:500`, `:1761`) é sobrescrito junto: sem `descricao` a nota anterior é limpa.

### 2.2 O recusado fica no banco, mas ninguém o vê
`refuse` (`SettlementConfirmationService.php:96-113`) só grava `refused_at`; a linha e o arquivo ficam (D4-B da feature anterior). `CycleSummaryService::withConfirmations` (`:362-403`) devolve `confirmedProofUrl`, `confirmedNote` e `confirmedAt` **nulos** para o recusado (`:382-384`), para a tela tratá-lo como "a pagar". Quem enviou e o credor ficam sem ver o que foi recusado, e o arquivo some quando alguém reenvia (§2.1).

### 2.3 O limite do achado 1 da revisão de segurança
`SettlementConfirmationService::resendBlockedBy` (`:208-215`): o comprovante **aceito** é definitivo (`BLOCKED_ACCEPTED`) e o de estado `sent` (o do próprio devedor) só ele substitui (`BLOCKED_DEBTOR_PROOF`, 422 "O devedor já enviou o comprovante deste acerto.", `:221`). Sem esse limite, qualquer membro que se declarasse responsável trocaria um comprovante válido do devedor e apagaria o arquivo dele. O limite só atinge o devedor que **usa o app** e mandou a foto errada; no caso da criança que não abre o app, o comprovante já é de quem paga por ela, e esse reenvia à vontade.

### 2.4 Recusar é direto e sem motivo
`refuse` não recebe motivo, o controller valida só o par e o ciclo (`SettlementDecisionController.php:65-103`, `validatedPair` `:167-174`), e o front chama a ação num clique, sem diálogo (`PayableSettlementList.tsx:294-302`). O aviso `settlement_refused` (`Notifier.php:364`) não leva motivo, e o texto do sino é "{credor} recusou o comprovante de {devedor} de {valor}" (`notificationText.ts:34-35`).

### 2.5 Excluir é físico e o diálogo promete que é definitivo
`SettlementConfirmationService::destroy` (`:128-156`) apaga a linha (`$confirmation->delete()`, `:150`) e depois o arquivo (`ProofStorage::deleteIfUnreferenced`, `:155`). É o **único `DELETE` físico** da feature anterior, feito com o aval de hard delete dele (D7-A, 2026-11-10), e vale só para o comprovante de responsável, enquanto não aceito e com o ciclo não encerrado. O diálogo diz "será excluído de vez e não poderá ser recuperado" (`PayableSettlementList.tsx:343-362`, texto em `:347`).

### 2.6 O arquivo só é "referenciado" por duas tabelas, e o download só conhece dois tipos
`ProofStorage::deleteIfUnreferenced` (`ProofStorage.php:90-102`) olha `Quota.payment_proof_path` e `SettlementConfirmation.proof_path`; um arquivo que só o histórico referencia seria apagado como órfão se isso não mudar. O download assinado resolve o caminho por `type` `quota` ou `settlement`, pelo **id da linha atual** (`ProofDownloadController.php:43-52`); um arquivo do histórico não tem como ser servido hoje. A URL é assinada por 30 minutos (`SettlementConfirmation::getProofUrlAttribute`, `:130-141`, ADR-005).

### 2.7 O comprovante atual é visto por qualquer membro do grupo
`withConfirmations` não filtra por quem chama: todo membro que lê o resumo recebe `confirmedProofUrl` de **todos** os pares. O histórico traria justamente o que foi recusado ou retirado, a prova de uma disputa entre as partes; por isso a regra de leitura dele precisa ser decidida (D8) e não herdada.

### 2.8 Limites do arquivo e da nota
`confirmSettlement` valida `comprovante` como `required|image|max:5120` (5 MB, `:1759`) e `descricao` como `nullable|string|max:500` (`:1761`). O arquivo é gravado em `comprovantes/<groupId>/<uuid>.<ext>` no disco privado (`ProofStorage::store`, `:40-54`). Produção é host compartilhado (cPanel).

### 2.9 Item 147: `accept` e `refuse` são os primeiros `UPDATE` sem `confirmed_at`
`confirmed_at` é a primeira coluna `timestamp NOT NULL` da tabela (`2026_08_25_220000_create_ex_settlement_confirmations_table.php`). Em MySQL ou MariaDB com `explicit_defaults_for_timestamp` **desligado**, essa coluna ganha `ON UPDATE CURRENT_TIMESTAMP`. Até a TASK-598 toda gravação definia `confirmed_at` junto; `accept` atualiza só `accepted_at` (`SettlementConfirmationService.php:79`) e `refuse` só `refused_at` (`:109`). Verificado só no MySQL de teste (8.0.17, opção **ligada**, sem `ON UPDATE`); **não verificado em produção**. Se a produção tiver a opção desligada, a data de **envio** (dado de auditoria) viraria a data da decisão do credor. Não afeta autorização nem selo.

### 2.10 Retenção: a política de privacidade e a Constitution
A política de privacidade (`site/src/legal/privacidade.php:72-76`, item 5) diz que os dados ficam "enquanto sua conta estiver ativa", que registros de negócio excluídos são marcados como removidos, e que a exclusão definitiva é pedida pelo contato. **Não existe exclusão de conta no app** (conferido: nenhum código apaga `ex_users`; é o gancho do backlog 146). A Constitution (`00-constitution.md:105`, §1.5 e §5.2) proíbe `DELETE` físico de registro de negócio sem o gate humano de hard delete.

---

## 3. Requisitos

### 3.1 O que a feature entrega

1. **Histórico dos comprovantes.** Quando um comprovante **sai de cena** (é substituído por um reenvio ou retirado), ele vai para um histórico do par e do ciclo, com o arquivo, a nota, quem enviou, o estado em que estava, as datas e, se foi recusado, o motivo. O arquivo **não é apagado**.
2. **Motivo da recusa.** O credor informa um motivo opcional ao recusar; ele aparece, junto do comprovante recusado, para as partes.
3. **Retirar em vez de excluir.** "Excluir" passa a **retirar**: o comprovante sai do acerto, que volta a "a pagar", e vai ao histórico com o arquivo. O aceito continua definitivo.
4. **Complemento com justificativa.** Quem paga pelo devedor, e só sobre o comprovante do **próprio devedor**, adiciona uma justificativa obrigatória com uma foto. O complemento entra no histórico, avisa o credor e o devedor, e **nunca muda estado, `countsForSettlement` nem selo**.
5. **Leitura.** O credor, o devedor e quem enviou veem o histórico e o comprovante recusado, por URL assinada; ninguém mais.
6. **Item 147.** `accept` e `refuse` passam a definir `confirmed_at` explicitamente, e um teste afirma que ele não muda.

### 3.2 Regras adotadas

| # | Regra |
|---|---|
| R1 | O histórico é **só de acréscimo**: uma entrada nasce completa, no momento em que o comprovante sai de cena, e nunca é editada nem apagada pela API. |
| R2 | **Nenhum caminho desta feature apaga arquivo de comprovante de acerto.** Substituir e retirar guardam o arquivo. A única exceção é o arquivo **novo** que ainda não foi referenciado pelo banco (falha ao gravar), que continua sendo limpo. |
| R3 | O histórico **nunca** altera `SettlementConfirmation::status()`, o escopo `countsForSettlement`, `summaryIsFullySettled` nem o selo do ciclo. |
| R4 | O comprovante **aceito é definitivo** (R8 da feature anterior): não vai ao histórico por substituição nem por retirada. |
| R5 | **Ciclo encerrado é imutável** (R9 da feature anterior): substituir, retirar, recusar e aceitar continuam dando 422. O complemento é a única proposta de exceção (D7). |
| R6 | O comprovante **recusado** continua na linha atual (D4-B da feature anterior) até ser substituído ou retirado; só então vai ao histórico, junto do motivo. |
| R7 | O histórico e o comprovante recusado são lidos só pelas **partes** (D8): credor, devedor e quem enviou a entrada. O arquivo sai por URL assinada de 30 minutos (ADR-005); quem não é parte recebe o mesmo 404 de "não existe". |
| R8 | **Dois orçamentos de 10 por par e ciclo, divididos por quem age** (D5, D14): **10 entradas do devedor** (`replaced` e `withdrawn` em que quem agiu foi o próprio devedor: enviar, reenviar e retirar o comprovante de terceiro; a retirada conta, mas não é barrada) e **10 entradas de quem paga por ele** (toda entrada em que quem agiu **não** foi o devedor: `replaced`, `withdrawn` e `complement`). Estourar o orçamento de quem age dá 422, **antes** de gravar o arquivo. **Um orçamento nunca gasta o outro**: nenhum pagador declarado tranca o devedor. Os pagadores declarados dividem entre si o deles (um pode esgotá-lo para os outros, ver RK10). |
| R9 | O complemento só existe sobre o comprovante em estado `sent` (o do próprio devedor), por quem paga por ele, com justificativa e foto obrigatórias (D3, D9, D10). |
| R10 | Avisos só no sino, sempre **sem o autor** (R12 da feature anterior) e best effort (R13): o complemento avisa o credor e o devedor; a recusa segue avisando quem enviou e o devedor, **sem o motivo** no sino (D4). |
| R11 | Nenhum `UPDATE` de `ex_settlement_confirmations` desta feature pode alterar `confirmed_at` por efeito colateral (item 147). |
| R12 | O vínculo "quem paga por" continua dando **só o poder de enviar** (R4 da feature anterior): não dá leitura do histórico. |

### 3.3 Contrato da API (proposta; o Plan fecha os nomes)

| Quando | Contrato |
|---|---|
| Recusar | `POST /groups/{groupId}/settlements/refuse` ganha `reason` opcional (`string`, até 500). Sem ele, funciona como hoje. |
| Retirar | A rota `DELETE .../settlements/confirmation` passa a mover a linha para o histórico e **manter o arquivo**; a mensagem muda para "Comprovante retirado.". |
| Reenviar | `POST .../settlements/confirm` não muda de contrato; o comprovante substituído vai ao histórico (e o teto de R8 vale). |
| Complementar | **Rota nova**, ex. `POST /groups/{groupId}/settlements/complement`, multipart com o par, `cycles_ago`, `comprovante` (imagem até 5 MB) e `justificativa` (obrigatória, até 500). Com 10 entradas de quem paga pelo devedor no par e no ciclo dá 422 (R8, D14). |
| `summary`, `close`, `reopen` | `settlements[]` ganha, **de forma aditiva**, o histórico (`proofHistory`) e, para o recusado, o motivo, o arquivo e a nota (só para as partes). `confirmedProofUrl`, `confirmedNote` e `confirmedAt` seguem nulos para o recusado, como hoje. |
| Download | O `proofs.show` ganha um `type` novo para entrada do histórico, com a mesma URL assinada e a autorização de R7. |
| Tipos de aviso | Um novo, `settlement_complement_added`; `settlement_refused` não muda de texto no sino (D4). |

### 3.4 Decisões

**D1, D12 e D13 são decisões dele; D2 a D11 eram recomendações e foram aprovadas por ele em 2026-11-14.** Em **negrito** na coluna "Por quê" ficam os custos reais.

| # | Decisão | Opções | Recomendação | Por quê | Status |
|---|---|---|---|---|---|
| D1 | Onde entra | A) no PR da feature anterior<br>B) feature própria | **B** | A feature anterior já estava mergeada e em produção | Decidida por ele em 2026-11-11 |
| D2 | O que se guarda | A) histórico só dos comprovantes que saem de cena (substituídos e retirados); a linha do par segue sendo o comprovante atual<br>B) todos os comprovantes como lista, o atual é o último | **A** | Mantém `status()`, o escopo do selo e `withConfirmations` como estão (R3); B reescreve a leitura do estado | Aprovada por ele em 2026-11-14 |
| D3 | Complemento sobre o comprovante do devedor | A) ninguém (como hoje)<br>B) informativo: justificativa obrigatória, entra no histórico, credor e devedor veem e são avisados, **não muda estado nem selo**<br>C) substitui e volta a "aguardando aceite" | **B** | C reabre o achado 1 da revisão de segurança (§2.3): qualquer membro se declararia pagador e tiraria da contagem um comprovante válido | Aprovada por ele em 2026-11-14 |
| D4 | Motivo da recusa | A) o credor informa um motivo **opcional** (até 500), visível na tela para as partes; o sino segue sem o motivo<br>B) sem motivo | **A** | É a metade do histórico que falta: hoje quem pagou só vê "recusado" (§2.2 e §2.4). Fora do sino para o texto do aviso continuar curto | Aprovada por ele em 2026-11-14 |
| D5 | Teto | N entradas por par e ciclo (v1.1: substituídos, retirados e complementos **somados**; **v1.2: dois orçamentos, ver D13**) | **10** | Cada arquivo pode ter 5 MB (§2.8): 10 entradas são até 50 MB por par e ciclo, **num host compartilhado**. Impede encher o disco por quem se declara pagador. **Revisada na v1.2:** o teto somado deixava o complemento de um terceiro gastar a vaga do devedor (A1) | Aprovada por ele em 2026-11-14; o formato "somado" foi substituído pela D13 |
| D6 | O que o "Excluir" vira | A) **"Retirar"**: sai do acerto e vai ao histórico **com o arquivo**<br>B) "Retirar" guarda só o registro (quem, quando, valor, nota) e **apaga a imagem**<br>C) mantém a exclusão física de hoje | **A** | Combina com a Constitution §1.5 e preserva a prova. **Custo:** quem mandou foto errada que tenha dado pessoal **não consegue apagá-la sozinho**; vale o pedido por contato da política (§2.10). O aval de hard delete da D7-A deixa de ser necessário para o **arquivo**: a retirada só apaga a **linha atual**, depois de copiá-la ao histórico, o que cabe dentro do aval que ele já deu (só o próprio comprovante, enquanto não aceito e com o ciclo não encerrado). O diálogo (`PayableSettlementList.tsx:347`) passa a dizer que o arquivo fica no histórico. B é a saída se ele preferir não reter a imagem: perde-se a prova. C não cumpre o pedido | Aprovada por ele em 2026-11-14 |
| D7 | Janela do complemento | A) a mesma das demais ações: ciclo fechado e **não encerrado**<br>B) também em **ciclo encerrado**, porque só acrescenta ao histórico e não muda estado nem selo | **B** | O comprovante do devedor (`sent`) já vale para o selo: em grupo pequeno, ou quando é o último par, **o ciclo sela na hora**, então com A o complemento nunca caberia no caso mais comum. **Custo:** é uma **exceção à R9** da feature anterior ("ciclo encerrado é imutável"); o dado selado (snapshot, acertos, selo) continua intacto, só se acrescenta uma entrada num histórico à parte | Aprovada por ele em 2026-11-14 (exceção à R9) |
| D8 | Quem lê o histórico e o recusado | A) as partes: credor, devedor e quem enviou a entrada<br>B) todos os membros do grupo, como o comprovante atual (§2.7) | **A** | É a prova de uma disputa entre as partes, e o vínculo "quem paga por" não dá leitura (R12). **Custo:** o comprovante recusado, que hoje nem aparece, passa a ter uma regra de leitura diferente da do comprovante atual | Aprovada por ele em 2026-11-14 |
| D9 | O complemento leva foto? | A) sim: foto obrigatória (até 5 MB) e justificativa obrigatória (até 500)<br>B) só justificativa | **A** | É o que o pedido descreve ("adicionar novos comprovantes"); só texto vira um comentário, que é outra feature | Aprovada por ele em 2026-11-14 |
| D10 | Quem pode complementar | A) quem paga pelo devedor (vínculo atual), só sobre o comprovante `sent` do devedor<br>B) também o devedor | **A** | O devedor já substitui o próprio comprovante, e cada substituição vai ao histórico (D2). Quem paga por ele é o único que hoje fica sem saída (§2.3). O credor nunca complementa | Aprovada por ele em 2026-11-14 |
| D11 | Retenção dos arquivos do histórico | A) enquanto o grupo e a conta existirem, sem prazo e sem tarefa agendada<br>B) prazo fixo (ex.: 12 meses) com limpeza agendada | **A** | A política de privacidade (§2.10) já diz "enquanto sua conta estiver ativa"; B exige agendador e uma decisão jurídica de prazo. **Custo:** quando existir exclusão de conta no app (exigência da Google Play), o histórico precisa entrar nela; fica como risco (RK6) | Aprovada por ele em 2026-11-14 |
| D12 | Agrupar o item 147 | A) sim: `accept` e `refuse` definem `confirmed_at` explicitamente e um teste afirma que não muda<br>B) deixar o 147 aberto | **A** | `refuse` passa a gravar o motivo e é um dos dois `UPDATE` do 147 (§2.9) | Decidida por ele em 2026-11-14 |
| D13 | O teto compartilhado entre o devedor e quem paga por ele (achado **A1** da revisão de segurança da TASK-628) | A) dois orçamentos separados: 10 entradas `replaced` e `withdrawn` e 10 complementos<br>B) manter o teto somado e aceitar o risco<br>C) A mais *throttle* por usuário na rota e janela de aviso do complemento<br>D) complemento só com vínculo aceito pelo devedor | **A** | Com o teto somado, quem se declara pagador do devedor (o vínculo é auto-declarado, D1-A da feature anterior) enche o histórico com 10 complementos e o devedor recebe 422 ao corrigir o **próprio** comprovante naquele ciclo; não existe rota que apague entrada (R1). **Custo de A:** até **21 arquivos** por par e ciclo (até 11 de quem envia e 10 de complementos, cerca de 105 MB com 5 MB cada; a parte de terceiros segue em no máximo 50 MB, como antes). O aviso do complemento segue **sem janela anti-assédio** (até 10 avisos por par e ciclo, o teto próprio); **C não foi escolhida** e o ponto vai ao backlog 155. D reabre a D1-A da feature anterior e contradiz o caso da criança que não abre o app | Decidida por ele em 2026-11-14 (opção A); **a parte "10 complementos à parte" é substituída pela D14** |
| D14 | O orçamento de quem envia é um só para o devedor e para todo pagador declarado (achado **A5**, ALTO, `security-reviewer` da TASK-639) | A) **orçamento por quem age**: 10 do devedor e 10 de quem paga por ele, este último somando `replaced`, `withdrawn` e `complement`<br>A2) A, mas mantendo os 10 complementos num orçamento à parte (3 orçamentos)<br>B) contar por `sent_by` da entrada, sem coluna nova<br>C) aceitar o risco | **A** | Um terceiro com o vínculo auto-declarado repete "enviar, retirar" e tranca o devedor (A5). **A** é o que ele pediu ("orçamento próprio para quem paga por outro"): o devedor passa a ter vaga **garantida**, e o complemento (sempre de terceiro) entra no orçamento de terceiros, então o pior caso **continua em 21 arquivos** (10 do devedor, 10 de terceiros e o atual; a A2 subiria a 31). **B não serve:** `sent_by` é quem enviou o comprovante que **saiu**, não quem agiu (o devedor que assume o comprovante de um terceiro criaria uma entrada com `sent_by` do terceiro e gastaria o orçamento errado); por isso A pede a coluna `acted_by` na entrada (quem agiu), no próprio `create_ex_settlement_proof_entries_table`, que **ainda não foi a produção**. **Custo de A:** desfaz o orçamento próprio do complemento da TASK-638 (some `MAX_COMPLEMENTS` e a mensagem própria; passa a valer uma mensagem de "limite de quem paga por outra pessoa") e um pagador pode esgotar o orçamento dos **outros** pagadores do mesmo devedor (RK10); o devedor e o credor o contêm: o devedor remove o vínculo pelo diálogo que já existe | Aguarda a aprovação dele (a escolha de A é dele, 2026-11-14) |

### 3.5 Falhas e consistência

| # | Falha | Tratamento |
|---|---|---|
| F1 | Substituir: o banco falha depois de gravar o arquivo novo | A entrada do histórico e a troca da linha vão na **mesma transação**; se falha, o arquivo novo é apagado (como hoje) e o anterior segue intacto e referenciado |
| F2 | Retirar: falha no meio | Mesma transação (inserir no histórico e apagar a linha atual); nada é feito com o arquivo |
| F3 | Teto atingido | 422 antes de gravar qualquer arquivo: quando as entradas **de quem age** chegam a 10 (do devedor, se quem age é o devedor; de quem paga por ele, se é um pagador declarado, somando `replaced`, `withdrawn` e `complement`), em enviar, reenviar e complementar (R8, D14). Um orçamento cheio **não** barra a ação do outro |
| F4 | Complemento sobre comprovante que não é `sent` (recusado, aguardando, aceito ou inexistente) | 422; quem enviou já pode substituir, e o aceito é definitivo (R4) |
| F5 | Motivo longo demais | 422 (`max:500`); sem motivo é válido |
| F6 | Corrida entre substituir, aceitar, retirar e complementar | A linha atual é travada (`lockForUpdate`) e relida, como hoje (F6 da feature anterior); o complemento lê a linha travada para conferir o estado `sent` e contar o teto |
| F7 | Quem não é parte baixa um arquivo do histórico | 404 uniforme (R7, ADR-005), sem distinguir "não existe" de "não é seu" |
| F8 | Falha no aviso | Log; não desfaz nada (R10) |
| F9 | Arquivo do histórico ausente no disco | A entrada aparece sem link, como o "Comprovante enviado" sem URL de hoje (`PayableSettlementList.tsx:266-275`) |
| F10 | Comprovantes substituídos ou excluídos **antes** do deploy | Não voltam: os arquivos já foram apagados. O histórico começa no deploy. O recusado atual fica na linha e passa a ser visível |

### 3.6 Interface (web)

Em Pagamentos, nas duas abas, sobre o `PayableSettlementList` (que já tem o arranjo `route` da feature `20261112-ajustes-de-texto-participantes-e-pagamentos`); os textos finais ficam para o Plan, e a interface nunca diz "Responsável".

- **Credor, Recusar:** abre um diálogo com o campo "Motivo (opcional)", com contador de 500; Cancelar e Recusar.
- **Acerto recusado:** o selo "Recusado por {credor}" ganha o motivo, em texto secundário, e um link "Ver comprovante recusado", só para as partes.
- **Retirar:** o botão "Excluir" vira "Retirar comprovante", e o diálogo explica que o comprovante sai do acerto e **fica guardado no histórico**.
- **Histórico:** em cada acerto com entradas, uma seção recolhível "Histórico (n)", da mais nova para a mais antiga, com quem enviou, quando, o estado, a nota, o motivo (se recusado) e o link do arquivo. Complemento aparece como tal, com a justificativa.
- **Complemento:** em acerto cujo comprovante é do devedor (`sent`), quem paga por ele vê "Adicionar justificativa e comprovante", com a justificativa obrigatória e a foto obrigatória.
- **Sino:** texto do `settlement_complement_added`, abrindo o resumo do grupo.
- **Layout:** medido em 375, 768, 1024, 1280 e 1440 px, com nomes longos, porque o card do acerto já é o mais cheio da tela.

---

## 4. Riscos

| # | Risco | Impacto | Mitigação |
|---|---|---|---|
| RK1 | Mexer em `confirmSettlement`, no resumo e na retirada (rota financeira) | Regressão no selo ou na autorização | R3, matriz de autorização, mutation check, os testes de selagem e de aceitar e recusar **sem edição** (os poucos que afirmam o hard delete e a perda do arquivo são editados de propósito e declarados no `implementation.md`), revisão do `security-reviewer` |
| RK2 | O histórico expõe arquivos financeiros | Vazar um comprovante de uma disputa a quem não é parte | R7 e D8-A, URL assinada de 30 minutos, 404 uniforme (F7), nova revisão do `security-reviewer` |
| RK3 | Crescimento de disco | Estourar a cota do host compartilhado | D5 e D14 (dois orçamentos de 10 por par e ciclo, o do devedor e o de quem paga por ele: até 21 arquivos, os de terceiros em no máximo 50 MB) e D11-A; o arquivo é no máximo 5 MB |
| RK4 | O complemento em ciclo encerrado (D7-B) fere a R9 | Alguém ler "imutável" literalmente | A exceção é declarada na D7 e no `01-specify.md`; o dado selado não muda, só se acrescenta ao histórico |
| RK5 | O contrato de `summary` muda | O app Flutter, quando existir, depende dele | Só campos **aditivos**; nenhum consumidor móvel hoje |
| RK6 | Foto com dado pessoal que não dá para apagar (D6-A) e a futura exclusão de conta | LGPD e exigência da Google Play | O pedido por contato segue valendo; o histórico entra na exclusão de conta quando ela existir (fica registrado como dependência, sem backlog novo até lá); decisão dele em D6 e D11 |
| RK7 | O `ON UPDATE` de `confirmed_at` em produção (147) | A data de envio vira a da decisão | R11, atribuição explícita e um teste que afirma; a conferência do `explicit_defaults_for_timestamp` em produção **não** é feita por esta feature (host compartilhado) |
| RK8 | O histórico cresce sem leitura | Entradas que ninguém vê | O credor e o devedor são avisados do complemento; a seção "Histórico" mostra a contagem |
| RK9 | Quem se declara pagador do devedor o tranca (A1 e A5) | O devedor não consegue corrigir o próprio comprovante no ciclo | D14-A: o orçamento é por quem age, então **nada** que um pagador faça (complementar, enviar, reenviar, retirar) gasta a vaga do devedor; testes que enchem o orçamento de terceiros por cada ação e reenviam como devedor; o `security-reviewer` relê o delta |
| RK10 | Um pagador declarado esgota o orçamento dos outros pagadores do mesmo devedor | Outro pagador legítimo leva 422 naquele par e ciclo; nenhum saldo, selo ou estado muda | Aceito (D14): o devedor remove o vínculo pelo diálogo que já existe, e o credor ainda recusa e vê o histórico; fica registrado no backlog 155 |

---

## 5. Critérios de aceite

### 5.1 Backend
- CA-1: substituir um comprovante guarda o anterior no histórico (arquivo **intacto no disco**, nota, quem enviou, estado e datas) e o teste confere o disco.
- CA-2: recusar aceita um motivo opcional (até 500); o motivo, o arquivo e a nota do recusado só saem para as partes.
- CA-3: retirar move a linha para o histórico e mantém o arquivo; o acerto volta a "a pagar"; o aceito dá 422.
- CA-4: o complemento só vale sobre `sent`, por quem paga pelo devedor, com foto e justificativa; **não muda** `status()`, `countsForSettlement` nem o selo; avisa o credor e o devedor, nunca o autor.
- CA-5: enviar e reenviar dão 422 antes de gravar o arquivo com 10 entradas `replaced` e `withdrawn`; complementar dá 422 antes de gravar o arquivo com 10 complementos; **os dois orçamentos são independentes** (10 complementos não barram o reenvio do devedor, e 10 substituições não barram o complemento).
- CA-6: ciclo encerrado dá 422 em substituir, retirar, recusar e aceitar; o complemento segue a D7 aprovada.
- CA-7: o histórico e o recusado só são lidos pelas partes; o arquivo sai por URL assinada; quem não é parte recebe 404 uniforme.
- CA-8: `accept` e `refuse` mantêm `confirmed_at` (teste que afirma), e `deleteIfUnreferenced` passa a tratar o histórico como referência (um arquivo só do histórico não é apagado).
- CA-9: matriz de autorização das rotas novas e alteradas (credor, devedor, quem enviou, outro responsável do devedor, terceiro, criador do grupo, não membro) e mutation check de R1 a R5, R7 e R8.

### 5.2 Frontend
- CA-10: o diálogo de Recusar com motivo; o acerto recusado com motivo e link; "Retirar comprovante" com o texto novo; a seção "Histórico"; o complemento; layout medido nas cinco larguras; nenhuma ocorrência de "Responsável" na interface.
- CA-11: `npx tsc --noEmit` limpo, suíte inteira do Vitest verde e `vite build` verde.

### 5.3 Documentação
- CA-12: `docs/sdd/01-specify.md` descreve o histórico, o motivo da recusa, a retirada e o complemento (glossário, §3.3 e §4); a Constitution **não** é editada; o aval de hard delete da feature anterior fica registrado como sem uso e a exceção à R9 (D7) fica declarada; os itens de backlog **150** e **147** vão para `concluidos/`.

---

## 6. Fora de escopo desta feature

- Os itens **146** (apagar a conta de quem enviou zera `confirmed_by`), **148** (janela de 24 h do aviso) e **149** (achados informativos da revisão): têm outro alvo e seguem abertos.
- **Excluir conta** e a retenção com prazo (D11-B): dependem de uma decisão jurídica e de um agendador.
- **Reabrir ciclo encerrado** para recusar e **desfazer um aceite** (como na feature anterior).
- Aceite do credor para o comprovante que o **próprio devedor** envia, e o credor complementar ou retirar.
- Comentários livres, chat ou anexos fora de um comprovante.
- Editar ou apagar uma entrada do histórico (R1).
- O WhatsApp (o texto ao credor não muda) e o app Flutter.
- O painel pessoal e `GET /me/overview` (backlog 139); o relatório de fechamento e o painel lateral do resumo seguem como estão.
- Conferir o `explicit_defaults_for_timestamp` do banco de produção.
- Atualizar o texto da política de privacidade (`privacidade.php`, item 5) para citar o histórico: texto jurídico; ele não respondeu à pergunta ao aprovar, então fica fora (se ele quiser, vira backlog próprio, sem bloquear esta feature).
