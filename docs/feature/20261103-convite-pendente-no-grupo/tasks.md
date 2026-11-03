# Tasks — Convite Pendente com Aceite ou Recusa para Entrar no Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261103

**Status: v1.0, aguardando aprovação.** O specify (v1.0, D1 a D9 como recomendado) e o plan (v1.0, com os ajustes 1 a 7) foram aprovados em 2026-11-03.

IDs a partir de **TASK-566**: o maior em `docs/` e nas mensagens de commit de todas as refs é TASK-565 (feature `20261030-saida-e-remocao-de-membro`), conferido em 2026-11-03 com `git grep` e `git log --all`. Reconfirmar a numeração antes de criar a primeira branch de task, caso outra feature em andamento reserve IDs.

Escopo: `backend/`, `frontend/` e documentação. Origem: item 133 do backlog (`docs/backlog/convite-pendente-com-aceite-ou-recusa-para-entrar-no-grupo.md`).

**Ordem (plan §14).** Primeiro a base de dados, depois o `Notifier`, depois o único ponto de entrada (`store()`), depois responder o convite, depois listar e cancelar, depois a matriz de autorização e a revisão de segurança, e por último a web e a documentação, para o PR único nunca ter tela apontando para endpoint incompleto. Dependências: a 567 não depende de nenhuma (fica depois da 566 só pela ordem de leitura); a 568 depende da 566 e da 567; a 569 sai depois da 568 (o texto descreve o fluxo que ela cria); a 570 e a 573 dependem só da 566; a 571 depende da 566 e da 567; a 572 da 566 e da 568 (um caso reconvida quem recusou); a 574 da 566, da 568 e da 571 (casos de reconvidar e de aceitar depois de cancelar); a 575 só faz sentido depois de 568 a 574, e a 576 depois da 575. No frontend, a 577 não depende de nada além do contrato, a 578 e a 579 criam os tipos que usam, e a 580 depende da 579 (recarrega a lista de pendentes). Nenhuma task de frontend precisa esperar o backend para ser **escrita**; ficam depois dele porque a validação local do usuário exercita as duas pontas juntas.

**Nenhum teste que já existia antes da feature é editado**, com três exceções, todas por design e já aprovadas no plan: os três testes backend do ajuste 1 (TASK-568), a linha do botão no teste de adicionar membro (TASK-580) e o ramo novo do helper `mockGroupsAndMe` do `Dashboard.test.tsx`, sem mexer em nenhuma asserção (TASK-578). Todo teste novo entra em caso novo ou arquivo novo.

**Execução de cada task backend:** o banco de teste precisa estar de pé (contêiner `mysql-expense-test` na 3307 e `backend/.env.testing`, receita local da memória `validacao-local-stack-isolada`), com `php artisan migrate:fresh --env=testing --force` depois da TASK-566. O mutation check de cada regra de autorização ou de consistência segue o plan §12 e é registrado no `implementation.md`. A suíte inteira do backend roda na TASK-568, que muda o comportamento de uma rota existente, e de novo no checklist final.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-566 | Criar a tabela `ex_group_invitations`, o model `GroupInvitation` (com o escopo `open()`) e o helper de teste de convites | backend | plan.md §1 | antes do deploy/migration em produção | Concluída |
| TASK-567 | Criar `Notifier::groupInvitation` e `Notifier::groupInvitationAccepted`, e fazer `guard` devolver `bool` | backend | plan.md §6 | nenhum | Concluída |
| TASK-568 | Fazer `POST /groups/{id}/members` criar ou renovar um convite pendente em vez de adicionar o membro (`GroupInvitationService::invite`) | backend | plan.md §2, §8 | antes do merge | Concluída |
| TASK-569 | Trocar o texto do e-mail de convite para o de convite pendente | backend | plan.md §7 | nenhum | Concluída |
| TASK-570 | Criar `GET /me/invitations`, os convites recebidos pelo usuário | backend | plan.md §5, §8 | nenhum | Concluída |
| TASK-571 | Criar `POST /me/invitations/{id}/accept` (`GroupInvitationService::accept`) | backend | plan.md §3, §8 | antes do merge | Pendente |
| TASK-572 | Criar `POST /me/invitations/{id}/decline` | backend | plan.md §3, §8 | nenhum | Pendente |
| TASK-573 | Criar `GET /groups/{id}/invitations`, os convites pendentes do grupo | backend | plan.md §4, §8 | nenhum | Pendente |
| TASK-574 | Criar `DELETE /groups/{id}/invitations/{invitationId}`, que cancela um convite pendente | backend | plan.md §4, §8 | nenhum | Pendente |
| TASK-575 | Travar com teste a matriz de autorização do convidado pendente (`PendingInviteeSecurityTest`) | backend | plan.md §3, §5, §12 | nenhum | Pendente |
| TASK-576 | Rodar o `security-reviewer` nas rotas e controllers novos e tratar os achados | backend | plan.md §8, §12 | nenhum | Pendente |
| TASK-577 | Mostrar no sino os convites recebido e aceito (textos e clique em `group_invitation`) | frontend | plan.md §9 | nenhum | Pendente |
| TASK-578 | Criar `ReceivedInvitations` (Aceitar e Recusar) e montá-lo em Meus Grupos | frontend | plan.md §9, §10 | nenhum | Pendente |
| TASK-579 | Mostrar em Participantes a lista de convites pendentes com **Cancelar** | frontend | plan.md §9, §11 | nenhum | Pendente |
| TASK-580 | Trocar "Adicionar" por "Convidar" em Participantes, com a mensagem de convite enviado | frontend | plan.md §11 | nenhum | Pendente |
| TASK-581 | Atualizar `01-specify.md` §3.2 e §3.3 para o convite pendente | doc | plan.md §13 | nenhum | Pendente |

## Critérios de aceite

- **TASK-566**:
  - Migration nova `backend/database/migrations/2026_11_03_000000_create_ex_group_invitations_table.php` com **só** `Schema::create` (`down()` faz `dropIfExists`; nenhum `drop`, `rename` ou alteração em tabela existente), com as colunas do plan §1: `group_id` e `user_id` com FK e `onDelete('cascade')`, `invited_by` nulo com `nullOnDelete()`, `status` `string(16)` com default `pending`, `expires_at` em `dateTime`, `notified_at` e `responded_at` nulos, `timestamps()`, `unique(['group_id','user_id'])` e `index(['user_id','status'])`.
  - `cd backend && php artisan migrate:fresh --env=testing --force` termina sem erro no MySQL de teste.
  - Model `App\Models\GroupInvitation` com as constantes (os quatro status, `VALIDITY_DAYS = 7`, `RENOTIFY_AFTER_HOURS = 24`), casts de data, `group()`, `invitee()`, `inviter()` e o escopo `open()` (`status = pending` e `expires_at > now()`); `Group::invitations()`.
  - Trait de teste `backend/tests/Concerns/BuildsGroupInvitations.php` com um helper que cria um convite no estado pedido (`pending`, `accepted`, `declined`, `cancelled`, vencido), para as tasks seguintes reutilizarem.
  - Arquivo novo `GroupInvitationModelTest`: `open()` devolve só convite `pending` com `expires_at` no futuro (o `pending` vencido, o que vence **neste instante** e os três outros status ficam de fora); segundo convite para o mesmo par (grupo, usuário) viola a unicidade; apagar o grupo apaga os convites; apagar o convidado apaga os convites; apagar quem convidou deixa `invited_by` nulo e mantém o convite; as colunas esperadas existem (`Schema::hasColumns`).
  - `php artisan test --filter GroupInvitationModelTest` verde; `git diff --stat -- backend/routes backend/app/Http backend/app/Support` vazio (nenhum comportamento muda); `./vendor/bin/pint --test` limpo.
- **TASK-567**:
  - `Notifier::groupInvitation(Group, int $inviteeId, ?string $actorName): bool` e `Notifier::groupInvitationAccepted(Group, int $inviterId, ?string $accepterName): void` em `backend/app/Support/Notifier.php`; `guard()` devolve `true` quando o callback roda sem exceção e `false` quando a captura (e continua logando); `fanOut` e os métodos existentes, **inclusive `groupMemberAdded`**, sem diff.
  - Arquivo novo `NotifierInvitationTest`: `groupInvitation` grava 1 linha para o convidado com `type = group_invitation`, `group_id` **nulo** e `data` exatamente `{actorName, groupName}` (sem `groupId`) e devolve `true`; `groupInvitationAccepted` grava 1 linha para quem convidou com `type = group_invitation_accepted`, `group_id` do grupo e `data` com `actorName` (quem aceitou), `groupId` e `groupName`; falha ao gravar (byte inválido em UTF-8, como o teste existente de `group_member_removed`) devolve `false`, não propaga e registra `Log::warning`.
  - `NotifierTriggersTest` e `NotificationModelTest` verdes e **sem edição**; `pint --test` limpo.
- **TASK-568**:
  - `backend/app/Services/GroupInvitationService.php` com `invite(Group, string $email, User $inviter): ?GroupInvitation` conforme o plan §2: transação com `lockForUpdate` no grupo e releitura de `deleted` (404); cria o `User` quando o e-mail é novo; devolve `null` quando já é membro; cria, renova (mantém `invited_by`) ou reabre a linha do par; **depois do commit**, avisa por `Notifier::groupInvitation` quando `notified_at` é nulo ou tem mais de 24 h e grava `notified_at` **só se devolveu `true`**; para `User` novo grava o token `invitation-token:<e-mail>` por 2 dias e envia `UserInvitedMail` dentro de `try/catch` (`Log::warning` em falha).
  - `GroupMemberController::store` mantém validação, `authorizeMembership` e o 404 de grupo excluído, delega ao service e responde 201 `{message: 'Convite enviado.', pending: true, invitation: {id, email, expires_at}}`, com o 409 de hoje quando o service devolve `null`. Saem do controller o `attach`, o `Notifier::groupMemberAdded` e o trecho de criação de `User`, token e e-mail; **`Notifier::groupMemberAdded` é removido** (`git grep -n groupMemberAdded -- backend` vazio).
  - Arquivo novo `GroupInvitationCreateTest`: **CA-1** (201 com `pending: true`; nenhuma linha nova em `ex_groups_members`; o convidado fora de `GET /groups/{id}/members` e de `members` em `GET /groups`; o grupo fora de `GET /groups` do convidado); **CA-2** (mesmas chaves e mesmo `message` para e-mail com e sem conta; 409 para quem já é membro, inclusive o próprio chamador; 404 para não membro e para grupo excluído, **sem** criar `User` nem convite); **CA-3** (repetir não duplica a linha e renova `expires_at`; `invited_by` do primeiro é mantido; sem novo aviso dentro de 24 h e com novo aviso 25 h depois, com `Carbon::setTestNow`); **CA-9** (convidado com conta recebe `group_invitation` com `group_id` nulo e sem `data.groupId`; falha do `Notifier` não derruba o 201 e deixa `notified_at` nulo); **CA-10** (e-mail novo: cria o `User`, grava o token de cache com 2 dias, envia `UserInvitedMail` e **não** cria vínculo); **R15** (`Mail` lançando exceção: 201, `User` e convite existem, log de aviso); **F6** (dois membros convidam a mesma pessoa: uma linha); **R13/D9-A** (convite recusado, cancelado, vencido e aceito-e-depois-saiu é reaberto: `pending`, novo `invited_by`, `responded_at` e `notified_at` nulos, novo `expires_at`, 201).
  - Testes existentes que mudam, e **só eles**: `GroupMemberControllerTest::test_member_can_add_member_to_group` (passa a afirmar convite pendente e nenhum vínculo), `NotifierTriggersTest::test_group_member_added_notifies_the_new_member_only` (vira o caso de `group_invitation`) e `NotifierTriggersTest::test_group_member_added_does_not_notify_when_already_a_member` (troca o tipo para `group_invitation`). `GroupMemberInvitationMailTest`, `GroupMemberInvitationTokenTest`, `FormerMemberSecurityReviewTest`, `NotificationModelTest` e `EmailBrandingTest` passam **sem edição**.
  - `cd backend && php artisan test` (suíte inteira) verde e `./vendor/bin/pint --test` limpo; `routes/api.php` sem diff. Mutation check: tirar o 409 de já membro, a janela de 24 h e a releitura de `deleted` sob lock; cada uma derruba pelo menos um teste.
- **TASK-569**:
  - `backend/resources/views/email/invitation.blade.php` ganha o parágrafo do plan §7 (ao entrar, o convite aparece em **Meus Grupos** e a pessoa só entra em "{{ $groupName }}" se aceitar); o botão, o link de ativação e o rodapé continuam; `UserInvitedMail` e o assunto sem diff.
  - Arquivo novo `InvitationMailPendingTextTest`: a view renderizada contém "Meus Grupos" e o nome do grupo, e contém o botão e o link de ativação.
  - `EmailBrandingTest` e `GroupMemberInvitationMailTest` verdes **sem edição**; `pint --test` limpo.
- **TASK-570**:
  - `app/Http/Controllers/MyInvitationController.php` com `index` e a rota `GET /me/invitations` em `routes/api.php`, dentro do grupo `jwt.auth`; devolve `GroupInvitation::open()` do usuário autenticado, só de grupo não excluído, mais recentes primeiro, com **exatamente** as chaves `{id, group: {id, name}, invited_by: {id, name}|null, expires_at}`.
  - Arquivo novo `MyInvitationControllerTest` (esta task cria o arquivo e o caso de listagem): lista só os convites do próprio usuário (o de outra pessoa não aparece); vencido, aceito, recusado e cancelado ficam de fora (**CA-8**); convite de grupo excluído fica de fora; `invited_by` nulo vira `null`; as chaves de cada item e do grupo são exatamente as combinadas, sem `email`, `members`, despesas nem saldos (**CA-7**); sem token, 401; ordem do mais recente para o mais antigo.
  - `php artisan route:list --path=api/me/invitations` mostra a rota com `jwt.auth`; `RoutesPointToExistingControllerMethodsTest` verde sem edição; `pint --test` limpo. Mutation check: trocar `user_id` do usuário por qualquer e trocar `open()` por todos os convites; cada uma derruba teste.
- **TASK-571**:
  - `GroupInvitationService::accept(int $invitationId, User $user): Group` e `MyInvitationController@accept` com a rota `POST /me/invitations/{invitationId}/accept`, conforme o plan §3: id só com dígitos (`ctype_digit`); transação que trava o **grupo** e depois o **convite**, relendo sob o lock grupo existente e não `deleted`, convite do usuário, `pending`, não vencido e usuário ainda não membro; `attach`; convite `accepted` com `responded_at`; depois do commit, `Notifier::groupInvitationAccepted` para `invited_by` só se ele não for nulo, não for o próprio aceitante e **ainda for membro**. 200 `{message: 'Você entrou no grupo.', group: {id, name}}`.
  - Casos novos em `MyInvitationControllerTest`: **CA-4** (o convidado aceita: vira membro, convite `accepted`, o grupo aparece em `GET /groups` e o convite some de `GET /me/invitations`; quem convidou recebe `group_invitation_accepted` com `groupId`); o **mesmo 404 e o mesmo corpo**, sem nenhuma mudança no banco, para convite de outra pessoa, vencido, já aceito, recusado ou cancelado, de grupo excluído (F1), id inexistente e id não numérico (`5abc`); F3 (já é membro: 404, sem segundo vínculo); F2/**CA-11** (segundo aceite em sequência dá 404 e fica um só vínculo); quem convidou já saiu do grupo: aceita sem notificar ninguém; falha do `Notifier` não derruba o 200.
  - Mutation check (plan §12): tirar o `user_id` do convite, o filtro de validade, a releitura de `deleted` sob o lock, o "ainda é membro" da notificação e o "já é membro" do F3; cada uma derruba pelo menos um teste.
  - `php artisan test --filter MyInvitationControllerTest` verde; `RoutesPointToExistingControllerMethodsTest` verde; `pint --test` limpo.
- **TASK-572**:
  - `MyInvitationController@decline` e a rota `POST /me/invitations/{invitationId}/decline`: um único `UPDATE` condicional (`id`, `user_id` do usuário, `status = pending`, `expires_at > now()`) para `declined` com `responded_at`; zero linhas afetadas = 404; id só com dígitos; 200 `{message: 'Convite recusado.'}`; não grava notificação.
  - Casos novos em `MyInvitationControllerTest`: **CA-5** (recusar: não vira membro, convite `declined`, sai de `GET /me/invitations`, **nenhuma** linha nova em `ex_notifications`); o mesmo 404 para convite de outra pessoa, repetido, aceito, cancelado, vencido, inexistente e id não numérico; recusar convite de grupo excluído responde 200 (o plan §3 não consulta o grupo: o caso registra a escolha); depois de recusar, o mesmo par pode ser convidado de novo (201 em `POST /groups/{id}/members`).
  - Mutation check: tirar o `user_id` e o `status = pending` do `UPDATE`; cada uma derruba pelo menos um teste. `php artisan test --filter MyInvitationControllerTest` verde; `pint --test` limpo.
- **TASK-573**:
  - `app/Http/Controllers/GroupInvitationController.php` com `index` e a rota `GET /groups/{groupId}/invitations` dentro do `jwt.auth`; `authorizeMembership` privado, como nos outros controllers; devolve `GroupInvitation::open()` do grupo em ordem de `id`, mapeado para **exatamente** `{id, email, invited_by: {id, name}|null, expires_at}`.
  - Arquivo novo `GroupInvitationControllerTest` (esta task cria o arquivo e os casos de listagem): o membro vê os pendentes do grupo; vencido, aceito, recusado e cancelado ficam de fora (**CA-8**); convite de **outro** grupo não aparece; grupo inexistente, grupo excluído, não membro, **convidado ainda pendente** e ex-membro recebem o **mesmo 404 com o mesmo corpo**; sem token, 401; chaves exatas.
  - Mutation check: tirar `authorizeMembership` e trocar `open()` por todos os convites; cada uma derruba pelo menos um teste. `php artisan test --filter GroupInvitationControllerTest` verde; `RoutesPointToExistingControllerMethodsTest` verde; `pint --test` limpo.
- **TASK-574**:
  - `GroupInvitationController@destroy` e a rota `DELETE /groups/{groupId}/invitations/{invitationId}`, na ordem do plan §4: ids só com dígitos; grupo e membership (404); convite **deste grupo** e `open()` (404); permissão (`invited_by` é quem chama **ou** `created_by` do grupo é quem chama; senão 403); `UPDATE` condicional em `status = pending` para `cancelled` com `responded_at` (zero linhas = 404). 200 `{message: 'Convite cancelado.'}`. **Nenhuma linha de `ex_group_invitations` é apagada** (a contagem antes e depois é a mesma).
  - Casos novos em `GroupInvitationControllerTest` (**CA-6**): quem convidou cancela (200); o criador cancela o convite de outro membro; membro comum que não convidou recebe 403 e nada muda; não membro e grupo excluído recebem 404; convite com o id certo mas de **outro** grupo: 404; respondido, vencido e já cancelado: 404; grupo sem `created_by` só deixa o autor cancelar; convite com `invited_by` nulo só o criador cancela; id não numérico: 404; depois de cancelar, o convidado leva 404 ao aceitar e pode ser convidado de novo (201).
  - Mutation check: tirar a permissão (`invited_by`/`created_by`), o `status = pending` do `UPDATE` e o `group_id` da busca; cada uma derruba pelo menos um teste. `php artisan test --filter GroupInvitationControllerTest` verde; `pint --test` limpo.
- **TASK-575**:
  - Arquivo novo `backend/tests/Feature/PendingInviteeSecurityTest.php`, no molde do `FormerMemberSecurityReviewTest`: um convidado com convite `pending` leva **404 em toda rota de leitura e escrita do grupo**, em lista explícita de rotas conferida com `app('router')->getRoutes()->match(...)` (lança se a rota não existe) e com **controle** (para um membro as mesmas rotas e ids não dão 404); `GET /groups` e `GET /me/overview` vêm sem o grupo; `GET /me/invitations` traz só as chaves combinadas (**CA-7**).
  - O convite de **outra** pessoa: accept e decline dão 404 e o cancelamento por membro que não convidou dá 403 (**RK1**), sem alterar o banco; depois de aceitar o acesso passa a existir (controle positivo), e depois de recusar ou de cancelar segue 404.
  - Nenhum teste existente editado e `git diff --stat -- backend/app` vazio. Se a matriz achar uma brecha, a task **para** e o achado volta ao usuário (vira correção em task nova, não conserto de passagem). `php artisan test --filter PendingInviteeSecurityTest` verde; `pint --test` limpo.
- **TASK-576**:
  - O agente `security-reviewer` roda sobre `backend/routes/api.php`, `GroupInvitationController`, `MyInvitationController`, `GroupMemberController::store` e `GroupInvitationService`; o relatório inteiro entra no `implementation.md`.
  - Cada achado tem desfecho registrado e decidido com o usuário: corrigido com teste novo (na própria task, se for pequeno) ou item novo em `docs/backlog/`. Achado que muda o contrato ou a regra volta ao usuário antes de qualquer mudança.
  - As cinco rotas novas aparecem em `php artisan route:list --json` com o middleware `jwt.auth` (conferido com comando e resultado no log); `php artisan test --filter "Invitation|GroupMember|Notifier"` verde depois dos tratamentos; `pint --test` limpo.
- **TASK-577**:
  - `frontend/src/components/notificationText.ts`: `group_invitation` → `{actorName} convidou você para o grupo "{groupName}"` e `group_invitation_accepted` → `{actorName} aceitou o convite para o grupo "{groupName}"`; `group_member_added` e os demais casos intactos.
  - `frontend/src/components/NotificationsMenu.tsx`: o clique num item `group_invitation` marca como lido e navega para `/meus-grupos`; `group_invitation_accepted` (tem `groupId`) segue para `/groups/{id}/summary`; `group_member_removed` (sem grupo) segue só fechando o menu, como hoje (**CA-16**).
  - Casos novos em `notificationText.test.ts` e `NotificationsMenu.test.tsx`, com os existentes **sem edição**; `cd frontend && npx tsc --noEmit` limpo; `npx vitest run src/components/notificationText.test.ts src/components/NotificationsMenu.test.tsx` verde.
- **TASK-578**:
  - `frontend/src/types/group.ts` ganha `ReceivedGroupInvitation`; `frontend/src/components/ReceivedInvitations.tsx` conforme o plan §10, montado em `Dashboard.tsx` acima da linha de busca (único diff no `Dashboard.tsx`: o import e o `<ReceivedInvitations />`).
  - Arquivo novo `ReceivedInvitations.test.tsx` (**CA-14**): mostra cada convite com quem convidou (ou "Alguém" quando `invited_by` é nulo) e o grupo; **Aceitar** faz o `POST` em `/api/me/invitations/{id}/accept` e navega para `/groups/{groupId}/summary`; **Recusar** faz o `POST` em `.../decline` e tira o convite da lista; os botões do convite ficam desabilitados enquanto a chamada dele está em curso; um 404 mostra "Este convite não está mais disponível." e tira o convite da lista; `GET` com erro, resposta que não é array e lista vazia **não renderizam nada nem lançam**.
  - `Dashboard.test.tsx`: `mockGroupsAndMe` ganha o ramo de `/api/me/invitations` **antes** do de `/api/me` (que também casaria essa URL) e um teste novo da seção; nenhuma asserção existente muda e todos os testes do arquivo seguem verdes.
  - `cd frontend && npx tsc --noEmit` limpo; `npx vitest run src/components/ReceivedInvitations.test.tsx src/pages/Dashboard.test.tsx` verde.
- **TASK-579**:
  - `frontend/src/types/group.ts` ganha `PendingGroupInvitation`; `GroupMembersForm.tsx` busca `GET /api/groups/{id}/invitations` **separado** da carga principal e tolerante (erro ou resposta que não é array = sem lista, sem derrubar a tela) e mostra o cartão "Convites pendentes" só quando há ao menos um, com e-mail, "convidado por {nome}" e a validade; **Cancelar** aparece só para `invited_by.id === currentUserId` ou para o criador (`isCreator`); cancelar faz o `DELETE` em `/api/groups/{id}/invitations/{invitationId}` e recarrega a lista; um 404 só recarrega; outro erro mostra um `Alert`.
  - Arquivo novo `GroupMembersForm.invitations.test.tsx`, no precedente do `.exit.test.tsx` (**CA-15**, metade da lista): lista os pendentes; **Cancelar** visível para quem convidou e para o criador e ausente para o membro comum que não convidou; o `DELETE` sai com a URL certa e a lista é recarregada; erro do `GET` não derruba a tela; sem pendentes não há cartão.
  - `GroupMembersForm.test.tsx` e `GroupMembersForm.exit.test.tsx` seguem verdes **sem edição**; `npx tsc --noEmit` limpo; `npx vitest run src/pages/GroupMembersForm` verde.
- **TASK-580**:
  - `GroupMembersForm.tsx`: o cartão "Adicionar novo membro" vira "Convidar novo membro" e o botão "Adicionar" vira "Convidar" (o campo e o rótulo "E-mail do membro" ficam); depois do `POST` mostra um `Alert` de sucesso com o e-mail ("A pessoa só entra no grupo depois de aceitar."), limpa o campo e recarrega **só** os convites pendentes, não `/members`; a falha mostra "Erro ao enviar o convite.".
  - `GroupMembersForm.test.tsx`: **só** o teste de adicionar muda (o botão é buscado por "Convidar", e passa a afirmar a mensagem de convite enviado, a releitura dos pendentes e que `/members` **não** é relido); os demais testes do arquivo e do `.exit.test.tsx` seguem sem edição (**CA-15**, metade do envio).
  - `npx tsc --noEmit` limpo; `npx vitest run src/pages/GroupMembersForm` verde.
- **TASK-581**:
  - `docs/sdd/01-specify.md` §3.2 item 2 reescrito (hoje fala em `Password::getRepository()`, que o código não usa; passa a descrever o `User` criado quando o e-mail é novo, o token de 2 dias, o `UserInvitedMail` e o **convite pendente**) e a linha de `GET/POST /groups/{groupId}/members` em §3.3 atualizada (o `POST` cria o convite, o aceite é do convidado e as cinco rotas novas), com o cabeçalho de versão e data do documento atualizado se houver (**CA-18**).
  - `git grep -n -i "adicionar\|adiciona" -- docs/sdd` e a leitura de cada ocorrência que fale de adição direta de membro: nenhuma fica desatualizada, ou cada uma é corrigida na task; os documentos de `docs/feature/concluidas` ficam como estão (snapshots).
  - CRLF preservado (conferido por bytes, não por `grep -c`); `git diff --stat -- docs/sdd/00-constitution.md` **vazio** (a Constitution é só sinalizada ao usuário, nunca editada).

## Cobertura dos critérios de aceite do specify

| CA | Task(s) |
|---|---|
| CA-1, CA-2, CA-3 | TASK-568 |
| CA-4 | TASK-571 |
| CA-5 | TASK-572 |
| CA-6 | TASK-574 |
| CA-7 | TASK-570, TASK-573, TASK-575 |
| CA-8 | TASK-566 (escopo `open()`), TASK-570, TASK-571, TASK-573, TASK-574 |
| CA-9 | TASK-567, TASK-568 |
| CA-10 | TASK-568, TASK-569 |
| CA-11 | TASK-571 (aceite repetido em sequência; a concorrência real é garantida pelo lock, plan §3) |
| CA-12 | TASK-576 e o checklist final (`pint`, suíte inteira, `pr-readiness-checker`) |
| CA-13 | TASK-568 (três testes, ver o ajuste 1 do plan) |
| CA-14 | TASK-578 |
| CA-15 | TASK-579, TASK-580 |
| CA-16 | TASK-577 |
| CA-17 | TASK-577 a TASK-580 e o checklist final (`tsc`, Vitest dos arquivos tocados e suíte inteira com `--maxWorkers=1`) |
| CA-18 | TASK-581 |
