# Plan — Histórico de comprovantes e justificativa

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.2 · Criado em: 20261114

**v1.2 (2026-11-14): aprovado por ele** ("aprovado, siga com a TASK-638"), sobre o specify v1.2 (D13, opção A dele, depois do achado A1 da TASK-628). Muda o **ajuste 10** (novo), o ajuste 1, o §2, o §5, o foco de revisão (itens 2 e 12), o §10, o ajuste 9 (dois testes antigos editados) e o §15. As TASK-616 a 628 já entregues só mudam de código nas TASK-638 e 639. Nada foi implementado desta revisão.

**v1.1 (2026-11-14): aprovado por ele** ("aprovado, siga com as tasks"), sobre o specify v1.1. Só mudou este cabeçalho; nenhuma decisão técnica foi alterada.

**v1.0 (2026-11-14): rascunho para aprovação**, sobre o specify v1.1 (aprovado por ele no mesmo dia). Nada foi implementado. Cada afirmação sobre o código atual foi conferida nesta branch (`dev` em `6c984ead62`).

**Resumo.** *Objetivo:* guardar o histórico dos comprovantes do acerto (substituídos, retirados e complementos, com o arquivo), deixar o credor dizer o motivo da recusa, trocar "Excluir" por "Retirar" e permitir um complemento com justificativa sobre o comprovante do devedor, sem mudar estado nem selo. *Arquitetura:* uma **tabela nova só de acréscimo**, `ex_settlement_proof_entries`, e **uma coluna** nova, `ex_settlement_confirmations.refusal_reason`; a linha do par continua sendo o comprovante atual, então `status()`, `countsForSettlement` e o selo **não são tocados** (R3). A regra do histórico mora num **service novo**, `SettlementProofHistoryService`; `SettlementConfirmationService` o chama nas transições (reenviar, retirar) e ganha o complemento. *Stack:* Laravel 10 + MySQL; React 18 + TypeScript + MUI.

> **O que este plano ajusta ou acrescenta ao Specify** (nenhuma decisão aprovada muda de sentido; os itens 1 e 2 corrigem premissas que a leitura do código derrubou):
>
> 1. **O teto (R8, D5) vale para toda ação que grava arquivo novo: enviar, reenviar e complementar** (o specify dizia "substituir ou complementar"). Com o teto só em substituir, dava para furá-lo em laço: reenviar até 10, retirar (que não grava arquivo, então passa) e enviar de novo, sem limite. Retirar **continua sem teto** (não cria arquivo, só move). Com isso, no máximo 11 arquivos de quem envia por par e ciclo (10 no histórico e 1 na linha atual). **Desde a v1.2 o complemento não divide esse teto: tem o orçamento próprio do ajuste 10.**
> 2. **O item 147 não se resolve como o backlog sugere.** O backlog propõe `update(['accepted_at' => ..., 'confirmed_at' => $confirmation->confirmed_at])`, mas o Eloquent **só grava atributo sujo**: atribuir o mesmo valor não entra no `SET`, e a proteção contra o `ON UPDATE` seria nula. A correção é um `UPDATE` pelo query builder com `confirmed_at = confirmed_at` explícito (§8). O RED prova as duas coisas: o `SET` lista `confirmed_at`, e o valor não muda.
> 3. **O motivo da recusa mora na linha atual** (`ex_settlement_confirmations.refusal_reason`), porque o recusado fica nela até ser substituído ou retirado (R6); só vira coluna do histórico quando sai de cena.
> 4. **Visibilidade por entrada, não por par** (D8-A): uma entrada é lida por quem é parte do acerto (credor e devedor) **ou** por quem a enviou (`sent_by`). Um segundo responsável do mesmo devedor não lê o que outro enviou. O padrão de `withConfirmations` é **sem detalhe** (`viewerId` nulo): só os três endpoints que respondem ao usuário passam o `viewerId`, e o relatório de fechamento e o Painel não mudam.
> 5. **F9 ao pé da letra:** a entrada cujo arquivo sumiu do disco sai **sem link** (`proofUrl` nulo), com um helper novo `ProofStorage::exists`. Sem isso o link daria 404 ao clique.
> 6. **O download assinado segue sendo a única autorização do arquivo** (ADR-005, a aba do navegador não manda `Bearer`). O tipo novo `settlement_history` revalida só recurso × grupo (404 uniforme). **Quem não é parte nunca recebe a URL** (é onde R7 e F7 são garantidos); o 404 do specify vale para recurso inexistente ou de outro grupo.
> 7. **R1 vira código:** o model da entrada **lança exceção** em `updating` e `deleting`. Nenhum caminho futuro apaga ou edita uma entrada sem quebrar um teste.
> 8. **O complemento mora em `SettlementDecisionController`** (que já abriga `destroy`, que não é decisão do credor) para reaproveitar `findGroup` e `validatedPair`; o docblock da classe passa a dizer "ações sobre o comprovante do acerto".
> 9. **Testes antigos editados de propósito** (RK1 do specify), todos porque afirmam o comportamento que esta feature troca de propósito (arquivo apagado no reenvio e na exclusão, mensagem "excluído"): `SettlementConfirmationControllerTest`, `SettlementConfirmationOnBehalfTest`, `SettlementProofOwnershipTest`, `SettlementResendAfterDecisionTest`, `SettlementDecisionAuthorizationMatrixTest`, `SettlementDecisionDestroyTest` e, no front, os testes de `PayableSettlementList` que afirmam "Recusar" sem diálogo e o texto do "Excluir". Cada edição é declarada no `implementation.md`; qualquer **outro** teste que quebre é regressão e a task para. **Acrescentados na v1.2 (ajuste 10):** `SettlementComplementTest` (os testes que enchiam o teto somado: **três**, conferidos na execução da TASK-638, os dois do teto e o "recusa não avisa" da TASK-627; passam a encher o orçamento de complementos) e `SettlementHistoryAuthorizationMatrixTest` (a célula `full` do complemento).
> 10. **O teto são dois orçamentos independentes (D13, v1.2; achado A1 da revisão de segurança da TASK-628).** O teto somado deixava quem se declara pagador do devedor gastar com 10 complementos a vaga que o devedor precisa para corrigir o **próprio** comprovante. Agora: (a) `isFull` conta **só as entradas `replaced` e `withdrawn`** (o orçamento de quem envia: enviar e reenviar são barrados; retirar continua sem barreira, mas **conta**, o que mantém fechado o laço "reenviar até 10, retirar, enviar"); (b) o complemento ganha `MAX_COMPLEMENTS = 10` e `isComplementFull`, que contam **só as entradas `complement`**, com mensagem de 422 própria. Um orçamento cheio nunca barra a ação do outro. Custo: até 21 arquivos por par e ciclo (11 de quem envia e 10 de complementos); a parte de terceiros segue em no máximo 50 MB.

## Restrições globais

Valem para todas as tasks.

- Migrations **aditivas** (Constitution §1.4, §4.2), com `up` e `down`; **gate humano** antes de rodar em banco compartilhado ou produção (o deploy aplica no merge em `main`, ADR-008, e a janela "deploy antes de `migrate`" se repete: durante ela, `refuse` com a coluna ainda ausente daria 500). Nomes de índice e de chave dentro dos **64 caracteres**; nas colunas de data novas, **nenhum `timestamp NOT NULL` como primeira coluna** (a armadilha do item 147): todas `nullable`.
- Contrato da API só **aditivo**: campos novos em `settlements[]`, rota nova de complemento, `reason` opcional em `refuse`. A rota `DELETE .../settlements/confirmation` mantém método e URL; só a mensagem muda.
- Rota dentro de `jwt.auth`; **404** para quem não é membro e grupo excluído, **403** para membro sem permissão, **422** para regra de negócio; 404/403 **antes** de qualquer arquivo ou linha escritos.
- Controller fino, regra no service (§1.3). Backend: `./vendor/bin/pint` limpo e PHPUnit para toda regra nova. Frontend: TypeScript estrito, sem `any` não justificado.
- **Nenhum caminho de acerto apaga arquivo de comprovante (R2).** `ProofStorage::delete` fica só para o arquivo **novo** ainda não referenciado (falha ao gravar). A **única exclusão física** é a da **linha atual** na retirada, depois de copiada ao histórico, dentro do aval de hard delete que ele já deu (D7-A da feature anterior); citar no PR.
- `from_user_id` da confirmação **é sempre o devedor**; `confirmed_by` é quem enviou (nulo = o próprio devedor); **nenhum rótulo da interface usa "Responsável"**.
- Backend: suíte inteira só com o banco de teste **zerado** (`migrate:fresh`) e `./vendor/bin/phpunit`; frontend: `--maxWorkers=1` nesta máquina, conferindo que rodaram todos os arquivos, e **sem outra sessão rodando vitest** ao mesmo tempo.

## Foco de revisão

Casos que o specify implica mas que ninguém lembraria de testar, do mais provável ao menos. Cada um tem o teste na seção dona.

1. **Reenviar um recusado com motivo:** a entrada nasce com `status_at_exit = refused`, o motivo e `refused_at`; a linha atual volta com `refusal_reason` nulo e `refused_at` nulo. (§2, §3)
2. **O teto não se fura em laço:** com 10 entradas `replaced` e `withdrawn`, enviar e reenviar dão 422 **antes de gravar o arquivo**, e retirar ainda funciona; depois de retirar, enviar de novo continua dando 422. Com 10 complementos, complementar dá 422 **antes de gravar o arquivo**. (§2, §4, §5)
3. **Complemento em ciclo selado:** a linha atual, o snapshot e o selo ficam **idênticos**; só entra uma entrada e os avisos saem. Complemento nunca chama `update` na linha (nem tem como acionar o `ON UPDATE`). (§5)
4. **Quem pode complementar:** só quem tem o vínculo **agora** e **não** é o devedor nem o credor; o credor que também é responsável do devedor leva 403; quem foi desvinculado leva 403. (§5)
5. **Complemento sobre o que não é `sent`:** recusado, aguardando aceite, aceito e par sem comprovante dão 422 ou 404, **sem deixar arquivo órfão** no disco. (§5)
6. **Leitura:** um membro que não é parte vê `proofHistory: []` e nenhum `refusedProof`; um segundo responsável não lê a entrada que outro enviou; credor e devedor leem tudo. (§6)
7. **Arquivo só do histórico não é apagado como órfão** (`deleteIfUnreferenced`), mesmo quando um `updatePaymentProof` de despesa apaga um arquivo parecido. (§7)
8. **Corrida entre reenviar, retirar e aceitar:** a linha é travada e relida; quem perde recebe 422 limpo, e **nenhuma entrada** nasce para o que perdeu (aceito não vai ao histórico, R4). (§2, §4)
9. **`confirmed_at` intacto** depois de aceitar, recusar e complementar. (§8)
10. **Arquivo do histórico ausente no disco:** a entrada aparece sem link e o resto da resposta é normal. (§6)
11. **Ciclo encerrado:** reenviar, retirar, recusar e aceitar continuam dando 422 e **não escrevem entrada**; só o complemento passa (D7-B). (§2 a §5)
12. **Os dois orçamentos são independentes (A1):** quem paga pelo devedor faz 10 complementos, o credor recusa o comprovante do devedor e o devedor **reenvia (200)**; o 11º complemento segue dando 422. E o inverso: 10 substituições não barram o complemento. (§2, §5)

---

## 1. Dados: tabela de entradas, coluna do motivo e o model (Specify 2.1, 2.2, R1, R6, D2, D5)

- **Migration 1, `create_ex_settlement_proof_entries_table`** (nome `ex_`, como as demais): `id`; `group_id` (FK `ex_groups`, cascade); `cycle_start` (date); `from_user_id` e `to_user_id` (FK `ex_users`, cascade, como em `ex_settlement_confirmations`); `kind` (`string(20)`: `replaced`, `withdrawn`, `complement`); `sent_by` (FK `ex_users`, **`nullOnDelete`**, nulo): quem enviou **o comprovante daquela entrada** (para `replaced` e `withdrawn` é `confirmed_by ?? from_user_id`; no complemento, o autor); `status_at_exit` (`string(20)` nulo: `sent`, `awaiting_acceptance` ou `refused`; nulo no complemento); `amount` (`decimal(10,2)`); `proof_path` (`string`); `note` (`string(500)` nulo: a nota do comprovante e, no complemento, **a justificativa**); `refusal_reason` (`string(500)` nulo); `sent_at` e `refused_at` (`timestamp` nulos); `created_at` (`timestamp` nulo, **sem** `updated_at`). Índice **não único** `settlement_proof_entries_pair_index` em `(group_id, cycle_start, from_user_id, to_user_id)`: serve à contagem do teto e à leitura.
- **Por que tabela e não uma lista na linha atual (D2-A):** a linha do par continua sendo o único lugar que `status()`, `scopeCountsForSettlement`, `summaryIsFullySettled` e `withConfirmations` leem; o histórico fica fora do caminho do selo por construção (R3).
- **Migration 2, `add_refusal_reason_to_ex_settlement_confirmations_table`:** `refusal_reason` (`string(500)` nulo) depois de `refused_at`. `down` desfaz só o que criou.
- **Model `SettlementProofEntry`** (`App\Models\`): `UPDATED_AT = null`; `$fillable` das colunas; casts (`cycle_start` date, `amount` float, `sent_at`, `refused_at` e `created_at` datetime); relações `sender()` (`sent_by`), `fromUser()` e `toUser()`; `proofUrl(): ?string` com `URL::temporarySignedRoute('proofs.show', 30 min, [groupId, type 'settlement_history', id])`, **sem `$appends`** (a URL só sai por montagem explícita, para quem é parte). **R1:** `booted()` lança `LogicException` em `updating` e `deleting`.
- `SettlementConfirmation` ganha `refusal_reason` em `$fillable`. Nada mais no model muda: `status()` e `countsForSettlement` seguem como estão.

## 2. Reenviar guarda o arquivo anterior e respeita o teto (Specify 2.1, R1, R2, R4, R8, F1, F3, F6, D2, D5)

- **`SettlementProofHistoryService`** (novo, sem HTTP): `MAX_ENTRIES = 10`; `isFull(int $groupId, $cycleStart, int $fromId, int $toId): bool` (contagem pelo índice; **desde a v1.2 conta só `replaced` e `withdrawn`**, o orçamento de quem envia, ajuste 10); `MAX_COMPLEMENTS = 10` e `isComplementFull(int $groupId, $cycleStart, int $fromId, int $toId): bool` (a mesma contagem, **só de `complement`**; ver §5); `archive(SettlementConfirmation $row, string $kind): SettlementProofEntry` (copia `proof_path`, `note`, `amount`, `refusal_reason`, `refused_at`, `confirmed_at → sent_at`, `status_at_exit = $row->status()`, `sent_by = confirmed_by ?? from_user_id`). O **aceito** nunca vai ao histórico (R4): `archive` recusa o estado `accepted` com `LogicException`, o que torna R4 testável.
- **`SettlementConfirmationService::submitProof`** (já numa transação, com a linha travada): depois do bloqueio atual (`resendBlockedBy`), checa `isFull` → novo motivo `BLOCKED_HISTORY_FULL` (mensagem: "O limite de comprovantes guardados deste acerto foi atingido."); se há linha atual, `archive($current, 'replaced')` **antes** do `updateOrCreate`, na mesma transação (F1: se algo falha, entrada e troca desfazem juntas). Os valores padrão da gravação ganham `refusal_reason => null` ao lado de `accepted_at` e `refused_at`. O retorno **perde `previousPath`** (nada mais apaga o arquivo anterior).
- **`ExpenseController::confirmSettlement`:** depois de `resendBlockedBy`, o mesmo teto é checado **antes** de `ProofStorage::store` (F3); **sai** o `ProofStorage::delete($previousProofPath)` (`:1852-1854`) (R2); o tratamento de `confirmation === null` já devolve `blockedMessage(...)`, que ganha o caso novo. A falha do banco continua apagando só o **arquivo novo**.
- Por que checar duas vezes: a checagem do controller evita gravar 5 MB à toa; a do service, sob a trava da linha, é a que vale na corrida (o mesmo padrão de `resendBlockedBy`).

## 3. Recusar com motivo (Specify 2.4, D4, F5)

- `SettlementDecisionController::refuse` valida `reason` (`nullable|string|max:500`; o trim e o "" → null já vêm dos middlewares globais) e o repassa a `SettlementConfirmationService::refuse(..., ?string $reason)`, que grava `refused_at` **e** `refusal_reason` na mesma atualização. O sino **não muda**: `Notifier::settlementRefused` e o texto de `settlement_refused` ficam como estão (D4-A).
- Sem `reason`, o comportamento é o de hoje (compatível com quem nunca informa).

## 4. Retirar em vez de excluir (Specify 2.5, D6-A, R2, R4, F2)

- `SettlementConfirmationService::destroy` continua com as mesmas validações (quem enviou ou o devedor, só comprovante de responsável, nunca o aceito, ciclo não encerrado), mas, na mesma transação, faz `archive($confirmation, 'withdrawn')` e **depois** `delete()` da linha; **sai** o `ProofStorage::deleteIfUnreferenced($path)` do fim. O teto **não** se aplica (não grava arquivo novo).
- `SettlementDecisionController::destroy` responde "Comprovante retirado." (era "excluído"). Método e URL não mudam, então o front só troca texto.
- O aval de hard delete da feature anterior **continua sendo o que cobre** o `delete()` da linha atual; o arquivo passa a ficar. O specify (CA-12) registra isso na documentação.

## 5. Complemento com justificativa (Specify 3.1 item 4, D3, D7-B, D9, D10, R3, R5, R9, R12, F4, F6)

- **Rota nova** `POST /groups/{groupId}/settlements/complement`, dentro de `jwt.auth`, em `SettlementDecisionController::complement`. Corpo multipart: `from_user_id` (o devedor), `to_user_id`, `cycles_ago`, `comprovante` (`required|image|max:5120`) e `justificativa` (`required|string|max:500`).
- **Ordem das respostas:** 404 (grupo inexistente, excluído ou quem chama não é membro) → 422 de validação → **403** se quem chama não paga pelo devedor (`MemberResponsibleService::canPayFor(group, quem chama, devedor)` falso), ou é o devedor, ou é o credor (D10: o devedor já substitui o dele; o credor nunca complementa) → regras do service (422/404) → só então `ProofStorage::store` → gravação travada → avisos.
- **`SettlementConfirmationService`:** o teste "o ciclo está fechado" sai de `decidableCycle` para `closedCycle(Group, int): array` (fechado por data ou manualmente, **sem** olhar o selo); `decidableCycle` passa a chamá-lo e a checar o selo (comportamento idêntico ao de hoje para as quatro ações). O complemento usa `closedCycle` **sem** a checagem de selo (**D7-B**, a exceção à R9). Dois métodos novos: `complementTarget(...)` (a verificação **sem trava**, antes de gravar o arquivo: ciclo fechado, linha existente → 404, estado `sent` → 422, orçamento de complementos → 422) e `addComplement(...)` (transação: trava e relê a linha, repete as conferências e chama `SettlementProofHistoryService` para inserir `kind = complement`, `sent_by` = o autor, `note` = a justificativa, `amount` = o da linha, `sent_at` = agora, `status_at_exit` nulo). **Nenhum `UPDATE` na linha atual** (R3 e R11 por construção). Falha depois do arquivo gravado: o controller apaga o arquivo **novo** (F1).
- **Orçamento próprio (v1.2, D13, ajuste 10):** `SettlementConfirmationService::BLOCKED_COMPLEMENT_FULL = 'complement_full'` e, em `blockedMessage`, a mensagem "O limite de justificativas e comprovantes adicionados a este acerto foi atingido."; `complementBlockedBy(array $key): ?string` (estático, como `historyBlockedBy`, e com o mesmo formato de `$key`) devolve `BLOCKED_COMPLEMENT_FULL` quando `isComplementFull`. `assertComplementable` passa a usá-lo **no lugar de** `historyBlockedBy`, então `complementTarget` (sem trava) e `addComplement` (com a linha travada, F6) conferem o mesmo orçamento. Enviar e reenviar seguem com `historyBlockedBy` e a mensagem de hoje.
- **Aviso:** `Notifier::settlementComplementAdded(Group, array $recipientIds, ?string $authorName, ?string $debtorName, amount, $cycleStart)`, tipo `settlement_complement_added`, para o credor e o devedor **menos o autor** (R10/R12), best effort. Dados: `actorName`, `debtorName`, `groupId`, `groupName`, `amount`, `cycleLabel`; sem a justificativa no sino.
- **Resposta:** 201 `{ message: 'Justificativa e comprovante enviados.' }`; o front recarrega o resumo.

## 6. Leitura: o resumo, o download e as partes (Specify 2.7, D8, R7, R12, F7, F9)

- **`CycleSummaryService::withConfirmations`** ganha o parâmetro opcional `?int $viewerId = null`. Sem ele, o retorno é **idêntico ao de hoje** (o relatório de fechamento, `view` e `viewAt` não mudam). Com ele, cada `settlements[]` ganha, **de forma aditiva**:
  - `refusedProof`: `{ proofUrl, note, reason } | null`, só quando o estado é `refused` **e** o `viewerId` é parte (credor, devedor ou `confirmed_by`); `confirmedProofUrl`, `confirmedNote` e `confirmedAt` seguem **nulos** no recusado, como hoje.
  - `proofHistory`: lista, da mais nova para a mais antiga, de `{ id, kind, status, sentBy: {id, name} | null, amount, note, refusalReason, sentAt, refusedAt, archivedAt, proofUrl }`, **só com as entradas que o `viewerId` lê** (credor e devedor leem todas; os demais, só as que enviaram); `[]` para quem não é parte. Uma consulta só para o ciclo, agrupada por par, com `sender:id,name` carregado junto (sem N+1). `proofUrl` é nulo quando o arquivo não existe (`ProofStorage::exists`, F9).
- Os três pontos que respondem ao usuário passam o `viewerId`: `ExpenseController` em `summary`, `close` e `reopen` (`auth()->id()`).
- **`ProofDownloadController`:** o `type` novo `settlement_history` resolve `SettlementProofEntry::find($id)`, confere `group_id` e devolve o `proof_path`; recurso ausente, de outro grupo ou `type` inválido seguem dando o **mesmo 404**; a assinatura (`signed`) segue sendo a autorização, e **a URL só é emitida para quem é parte** (ajuste 6 do início deste plano).
- **`ProofStorage::exists(?string $path): bool`** (disco certo por `resolveDisk`), usado só para o link do histórico.

## 7. `deleteIfUnreferenced` conhece o histórico (Specify 2.6, CA-8)

- `ProofStorage::deleteIfUnreferenced` passa a consultar também `SettlementProofEntry::where('proof_path', $path)->exists()`. Hoje nenhum caminho de acerto o chama, mas ele é a rede de segurança de `updatePaymentProof` e de quem vier depois; sem isso um arquivo só do histórico seria tratado como órfão. Teste em `ProofStorageReferencesTest` (arquivo novo ao lado, sem editar o existente).

## 8. Item 147: `accept` e `refuse` não reescrevem `confirmed_at` (Specify 2.9, D12, R11)

- Em `SettlementConfirmationService`, `accept` e `refuse` deixam de usar `$confirmation->update([...])` e passam por um método privado que faz **`SettlementConfirmation::whereKey($id)->update($atributos + ['confirmed_at' => DB::raw('confirmed_at')])`** e depois `forceFill($atributos)->syncOriginal()` no model, para o chamador continuar lendo o valor novo. `confirmed_at = confirmed_at` no `SET` é a forma documentada do MySQL de impedir o `ON UPDATE CURRENT_TIMESTAMP`, e funciona com a opção ligada ou desligada.
- **Testes:** (a) captura o SQL (`tests/Concerns/CapturesTransactionQueries::queriesAfterTheTransactionOpens` já guarda toda consulta depois do `BEGIN`, `UPDATE` incluído) e afirma que o `UPDATE` de aceitar e o de recusar **listam `confirmed_at` no `SET`**; (b) afirma o valor de `confirmed_at` **inalterado** depois de aceitar, de recusar e de complementar (o reenviar grava um valor novo de propósito). O RED também mostra que a versão "ingênua" do backlog (`update` do Eloquent com o mesmo valor) **não** lista a coluna. É o único teste que independe da configuração do banco, que no teste local tem a opção **ligada** (sem `ON UPDATE`). A conferência em produção (`SHOW CREATE TABLE`) segue fora do escopo (gate dele).

## 9. Avisos e sino (Specify 3.2 R10, 3.6)

- Backend: o §5. Frontend: `notificationText.ts` ganha `settlement_complement_added`: "{actorName} adicionou uma justificativa e um comprovante ao acerto de {debtorName} de R$ {valor}". `settlement_refused` **não muda**. O clique segue o que os demais avisos do grupo já fazem (conferir no RED do teste do sino; se o mapeamento de rota for por tipo, o novo entra no mesmo caso que `settlement_confirmed`).

## 10. Matriz de autorização e revisão de segurança (Specify 5.1 CA-7, CA-9, RK1, RK2)

- Testes de matriz para a rota nova, a alterada (`refuse` com motivo, `destroy` retirando) e o download novo: credor, devedor, quem enviou, outro responsável do mesmo devedor, terceiro do grupo, criador do grupo, não membro, grupo excluído, ciclo aberto, ciclo fechado, ciclo selado, par sem comprovante. Nas três chamadas de leitura, a matriz de quem recebe `refusedProof` e `proofHistory`.
- **Mutation check** das regras R1 (tirar a exceção do model), R2 (voltar a apagar o arquivo), R3 (o complemento atualiza a linha), R4 (arquivar o aceito), R5 (tirar a checagem de selo das quatro ações), R7 (emitir a URL a todo membro) e R8 (tirar o teto de cada orçamento; `isFull` contando também os complementos; `isComplementFull` contando também `replaced` e `withdrawn`): cada mutante é uma condição trocada e **precisa ser morto** por um teste.
- Revisão do agent `security-reviewer` na rota de complemento, no tipo de download novo e na leitura do histórico (dado financeiro e arquivo); o resultado entra no `implementation.md`.

## 11. Frontend: tipos, hooks e textos (Specify 3.6, CA-10, CA-11)

- **`hooks/useGroupCycle.ts`:** `SummarySettlement` ganha `refusedProof?: { proofUrl: string | null; note: string | null; reason: string | null } | null` e `proofHistory?: SettlementProofEntry[]`; `SettlementProofEntry` (novo tipo exportado) reflete o §6. Opcionais, porque fixtures e respostas antigas não os trazem.
- **`hooks/useSettlementDecision.ts`:** `refuse(from, to, cyclesAgo, reason?)` envia `reason` só se não vazio. Hook novo **`useSettlementComplement(groupId, onSuccess)`** (multipart para `POST .../settlements/complement`, o mesmo cabeçalho e `cycles_ago` de `useConfirmSettlement`; mensagem do servidor em `error`; uma ação por vez).
- **Textos finais** (a interface nunca diz "Responsável"):
  - Recusar: título "Recusar comprovante?", corpo "O acerto volta para \"a pagar\" e quem enviou poderá reenviar. O motivo aparece para quem enviou e para {devedor}.", campo "Motivo (opcional)" com contador `n/500`, botões "Cancelar" e "Recusar".
  - Recusado: abaixo do selo "Recusado por {credor}", a linha "Motivo: {texto}" (omitida sem motivo) e o link "Ver comprovante recusado" (só quando `refusedProof.proofUrl` vem).
  - Retirar: o botão "Excluir" vira "Retirar comprovante"; título "Retirar comprovante?", corpo "O comprovante enviado por {nome} sai do acerto e fica guardado no histórico. Quem deve poderá enviar outro.", botão "Retirar"; aviso de sucesso "Comprovante retirado.".
  - Histórico: botão de texto "Histórico (n)", recolhido por padrão; cada entrada mostra "Substituído", "Retirado" ou "Justificativa", quem enviou, a data, "Estava: Enviado / Aguardando aceite / Recusado", a nota (ou a justificativa), "Motivo da recusa: …" e o link "Ver comprovante".
  - Complemento: botão "Adicionar justificativa e comprovante"; título "Adicionar justificativa", explicação "Use quando o comprovante enviado por {devedor} está errado ou incompleto. O comprovante atual continua valendo; o credor e {devedor} são avisados."; foto obrigatória ("Escolher foto do comprovante") e "Justificativa" obrigatória com contador `n/500`; sucesso "Justificativa e comprovante enviados.".

## 12. Frontend: Pagamentos (Specify 3.6, R7, CA-10)

- **Componentes novos** (um arquivo e um teste cada): `RefuseReasonDialog.tsx`, `SettlementProofHistory.tsx` (seção recolhível, mais nova primeiro, `overflowWrap: anywhere` nos textos) e `SettlementComplementDialog.tsx` (reaproveita `PaymentProofFields`, com a regra "foto **e** justificativa não vazia" para habilitar o envio).
- **`PayableSettlementList.tsx`:** "Recusar" passa a abrir o `RefuseReasonDialog` e `onRefuse(settlement, reason?)` recebe o motivo; "Excluir" vira "Retirar comprovante"; o recusado mostra motivo e link; o histórico aparece abaixo das ações quando `proofHistory?.length`; o botão de complemento aparece quando **quem vê paga pelo devedor** (`canPayFor`), o estado é `sent`, `confirmedBy` é nulo (o comprovante é do próprio devedor) e a tela recebeu `onComplement`; ele **não depende de `canConfirm`**, porque vale também em ciclo encerrado (D7-B). **O Painel** (`GroupPositionCard`) não passa os handlers novos e não recebe `proofHistory`, então **não muda**.
- **`PaymentSettlementsPanel.tsx`** repassa `onComplement`; **`Payments.tsx`** liga `onRefuse` com motivo, `onComplement`, a mensagem de sucesso por ação e o recarregamento do ciclo.
- **Layout:** medido em 375, 768, 1024, 1280 e 1440 px com nomes longos, no `PayableSettlementList` na variante `route`, que já é o cartão mais cheio da tela.

## 13. Testes e validação (Specify 5.1, 5.2, CA-11)

- Cada task backend: **RED executado**, GREEN, **mutation check** por regra e a suíte inteira com banco zerado. Cada task frontend: `tsc` limpo e `vitest related` dos arquivos tocados, e a **suíte inteira** (`--maxWorkers=1`, sem outra sessão rodando vitest) na revisão final, mais `vite build` num diretório temporário (o `dist/index.html` é versionado).
- Os testes novos usam `tests/Concerns/BuildsSettlementScenarios.php` e `BuildsThirdPartyProofScenarios.php` (acerto de agosto/2026 com `Carbon::setTestNow('2026-08-19')`), como as suítes de aceitar e recusar. Arquivo novo para cada regra nova; os testes antigos editados são só os do ajuste 9, declarados no `implementation.md`.
- **Validação local no navegador** (pedido dele, e antes de qualquer push): stack isolada, usuários credor, devedor, quem paga pelo devedor e um membro de fora, com o histórico, o motivo, a retirada e o complemento medidos nas cinco larguras.

## 14. Documentação (Specify CA-12)

- `docs/sdd/01-specify.md`: glossário (a tabela nova e `refusal_reason`), o bloco de §3.3 (histórico, motivo da recusa, retirar, complemento e a **exceção à R9** declarada) e a frase que hoje diz que a exclusão é física e apaga o arquivo. A Constitution **não** é editada. O texto da política de privacidade **fica fora** (decisão dele ao aprovar).
- Fechamento: os itens de backlog **150** e **147** vão para `docs/backlog/concluidos/` com a seção "Resolução", e a linha do `README` passa para a tabela "Concluídos".

## 15. Ordem de execução

Há dependência técnica; o critério é "dados e regra primeiro, leitura depois, tela por último", parando após cada task.

1. **Dados:** §1 (migrations e models) e o `SettlementProofHistoryService` base do §2; independente do resto.
2. **Regra de escrita**, nesta ordem: §8 (item 147, pequeno e isolado, e abre a mudança do `accept`/`refuse`), §2 (reenviar guarda o arquivo e o teto), §3 (motivo da recusa), §4 (retirar).
3. **Leitura:** §7 (`deleteIfUnreferenced`) e §6 (resumo e download). Dependem de §1 e §2.
4. **Complemento:** §5, que depende de §1 e usa a leitura do §6 para aparecer.
5. **Fechamento do backend:** §10 (matriz, mutation check e `security-reviewer`).
5b. **Correção do achado A1 (v1.2):** ajuste 10, em duas tasks: primeiro o orçamento próprio do complemento (§5), depois `isFull` contando só `replaced` e `withdrawn` (§2). **Antes do frontend**: a tela só mostra a mensagem de erro do servidor, então o front não depende desta correção, mas o backend fecha antes dele.
6. **Frontend:** §11 (tipos, hooks e sino), depois §12 em três fatias: recusar com motivo e retirar; recusado com motivo, link e histórico; complemento. Depende do contrato do backend estar pronto.
7. **Docs e fechamento:** §14, suítes cheias, `pr-readiness-checker`, validação local dele e um **PR único** contra `dev`.
