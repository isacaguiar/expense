# Tasks — Responsável do devedor

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.1 · Criado em: 20261109

**v1.1 (2026-11-09):** a D3 passa a B (nenhum ou vários responsáveis): mudam os critérios das TASK-583, 584, 585, 590, 591 e 592 e os títulos das 584 e 585; nenhuma task nova, e as TASK-586 a 589 e 593 não mudam.

**Status: aprovada em 2026-11-09 e em execução.** Specify v1.1, plan v1.1 e tasks v1.1, com a **D3 trocada para B** a pedido dele e a **D9** e a **D10** como recomendado (A e A), na resposta "siga". A execução é no modo nativo, parando após cada task.

IDs a partir de **TASK-583**: o maior em `docs/` e nas mensagens de commit de todas as refs é TASK-582 (feature `20261103-convite-pendente-no-grupo`), conferido em 2026-11-09 com busca em `docs/` e `git log --all`. Reconfirmar antes de criar a primeira branch de task, caso outra feature em andamento reserve IDs.

Escopo: `backend/`, `frontend/` e documentação. Origem: pedido do usuário na conversa de 2026-11-09, sem item de backlog.

**Ordem (plan §13).** Base de dados e service, rotas de vínculo, lista e saída de membros, coluna e `confirmSettlement`, matriz de segurança, e por último a web e a documentação. Dependências: 584, 585 e 587 dependem da 583; 587 depende da 586; 588 e 589 dependem da 587; 589 também da 584; 590 não depende de nenhuma; 591 depende da 584, 585 e 590; 592 depende da 587, 588 e 590; 593 por último.

**Regras de execução.** **Parar após cada task e perguntar ao usuário se segue.** Cada task é teste primeiro (teste que falha pelo motivo certo, implementação, teste verde, commit). **Nenhum teste que já existia é editado**: todo teste novo entra em arquivo novo (plan §11). O mutation check das regras de autorização e de consistência é registrado no `implementation.md`. A branch da feature (`feature/20261109-responsavel-do-devedor`) recebe a primeira task direto; as seguintes nascem de `feature/20261109-responsavel-do-devedor-TASK-0xx` e voltam com merge local `--no-ff` (ADR-003, `04-implementation.md` §1). **Antes de empurrar a branch e abrir o PR único contra `dev`, a feature é validada localmente** (stack isolada, memória `validacao-local-stack-isolada`) e o usuário autoriza o push; o push usa refspec explícita.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-583 | Criar a tabela `ex_group_member_responsibles`, o model `GroupMemberResponsible` e o `MemberResponsibleService` | backend | plan.md §1, §2 | antes do deploy/migration em produção | Concluída |
| TASK-584 | Criar `PUT /groups/{id}/members/{userId}/responsibles`, que substitui a lista de responsáveis, e o aviso `payment_responsible_assigned` | backend | plan.md §3, §7 | antes do merge | Concluída |
| TASK-585 | Trazer `responsibles` em `GET /groups/{id}/members` e apagar os vínculos na saída ou remoção de membro | backend | plan.md §4 | nenhum | Concluída |
| TASK-586 | Acrescentar `confirmed_by` a `ex_settlement_confirmations` e a relação `confirmedByUser` | backend | plan.md §1 | antes do deploy/migration em produção | Concluída |
| TASK-587 | Fazer `POST /groups/{id}/settlements/confirm` aceitar `from_user_id` do dependente e gravar `confirmed_by` | backend | plan.md §5, §7 | antes do merge | Concluída |
| TASK-588 | Expor `confirmedBy` nos acertos de `summary`, `close` e `reopen` | backend | plan.md §6 | nenhum | Concluída |
| TASK-589 | Cobrir a matriz de autorização do `from_user_id` e rodar a revisão de segurança | backend | plan.md §11 | antes do merge | Concluída |
| TASK-590 | Acrescentar os tipos `MemberResponsible` e `confirmedBy` e os textos do sino | frontend | plan.md §8 | nenhum | Concluída |
| TASK-591 | Criar o diálogo "Quem pode pagar por você" e mostrar os vínculos em Participantes | frontend | plan.md §9 | nenhum | Pendente |
| TASK-592 | Mostrar e enviar o pagamento por quem o usuário paga em Pagamentos | frontend | plan.md §10 | nenhum | Pendente |
| TASK-593 | Documentar o vínculo em `docs/sdd/01-specify.md` | doc | plan.md §12 | nenhum | Pendente |

## Critérios de aceite

- **TASK-583**:
  - Migration nova `backend/database/migrations/2026_11_09_000000_create_ex_group_member_responsibles_table.php` com **só** `Schema::create` (`down()` faz `dropIfExists`), com as colunas do plan §1: FKs com `onDelete('cascade')`, `timestamps()`, índice único `['group_id', 'user_id', 'responsible_user_id']` de nome explícito `member_responsible_unique` e índice `['group_id', 'responsible_user_id']` de nome explícito `member_responsible_by_responsible_index` (o nome gerado pelo Laravel para o primeiro passaria dos 64 caracteres do MySQL). `cd backend && php artisan migrate:fresh --env=testing --force` termina sem erro.
  - Model `App\Models\GroupMemberResponsible` com `group()`, `debtor()` e `responsible()`; `Group::memberResponsibles()`.
  - `App\Services\MemberResponsibleService` com `replace`, `clearFor`, `canPayFor` e `responsiblesByDebtor` nas assinaturas do plan §2, e `App\Exceptions\MemberResponsibleRefused`.
  - `backend/tests/Feature/MemberResponsibleServiceTest.php`, com: `test_replace_creates_the_links_for_a_list_of_responsibles` (duas linhas, `added` com os dois ids), `test_replace_keeps_the_row_of_who_stays_and_reports_added_and_removed`, `test_replace_with_an_empty_list_removes_every_link_of_the_debtor`, `test_replace_with_the_same_list_changes_nothing` (`added` e `removed` vazios), `test_replace_refuses_the_debtor_as_their_own_responsible`, `test_replace_refuses_a_repeated_id`, `test_replace_refuses_a_responsible_who_is_not_a_member`, `test_replace_refuses_when_the_debtor_is_not_a_member`, `test_replace_changes_nothing_when_any_id_is_refused` (a lista antiga continua igual), `test_replace_aborts_with_404_when_the_group_is_deleted`, `test_clear_for_deletes_links_in_both_directions_and_returns_the_count`, `test_can_pay_for_requires_the_exact_group_debtor_and_responsible`, `test_responsibles_by_debtor_returns_a_name_sorted_list_with_only_id_and_name`, `test_the_same_responsible_can_serve_several_debtors`.
  - Mutation check: tirar a conferência de membro dentro de `replace` faz o teste correspondente falhar.
  - `./vendor/bin/pint --test` limpo e os testes verdes.

- **TASK-584**:
  - A rota nova dentro do grupo `groups/{groupId}/members` de `routes/api.php`; `GroupMemberResponsibleController::update` na ordem de checagens do plan §3 (sem `destroy`); `Notifier::paymentResponsibleAssigned` (plan §7).
  - `backend/tests/Feature/GroupMemberResponsibleControllerTest.php`, com: `test_debtor_sets_two_responsibles` (200, `responsibles` com `id` e `name`, duas linhas), `test_put_with_another_list_adds_removes_and_keeps_who_stays`, `test_put_with_an_empty_list_removes_every_link`, `test_put_without_the_list_returns_422`, `test_put_from_another_member_returns_403`, `test_creator_cannot_set_someone_elses_responsibles` (403), `test_non_member_gets_404`, `test_deleted_group_gets_404`, `test_put_with_a_member_who_left_returns_422_and_changes_nothing`, `test_responsible_cannot_be_the_debtor` (422), `test_repeated_responsible_returns_422`, `test_invalid_ids_return_422` (texto, lista dentro da lista e id inexistente), `test_put_requires_authentication` (401), `test_delete_on_the_route_returns_405`.
  - Notificação: `test_put_notifies_each_new_responsible_once_with_group_id`, `test_identical_put_does_not_notify_again`, `test_put_notifies_only_who_entered_the_list`, `test_removing_from_the_list_notifies_nobody`, `test_notifier_failure_does_not_undo_the_links`. A linha gravada tem `type = payment_responsible_assigned`, `group_id` e `data.groupId`.
  - Mutation check: liberar o criador no 403 de `update` faz `test_creator_cannot_set_someone_elses_responsibles` falhar. O lock do grupo e a revalidação dentro da transação (F1, F2) são conferidos na leitura do diff e no mutation check, e o teste cobre o resultado observável (`test_put_with_a_member_who_left_returns_422_and_changes_nothing`).

- **TASK-585**:
  - `GroupMemberController::index` devolve `responsibles` (lista de `{id, name}`, vazia quando não há) por membro; `destroy` chama `clearFor` logo depois do `detach`, dentro da transação (plan §4).
  - `backend/tests/Feature/GroupMemberResponsibleMembersTest.php`, com: `test_members_list_includes_the_responsibles_with_id_and_name_only` (sem `email` nem `pix` do responsável), `test_members_without_responsibles_have_an_empty_list`, `test_a_debtor_with_two_responsibles_lists_both`, `test_leaving_clears_the_links_where_the_user_is_the_debtor`, `test_leaving_clears_the_links_where_the_user_is_a_responsible` (foco de revisão 5; o dependente e os outros responsáveis continuam), `test_creator_removing_a_member_clears_their_links`, `test_blocked_exit_keeps_the_links` (o 422 de bloqueio não apaga nada), `test_last_member_leaving_clears_the_links`.
  - `php artisan test --filter=GroupMemberControllerTest` segue verde **sem edição** do arquivo.
  - Mutation check: tirar o `clearFor` de `destroy` faz os testes de saída falharem.

- **TASK-586**:
  - Migration nova `2026_11_09_000001_add_confirmed_by_to_ex_settlement_confirmations_table.php` com **só** `Schema::table` que acrescenta `confirmed_by` (nula, FK com `nullOnDelete()`); `confirmed_by` no `$fillable` e `SettlementConfirmation::confirmedByUser()`.
  - `migrate:fresh --env=testing --force` sem erro, e `migrate:rollback --step=1 --env=testing` seguido de nova migração também sem erro (registrar no `implementation.md`).
  - `backend/tests/Feature/SettlementConfirmationOnBehalfTest.php` nasce aqui, com `test_confirmed_by_defaults_to_null_and_becomes_null_when_the_user_is_deleted` e `test_confirmed_by_user_relation_returns_the_sender`.
  - `SettlementConfirmationControllerTest` segue verde sem edição.

- **TASK-587**:
  - `ExpenseController::confirmSettlement` na forma do plan §5: `from_user_id` opcional (`nullable|integer|exists:ex_users,id`), o 403 **antes** de qualquer consulta de ciclo e de `ProofStorage::store`, `$debtorId` no lugar de `auth()->id()` na busca e na chave, `confirmed_by` gravado, helper privado `responsibles()`; `Notifier::settlementConfirmed` com o último parâmetro `?string $paidByName = null`.
  - Em `SettlementConfirmationOnBehalfTest`: `test_responsible_confirms_the_debtors_settlement` (200, linha com `from_user_id` do dependente e `confirmed_by` do responsável, arquivo gravado), `test_debtor_confirmation_keeps_confirmed_by_null`, `test_explicit_from_user_id_equal_to_the_caller_behaves_as_today`, `test_resend_by_the_debtor_after_the_responsible_resets_confirmed_by_and_leaves_one_file` (foco 1), `test_resend_by_the_responsible_after_the_debtor_replaces_the_proof_and_sets_confirmed_by`, `test_responsible_removed_from_the_list_loses_the_right_at_once_and_a_new_one_passes` (foco 3), `test_two_responsibles_can_each_confirm_for_the_same_debtor` (o último vence), `test_confirmation_keeps_confirmed_by_after_the_link_is_removed` (F9), `test_responsible_who_is_also_the_creditor_is_accepted` (foco 4), `test_responsible_confirm_requires_a_closed_cycle` (422, R9), `test_creditor_is_notified_with_paid_by_name_and_the_debtor_as_actor`, `test_debtor_confirmation_notification_has_no_paid_by_name`, `test_whatsapp_to_the_creditor_still_names_the_debtor`.
  - `php artisan test --filter=SettlementConfirmationControllerTest` segue verde, com os 28 testes e **sem edição** do arquivo.
  - Mutation check: trocar `canPayFor` por `true` faz `test_responsible_removed_from_the_list_loses_the_right_at_once_and_a_new_one_passes` e a matriz da TASK-589 falharem.

- **TASK-588**:
  - `CycleSummaryService::withConfirmations` carrega `confirmedByUser:id,name` e acrescenta `confirmedBy` na forma do plan §6.
  - Em `SettlementConfirmationOnBehalfTest`: `test_summary_exposes_confirmed_by_only_when_the_sender_differs_from_the_debtor`, `test_summary_confirmed_by_is_null_when_the_debtor_sent_it`, `test_summary_confirmed_by_is_null_when_nobody_confirmed`, `test_close_and_reopen_also_expose_confirmed_by`, `test_a_snapshot_cycle_decorates_confirmed_by_live`.
  - Os testes de resumo que já existem em `SettlementConfirmationControllerTest` seguem verdes sem edição.

- **TASK-589**:
  - Arquivo novo `backend/tests/Feature/ResponsibleAuthorizationMatrixTest.php` com a matriz do `POST .../settlements/confirm` e `from_user_id` de outro membro: `test_without_any_link_returns_403`, `test_link_in_another_group_returns_403`, `test_removed_link_returns_403`, `test_link_for_another_debtor_returns_403` (foco 2), `test_unknown_from_user_id_returns_422`, `test_non_member_caller_gets_404`, `test_former_member_as_debtor_cannot_be_paid_for` (R16). Em **todos** os casos de 403: nenhuma linha em `ex_settlement_confirmations` e nenhum arquivo gravado (`Storage::fake` sem arquivos).
  - O agent `security-reviewer` roda na rota nova e na mudança do `confirmSettlement`; os achados e o tratamento entram no `implementation.md`. Achado de severidade alta ou média bloqueia o merge da feature.
  - Mutation check das regras R2, R3, R6 e R12 (as quatro), registrado no `implementation.md`.
  - `./vendor/bin/pint --test` limpo e a suíte inteira do backend verde (`php artisan test`).

- **TASK-590**:
  - `frontend/src/types/group.ts` com `MemberResponsible`, `GroupMember.responsibles?` (lista) e `GroupMemberBasic` omitindo também `responsibles`; `SummarySettlement.confirmedBy?` em `useGroupCycle.ts`; `notificationText.ts` com os dois casos do plan §8.
  - `frontend/src/components/notificationText.responsible.test.ts` (arquivo novo): `payment_responsible_assigned` vira `Você agora pode pagar por {actorName} no grupo "{groupName}"`; `settlement_confirmed` com `paidByName` vira `{paidByName} confirmou um pagamento de R$ ... por {actorName}`; sem `paidByName` mantém o texto de hoje.
  - `cd frontend && npx tsc --noEmit` limpo (as fixtures que já existem seguem compilando, plan ajuste 5).

- **TASK-591**:
  - `frontend/src/components/PaymentResponsibleDialog.tsx` com as props do plan §9; `GroupMembersForm.tsx` com o botão "Quem paga por mim" **só** na linha do próprio usuário, os textos secundários, os selos "Paga por {nome}" e a frase extra no diálogo de sair e remover.
  - `PaymentResponsibleDialog.test.tsx` (novo): escolher duas pessoas e salvar faz `PUT` com `{responsible_user_ids: [a, b]}` em `.../members/{usuário logado}/responsibles`; abrir com responsáveis atuais os mostra como selos; esvaziar o seletor e salvar envia `{responsible_user_ids: []}` e mostra "Escolha desfeita."; salvar com gente mostra "Escolha salva."; o próprio usuário não aparece entre as opções; sem outros membros mostra "Não há outros membros neste grupo ainda. Convide alguém para poder escolher."; erro mostra "Não foi possível salvar agora. Tente novamente."
  - `GroupMembersForm.responsible.test.tsx` (novo): botão só na linha do próprio usuário (nunca nas de outros, nem para o criador); `Quem paga por você: {nome}, {nome}` na linha própria (dois responsáveis); `Quem paga por {membro}: {responsáveis}` nas de outros; selo `Paga por {dependente}` na linha do responsável; a frase extra aparece ao remover ou sair quem paga por alguém e não aparece nos demais casos; salvar recarrega os membros.
  - `GroupMembersForm.test.tsx`, `GroupMembersForm.exit.test.tsx` e `GroupMembersForm.invitations.test.tsx` seguem verdes **sem edição**. `npx tsc --noEmit` limpo.

- **TASK-592**:
  - `PayableSettlementList.tsx`, `PaymentSettlementsPanel.tsx`, `utils/cycleOrdering.ts` e `Payments.tsx` na forma do plan §10; `GroupPositionCard` e `useConfirmSettlement` intocados.
  - `PayableSettlementList.responsible.test.tsx` (novo): acerto de quem o usuário paga mostra o selo `Você paga por {nome}` e os botões "Pagar com Pix" e "Enviar comprovante" (ou "Reenviar comprovante"); acerto de terceiro e acerto que o usuário **recebe** (foco 4) não mostram botão; o aviso "Disponível após o fechamento do ciclo." vale igual; `Enviado por {nome}` aparece só com `confirmedBy`.
  - `cycleOrdering.responsible.test.ts` (novo): as saídas do usuário, depois as de `alsoFirstIds`, depois as demais, sem mutar o argumento.
  - `Payments.responsible.test.tsx` (novo): o `POST` leva `from_user_id` ao pagar por um dependente e **não** leva ao pagar por si mesmo.
  - Os testes que já existem de `PayableSettlementList`, `PaymentSettlementsPanel` e `Payments` seguem verdes sem edição; `npx tsc --noEmit` limpo; a suíte inteira do frontend verde com `--maxWorkers=1`, conferindo que rodaram todos os arquivos.

- **TASK-593**:
  - `docs/sdd/01-specify.md` com a linha de glossário (§2), o parágrafo de §3.3 e a regra de §4, conforme plan §12. A Constitution não é editada; se algo nela ficar desatualizado, é só sinalizado ao usuário.
