# Tasks — Quem paga se declara e o credor recusa o comprovante

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261110

**Status: aprovada em 2026-11-10 ("aprovado, siga com a TASK-594") e em execução.** Specify v1.1 (aprovado por ele em 2026-11-10, D2, D3, D4-B, D6 e D8 a D12 como recomendado, D5 com a leitura confirmada e **D7-A com o aval de hard delete**), plan v1.0 (aprovado em 2026-11-10) e estas tasks. A execução é no modo nativo, parando após cada task.

IDs a partir de **TASK-594**: o maior em `docs/` e nas mensagens de commit de todas as refs é TASK-593 (feature `20261109-responsavel-do-devedor`), conferido em 2026-11-10 com busca em `docs/sdd/03-tasks.md`, em todos os `docs/feature/**/tasks.md` e em `git log --all`.

Escopo: `backend/`, `frontend/` e documentação. Origem: backlog **144**, agrupado com **141** e **143** (o 142 fica fora, specify §6). O item 144 vira "Promovido para TASK-594" depois da aprovação destas tasks, mas **o arquivo só vai para `docs/backlog/concluidos/` no fim da execução**.

**Ordem (plan §14).** Dados primeiro (594 e 595), selo (596 e 597), as três ações do credor (598, 599 e 600) e o reenvio (601 e 602), os avisos e a declaração de quem paga (603 e 604), a matriz de segurança (605), e por último a web (606 a 608) e a documentação (609). Dependências: 596 e 597 dependem da 595; 598 depende da 596 e da 597; 599 e 600 dependem da 598 (service e controller); 601 depende da 597; 602 não depende de nenhuma; 603 não depende de nenhuma; 604 depende da 594 e da 603; 605 depende da 598 a 604; 606 não depende de nenhuma; 607 depende da 604 e da 606; 608 depende da 597 a 601 e da 606; 609 por último.

**Regras de execução.** **Parar após cada task e perguntar ao usuário se segue.** Cada task é teste primeiro (teste que falha pelo motivo certo, implementação, teste verde, commit local). **Nenhum teste que já existia é editado**: todo teste novo entra em arquivo novo (plan, restrições globais); se um teste antigo quebrar, é sinal de regressão e a task para. O mutation check das regras de autorização e de consistência é registrado no `implementation.md`. A execução é no **modo nativo**, com **commit por task direto na branch da feature** (`feature/20261110-quem-paga-se-declara-e-credor-recusa`), sem sub-branches por task: é o desvio do ADR-003 que a feature anterior também fez e registrou em `implementation.md` §1. Antes do PR, a suíte inteira do backend roda com o banco de teste **zerado** e a do front com `--maxWorkers=1`. **Antes de empurrar a branch e abrir o PR único contra `dev`, a feature é validada localmente** (stack isolada, memória `validacao-local-stack-isolada`, com o layout medido nas cinco larguras) e ele autoriza o push, que usa refspec explícita.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-594 | Adicionar `created_by` ao vínculo `ex_group_member_responsibles` e gravá-lo em `MemberResponsibleService::replace` | backend | plan.md §1 | antes do deploy/migration em produção | Concluída |
| TASK-595 | Adicionar `accepted_at` e `refused_at` a `ex_settlement_confirmations` e os estados e o escopo no model `SettlementConfirmation` | backend | plan.md §1 | antes do deploy/migration em produção | Pendente |
| TASK-596 | Extrair o selo do ciclo para `CycleSummaryService::sealIfSettled`, sem mudar comportamento | backend | plan.md §2 | antes do merge | Pendente |
| TASK-597 | Fazer o selo contar só o comprovante que vale e expor `confirmationStatus` e `refusedAt` em `withConfirmations` | backend | plan.md §3 | antes do merge | Pendente |
| TASK-598 | Criar `POST /groups/{id}/settlements/accept`, o `SettlementConfirmationService` e o aviso `settlement_accepted` | backend | plan.md §4 | antes do merge | Pendente |
| TASK-599 | Criar `POST /groups/{id}/settlements/refuse` e o aviso `settlement_refused` | backend | plan.md §4 | antes do merge | Pendente |
| TASK-600 | Criar `DELETE /groups/{id}/settlements/confirmation`, que exclui o próprio comprovante | backend | plan.md §4 | antes do merge (hard delete aprovado) | Pendente |
| TASK-601 | Fazer `confirmSettlement` respeitar o aceite, limpar a recusa no reenvio e reavisar o credor | backend | plan.md §5 | antes do merge | Pendente |
| TASK-602 | Não avisar o credor (sino e WhatsApp) da confirmação que ele mesmo enviou (item 143) | backend | plan.md §5 | antes do merge | Pendente |
| TASK-603 | Criar a janela de 24 h contra aviso repetido de vínculo no `Notifier` (item 141) | backend | plan.md §6 | nenhum | Pendente |
| TASK-604 | Criar `PUT /groups/{id}/me/pays-for`, `MemberResponsibleService::declare` e o aviso `payment_responsible_self_declared` | backend | plan.md §7 | antes do merge | Pendente |
| TASK-605 | Escrever a matriz de autorização das rotas novas, o mutation check e rodar a revisão do `security-reviewer` | backend | plan.md §8 | antes do merge | Pendente |
| TASK-606 | Acrescentar os tipos e os textos do sino dos três avisos novos | frontend | plan.md §9 | nenhum | Pendente |
| TASK-607 | Criar o diálogo "Eu pago por…" e o botão e o texto na linha própria de Participantes | frontend | plan.md §10 | nenhum | Pendente |
| TASK-608 | Criar o hook `useSettlementDecision` e os botões e selos de aceitar, recusar e excluir em Pagamentos | frontend | plan.md §11 | antes do merge | Pendente |
| TASK-609 | Documentar o fluxo em `docs/sdd/01-specify.md` v1.4 | doc | plan.md §13 | nenhum | Pendente |

## Critérios de aceite

- **TASK-594**:
  - A migration `2026_11_10_000000_add_created_by_to_ex_group_member_responsibles_table` acrescenta `created_by` (bigint sem sinal, nulo) depois de `responsible_user_id`, com chave estrangeira para `ex_users` e `nullOnDelete`; o nome da chave tem no máximo 64 caracteres; `php artisan migrate:fresh --env=testing` e `migrate:rollback --step=1` rodam sem erro.
  - `GroupMemberResponsible` aceita `created_by` em massa. `replace()` grava `created_by` = o devedor nas linhas **novas** e **não** altera as que continuam.
  - Em arquivo novo: `test_replace_stamps_created_by_with_the_debtor`, `test_replace_keeps_created_by_of_the_links_that_stay`, `test_a_link_created_without_created_by_keeps_it_null`.
  - `MemberResponsibleServiceTest`, `GroupMemberResponsibleControllerTest` e os demais testes do vínculo seguem verdes sem edição. Mutation check: tirar `created_by` do `create` derruba o primeiro teste.

- **TASK-595**:
  - A migration `2026_11_10_000001_add_acceptance_to_ex_settlement_confirmations_table` acrescenta `accepted_at` e `refused_at` (timestamp, nulos) depois de `confirmed_at`; `migrate:fresh` e `migrate:rollback --step=1` rodam sem erro.
  - `SettlementConfirmation::status()` devolve `refused` se `refused_at` está preenchido; senão `accepted` se `accepted_at`; senão `awaiting_acceptance` se `isThirdParty()`; senão `sent`. `isThirdParty()` é verdadeiro só com `confirmed_by` não nulo e diferente de `from_user_id`.
  - O escopo `countsForSettlement` inclui o comprovante do devedor (`confirmed_by` nulo) e o de responsável **aceito**, e exclui o de responsável não aceito e **qualquer** recusado.
  - Em arquivo novo, com dataset: `test_status_for_every_combination` (as quatro saídas e a precedência do recusado) e `test_counts_for_settlement_scope_selects_only_the_ones_that_count`. Os testes de `SettlementConfirmation` que já existem seguem verdes sem edição. Mutation check: trocar a precedência e a condição do escopo derruba os testes.

- **TASK-596**:
  - `CycleSummaryService::sealIfSettled(Group, Carbon $start, Carbon $end): bool` tem o corpo de `ExpenseController::sealCycleIfSettled` sem mudança de comportamento: sela só se `isFullySettled`, não sela ciclo sem despesa real nem acerto, grava o `GroupCycleSnapshot` preservando `closed_manually_at` e `reopened_at`, e dispara `Notifier::cycleSettled` **só** na transição para selado.
  - `ExpenseController::sealCycleIfSettled` devolve `$this->cycles()->sealIfSettled(...)`; `unsealIfBroken` e `cycleIsFullySettled` ficam como estão.
  - Em arquivo novo: `test_it_seals_a_fully_settled_cycle`, `test_it_does_not_seal_with_a_pending_entry`, `test_it_does_not_seal_a_cycle_without_a_real_expense_or_settlement`, `test_it_notifies_only_on_the_transition_to_sealed`, `test_it_preserves_the_manual_closure_fields`.
  - **A suíte inteira do backend, com banco zerado, passa sem editar nenhum teste.** Mutation check: inverter a condição e a guarda do ciclo vazio derruba os testes novos.

- **TASK-597**:
  - `summaryIsFullySettled` usa `countsForSettlement`. Testes novos: `test_a_debtor_proof_still_settles_the_pair`, `test_a_third_party_proof_awaiting_acceptance_does_not_settle_the_cycle`, `test_an_accepted_third_party_proof_settles_the_cycle`, `test_a_refused_proof_does_not_settle_the_cycle`, `test_focus_keeps_a_cycle_whose_last_proof_awaits_acceptance`.
  - `withConfirmations` acrescenta `confirmationStatus` (`sent`, `awaiting_acceptance`, `refused`, `accepted` ou `null` sem comprovante) e `refusedAt`; no acerto **recusado**, `confirmedAt`, `confirmedProofUrl` e `confirmedNote` saem nulos e `confirmedBy` continua; o recusado fica entre os acertos não confirmados na ordenação. `summary`, `close` e `reopen` devolvem os campos novos, e um ciclo de snapshot os decora ao vivo.
  - `SettlementConfirmationControllerTest`, `SettlementConfirmationOnBehalfTest` e `ExpenseControllerCycleIsFullySettledTest` seguem verdes **sem edição**. Mutation check: tirar `confirmed_by` nulo, `accepted_at` ou `refused_at` do escopo, ou não anular os campos do recusado, derruba pelo menos um teste cada.

- **TASK-598**:
  - `POST /api/groups/{groupId}/settlements/accept` exige `from_user_id` e `to_user_id` (existentes) e aceita `cycles_ago`. Respostas: **404** para grupo inexistente ou excluído, para quem não é membro (antes do 403) e para comprovante inexistente; **403** se `to_user_id` não é o usuário logado, **antes** de qualquer consulta; **422** se o comprovante não é de responsável, não está `awaiting_acceptance`, o ciclo não está fechado ou já está selado.
  - Aceitar grava `accepted_at` e, depois da transação, chama `sealIfSettled`: sela quando o ciclo fica quitado e **não** sela quando ainda falta par ou há parcela pendente. A linha é travada e relida: dois aceites ao mesmo tempo dão um 200 e um 422.
  - `settlement_accepted` vai para `confirmed_by` (com `group_id`, `actorName` do credor, `amount`, `cycleLabel`, `groupId`, `groupName`) e **não** é gravado quando `confirmed_by` é o próprio credor.
  - Em arquivo novo, um teste por regra acima (`test_it_accepts_and_seals_the_cycle`, `test_it_accepts_and_does_not_seal_while_something_is_missing`, `test_it_returns_403_when_to_user_id_is_not_the_caller_before_any_lookup`, `test_it_returns_422_for_a_debtor_proof`, `test_it_returns_422_for_a_sealed_cycle`, `test_it_notifies_the_sender_but_not_the_creditor_himself`, entre outros). Mutation check: tirar o 403, a guarda do ciclo selado, o `sealIfSettled` ou o filtro do autor derruba cada um.

- **TASK-599**:
  - `POST /api/groups/{groupId}/settlements/refuse`, mesma entrada e mesmas respostas 404, 403 e 422 do aceite. Só de `awaiting_acceptance`; grava `refused_at`; **não apaga a linha nem o arquivo** (D4-B), e o teste confere que `proof_path` e o arquivo no disco continuam.
  - Depois de recusar, o resumo mostra o acerto como não confirmado (`confirmedAt` nulo, `confirmationStatus` `refused`) e o ciclo **não** sela. Recusar de novo, ou aceitar um recusado, dá 422.
  - `settlement_refused` vai para `confirmed_by` (com `debtorName`, de quem é o acerto) e para o devedor, com `group_id`, e para ninguém que seja o autor.
  - Em arquivo novo: `test_it_refuses_and_keeps_the_row_and_the_file`, `test_a_refused_pair_is_unconfirmed_in_the_summary_and_never_seals`, `test_it_notifies_the_sender_and_the_debtor`, `test_it_returns_422_when_refusing_twice`, e as recusas por ciclo selado e por não ser o credor. Mutation check: apagar o arquivo, não gravar `refused_at` ou avisar o autor derruba cada um.

- **TASK-600**:
  - `DELETE /api/groups/{groupId}/settlements/confirmation` lê `from_user_id`, `to_user_id` e `cycles_ago` da query. **403** para quem não é `confirmed_by` nem o devedor (inclusive o credor e o criador do grupo), antes de escrever; **404** para inexistente e para não membro; **422** para comprovante do próprio devedor, **aceito**, ciclo não fechado ou selado.
  - Apaga a linha e **depois** o arquivo com `ProofStorage::deleteIfUnreferenced`: um arquivo ainda referenciado por outra linha ou parcela **permanece**. Depois de excluir, o acerto volta a "a pagar" (`confirmationStatus` nulo) e aceita um `confirm` novo.
  - Quem enviou e já saiu da lista de responsáveis **ainda exclui** o próprio comprovante.
  - Em arquivo novo: `test_the_sender_deletes_a_refused_proof_and_the_file`, `test_the_debtor_deletes_an_awaiting_proof`, `test_a_shared_file_is_kept`, `test_an_accepted_or_own_proof_cannot_be_deleted`, `test_the_creditor_cannot_delete`, `test_a_former_responsible_can_still_delete_the_own_proof`. **O aval de hard delete (D7-A, 2026-11-10) é citado no `implementation.md` e vai ao corpo do PR.** Mutation check: apagar o arquivo antes da linha, deixar o credor excluir ou excluir o aceito derruba cada um.

- **TASK-601**:
  - Em `confirmSettlement`, **antes** de gravar o arquivo: comprovante **aceito** dá 422 "Este comprovante já foi aceito." e não grava nada (o arquivo anterior segue no disco, nenhum novo aparece). No reenvio, `accepted_at` e `refused_at` voltam a nulos; o aviso `settlement_confirmed` sai quando a linha é criada **ou** a anterior estava recusada, e **não** em reenvio de comprovante não recusado.
  - O devedor que reenvia por cima de um comprovante de responsável aguardando aceite o torna **seu** (`confirmed_by` nulo, estado `sent`, conta para o selo); outro responsável que reenvia vira o novo `confirmed_by`. O resto da rota (403 antes de gravar, `from_user_id`, `confirmed_by`) não muda.
  - Em arquivo novo: `test_resending_an_accepted_proof_returns_422_and_writes_nothing`, `test_resending_after_a_refusal_clears_the_refusal_and_renotifies_the_creditor`, `test_the_debtor_resending_over_a_responsible_proof_takes_it_over`, `test_resending_an_unrefused_proof_does_not_renotify`. `SettlementConfirmationControllerTest` e `SettlementConfirmationOnBehalfTest` seguem verdes **sem edição**. Mutation check: tirar a guarda do aceito, o reset ou a condição do aviso derruba cada um.

- **TASK-602**:
  - Quando quem envia é o próprio credor (`to_user_id` igual ao usuário logado), **nenhuma** notificação `settlement_confirmed` é gravada e **nenhuma** chamada sai pelo `WhatsAppNotifier::settlementProofConfirmed`. Quando é o devedor ou outro responsável, sino e WhatsApp saem como hoje.
  - Em arquivo novo: `test_the_creditor_sending_gets_no_bell_notice`, `test_the_creditor_sending_triggers_no_whatsapp`, `test_the_debtor_and_a_responsible_still_notify_the_creditor`. Mutation check: remover a condição do sino ou a do WhatsApp derruba o teste correspondente.

- **TASK-603**:
  - O helper privado `Notifier::oncePer(chave, minutos, callable)` envia só se a chave não existia (`Cache::add` com TTL), esquece a chave se o envio falhar e, se o cache lançar, vira log sem derrubar a ação. `paymentResponsibleAssigned` o usa com 24 h e a chave por (grupo, devedor, responsável, tipo).
  - Em arquivo novo: `test_toggling_the_list_within_24h_notifies_once`, `test_after_24h_it_notifies_again`, `test_another_pair_is_not_affected`, `test_a_failed_send_does_not_block_the_next_one`, `test_a_cache_failure_is_logged_and_the_action_still_succeeds`. Os testes de notificação de `GroupMemberResponsibleControllerTest` seguem verdes **sem edição**. Mutation check: TTL zero e não esquecer a chave derrubam os testes.

- **TASK-604**:
  - `PUT /api/groups/{groupId}/me/pays-for` com `debtor_user_ids` (`present|array|max:50`, itens inteiros). **404** para grupo inexistente, excluído e para não membro; **422** para si mesmo, repetido, não membro, mais de 50 ("Escolha no máximo 50 pessoas.") e campo ausente, com **nada** alterado (compara o estado antes e depois). 200 `{ message: 'Escolha salva.', pays_for: [{ id, name }] }`, só id e nome, em ordem de nome.
  - `MemberResponsibleService::declare` troca a lista de quem a pessoa paga: cria as linhas novas com `created_by` = quem se declarou, apaga as que saíram (inclusive as que o devedor criou, D8), não recria as que ficam, e é feito com o grupo travado e relido. A lista vazia deixa todos os papéis.
  - `payment_responsible_self_declared` vai para cada devedor que **entrou** (com `group_id` e `actorName`), uma vez por 24 h por par; não vai para quem já estava nem para quem saiu.
  - O responsável declarado **consegue** `confirmSettlement` com `from_user_id` do devedor (teste de integração), e o devedor que omite quem se declarou no `PUT .../responsibles` **remove** o vínculo (sem mudança de código).
  - Em arquivo novo: um teste por regra acima, mais `test_the_declaration_is_refused_when_the_debtor_left_the_group`. Mutation check: aceitar a si mesmo, não conferir membro, não gravar `created_by` e avisar quem já estava derrubam cada um.

- **TASK-605**:
  - Matriz de autorização em arquivo novo para as quatro rotas novas (`me/pays-for`, `accept`, `refuse`, `confirmation`), com os papéis devedor, responsável, credor, terceiro, criador do grupo e não membro, mais grupo excluído, ciclo aberto, ciclo selado, comprovante inexistente e comprovante do próprio devedor. Os status esperados (404 antes de 403, 403, 422) ficam escritos no teste.
  - **Mutation check** das regras R1, R5, R6 e R9, registrado no `implementation.md`, com cada mutante morto (inclusive "escrever antes do 403" e "aceitar em ciclo selado"); arquivo restaurado e conferido.
  - O agent `security-reviewer` roda sobre as rotas, controllers e services novos e o resultado vai ao `implementation.md`; nada alto ou médio fica sem decisão dele. Suíte inteira do backend com banco zerado e `./vendor/bin/pint --test` verdes.

- **TASK-606**:
  - `SummarySettlement` ganha `confirmationStatus?` (união das quatro) e `refusedAt?`, e `npx tsc --noEmit` continua limpo com as fixtures que já existem.
  - `notificationText.ts` devolve, para `payment_responsible_self_declared`, `settlement_refused` e `settlement_accepted`, os textos do plan §9, e para `settlement_confirmed` **com** `paidByName` o complemento "Aguarda o seu aceite."; sem `paidByName` o texto não muda. O clique no sino abre o resumo do grupo para os tipos novos.
  - Teste em arquivo novo (`notificationText.acceptance.test.ts`); os testes de texto que já existem seguem verdes sem edição.

- **TASK-607**:
  - `PaysForDialog.tsx` lista só os **outros** membros, faz o `PUT .../me/pays-for`, mostra "Escolha salva." e recarrega os membros; o erro mostra "Não foi possível salvar agora. Tente novamente." e mantém o diálogo aberto; salvar a lista vazia é permitido.
  - Em `GroupMembersForm`, o botão "Eu pago por…" só aparece na linha **própria**, na ordem "Quem paga por mim", "Eu pago por…", "Sair do grupo", e o texto "Você paga por: Ana, Davi" só aparece quando há alguém; as outras linhas não mudam, e os quatro arquivos de teste de `GroupMembersForm` que já existem seguem verdes **sem edição**.
  - **Layout medido no navegador em 375, 768, 1024, 1280 e 1440 px**: a coluna do e-mail nunca fica sem espaço (uma linha em 768 px ou mais), os botões ficam dentro do card, sem rolagem horizontal; os números vão ao `implementation.md`.
  - Testes em arquivos novos e mutation check; `tsc` limpo; `vitest related` dos arquivos tocados.

- **TASK-608**:
  - `useSettlementDecision` faz `POST .../accept`, `POST .../refuse` e `DELETE .../confirmation` com o cabeçalho de autorização e `cycles_ago`, e devolve a mensagem do servidor em 404 e 422.
  - `PayableSettlementList` e `PaymentSettlementsPanel` mostram a tabela do plan §11 para cada papel e estado: **credor** com `awaiting_acceptance` vê "Aguardando seu aceite" e os botões Aceitar e Recusar, e nenhum outro papel os vê; **devedor e quem paga por ele** com `awaiting_acceptance` veem "Aguardando aceite do credor" e Excluir; com `refused`, "Recusado por {credor}", "Enviar comprovante" e Excluir; com `accepted`, "Aceito pelo credor" sem reenvio nem exclusão. O acerto recusado aparece entre os "a pagar".
  - A exclusão pede confirmação num diálogo; depois de cada ação a tela recarrega o ciclo; falha mostra a mensagem e reabilita os botões.
  - Em arquivos novos, um teste por papel × estado. `Payments.test.tsx`, `Payments.responsible.test.tsx` e `PayableSettlementList.responsible.test.tsx` seguem verdes **sem edição**. Layout dos cartões medido em 375 e 1440 px. `tsc` limpo, `vitest related` e mutation check.

- **TASK-609**:
  - `docs/sdd/01-specify.md` v1.4 acrescenta, no glossário, que quem paga também pode **se declarar** e que o comprovante de responsável passa pelo **aceite do credor**; um bloco em §3.3 (declarar, aceitar, recusar, excluir; o hard delete aprovado só do próprio comprovante) e uma regra em §4. A Constitution **não** é editada (`git diff --stat` não lista `00-constitution.md`).
