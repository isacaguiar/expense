# Plan — Convite Pendente com Aceite ou Recusa para Entrar no Grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261103

> **O que este plano ajusta ou acrescenta ao Specify aprovado** (para o aprovador ver de uma vez; nada aqui muda uma decisão D1–D9):
>
> 1. **Um terceiro teste existente muda** (Specify §2.9 e CA-13 listam dois). `NotifierTriggersTest::test_group_member_added_does_not_notify_when_already_a_member` (`:372`) continua passando, mas fica **vazio de significado**: afirma zero linhas de um tipo que deixa de ser emitido. Troco só o tipo para `group_invitation`, para ele continuar guardando "409 não notifica". O CA-13 passa a valer para três testes.
> 2. **`Notifier::guard` passa a devolver `bool`** (os chamadores atuais ignoram o retorno), para o `notified_at` só ser gravado quando a notificação foi de fato escrita (F4, D5).
> 3. **Falha no e-mail de convite deixa de ser 500.** Hoje o `Mail::send` do `store()` não tem `try`: se falhar, a exceção sobe depois de o `User` já estar criado. Pelo R15 vira log e a resposta segue 201. A recuperação já existe: o login diz ao e-mail não verificado para usar "Esqueci minha senha" (`AuthController.php:36-41`).
> 4. **Reconvidar reaproveita a linha do par (grupo, pessoa)** (R3), então só o último estado do convite fica guardado; não há histórico de convites antigos.
> 5. **Cancelar é `status = cancelled`, não `DELETE` da linha.** O verbo HTTP é `DELETE`, mas a Constitution §6.0 reserva a exclusão física de registro de negócio ao gate humano; o efeito é um `UPDATE`.
> 6. **Constitution §4.1:** a D8-A (aprovada) muda o sentido do 201 de uma rota existente em produção, com shape aditivo. Fica sinalizado aqui e no PR; o plano não edita a Constitution.
> 7. **O botão "Adicionar" de Participantes vira "Convidar"** (e o título do cartão também), porque "Adicionar" deixa de ser verdade. `GroupMembersForm.test.tsx:66` busca o botão por esse nome, então muda uma linha daquele teste (o Specify §2.9 dizia que o teste só afirmava o `POST`).

---

## 1. Modelo de dados (Specify 2.2, 2.7, R1, R3, R11, R12, D1, F7)

- **Migration nova**, `backend/database/migrations/2026_11_03_000000_create_ex_group_invitations_table.php`, só `Schema::create` (aditiva, Constitution §4.2). Tabela `ex_group_invitations`:

  | Coluna | Tipo | Observação |
  |---|---|---|
  | `id` | `id()` | |
  | `group_id` | `unsignedBigInteger` | FK `ex_groups`, `onDelete('cascade')` (F7) |
  | `user_id` | `unsignedBigInteger` | o **convidado**; FK `ex_users`, `onDelete('cascade')` (F7) |
  | `invited_by` | `unsignedBigInteger` nullable | quem convidou; FK `ex_users`, `nullOnDelete()` |
  | `status` | `string(16)`, default `pending` | `pending`, `accepted`, `declined`, `cancelled` |
  | `expires_at` | `dateTime` | validade (D4); ver a nota abaixo |
  | `notified_at` | `dateTime` nullable | último aviso in-app ao convidado (D5) |
  | `responded_at` | `dateTime` nullable | aceite, recusa ou cancelamento |
  | `created_at`, `updated_at` | `timestamps()` | |

  Índices: `unique(['group_id','user_id'])` (R3, o par é único) e `index(['user_id','status'])` (a lista do convidado).
- **`dateTime`, não `timestamp`, em `expires_at`:** em MySQL com `explicit_defaults_for_timestamp` desligado, a primeira coluna `timestamp NOT NULL` ganha `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` e a validade seria sobrescrita a cada `UPDATE`. A versão do MySQL de produção (cPanel) não está nos docs; `dateTime` não depende dela.
- **`status` como `string`, não `enum`:** um estado novo no futuro seria alterar o tipo de coluna existente, que a Constitution §4.2 trata como destrutivo.
- **Model novo** `app/Models/GroupInvitation.php` (`$table = 'ex_group_invitations'`): constantes dos quatro status, `VALIDITY_DAYS = 7` (D4) e `RENOTIFY_AFTER_HOURS = 24` (D5); casts de data; relações `group()`, `invitee()` (`user_id`) e `inviter()` (`invited_by`); e o escopo `open()` = `status = pending` **e** `expires_at > now()`. É o único lugar que sabe o que é "convite vivo", e é ele que implementa o R12 (a validade é lida na consulta, sem job). `Group` ganha `invitations()` (`hasMany`).
- **Por que fora do pivô** (D1-A): `authorizeMembership` e as consultas `whereHas('members')` (`GroupController.php:20-22`, `:63`) continuam enxergando só membros, sem filtro de status em lugar nenhum. É o que sustenta o R10 e o R11.
- **Fica como está:** `ex_groups_members`, `GroupController::store` (`:80`, o criador entra sem convite, R1) e `GroupController::index`/`show` (R11 vale por construção).

## 2. Criar o convite: `POST /groups/{groupId}/members` (Specify 2.1, 2.5, R2–R5, D2, D5, D8, F4–F6)

- **Controller** (`GroupMemberController::store`, `:32-93`): mantém a validação do e-mail, o `Group::findOrFail`, o `authorizeMembership` (`:41`) e o `abort_if($group->deleted, 404)` (`:46`), e passa a delegar para o service. O trecho de criar `User`, token e e-mail (`:48-72`), o `attach` (`:82`) e o `Notifier::groupMemberAdded` (`:86`) saem do controller.
- **Service novo** `app/Services/GroupInvitationService.php` (a regra tem transação, lock e três desfechos; é o caso que a convenção manda tirar do controller, como o `MemberExitBlockers`). `invite(Group $group, string $email, User $inviter): ?GroupInvitation`, em ordem:
  1. Busca o `User` pelo e-mail.
  2. Abre `DB::transaction` e **trava o grupo** (`Group::lockForUpdate()->find()`), relendo `deleted` (404 se sumiu), no mesmo padrão do `destroy` (`:127-131`). Travar o grupo serializa convites, aceites e saídas do mesmo grupo, e resolve a corrida do F6 sem depender de capturar violação de índice único.
  3. Sem `User`: cria como hoje (nome = parte local do e-mail, senha aleatória com hash), `isNewUser = true`.
  4. Se o `User` já é membro: devolve `null`, e o controller responde o mesmo **409** de hoje (F5 também cai aqui).
  5. Sobre a linha do par (`group_id`, `user_id`):
     - não existe: cria `pending`, `invited_by` = quem convidou, `expires_at = now() + 7 dias`;
     - existe `pending` e não expirado: **renova** só `expires_at` e mantém o `invited_by` do primeiro (R3, F6);
     - existe respondido, cancelado ou expirado: **reabre** (`pending`, novo `invited_by`, novo `expires_at`, `responded_at` e `notified_at` nulos). Isso cobre o D9-A e o R13 (quem recusou, saiu ou foi removido pode ser convidado de novo).
  6. Commit.
  7. **Depois do commit** (F4), cada passo isolado e best effort (R15): se o convite tem `notified_at` nulo ou com mais de 24 h (D5), chama `Notifier::groupInvitation` e, **só se devolveu `true`**, grava `notified_at` (vale também para o `User` recém-criado, como o aviso de hoje: ele vê a notificação quando entrar); se `isNewUser`, grava o token `invitation-token:<e-mail>` por 2 dias e envia o `UserInvitedMail` dentro de `try/catch` (`Log::warning` em falha).
- **Por que essa ordem resolve o "aviso silencioso" do F4:** a linha do convite é a fonte da verdade e aparece em Meus Grupos pelo `GET /me/invitations`, com ou sem notificação ou e-mail. Se o aviso falhar, o convite existe, está visível e o `notified_at` segue nulo, então um novo convite dentro de 24 h ainda tenta avisar.
- **Resposta (R4, D8-A):** 201 `{message: 'Convite enviado.', pending: true, invitation: {id, email, expires_at}}`, idêntica para e-mail com e sem conta e para convite novo ou renovado. O `email` devolvido é o que o próprio chamador digitou. 409 e 404 como hoje.
- **E-mail sem conta (D2-A, R5):** o `User` continua nascendo na hora, com o token de 2 dias, porque o `InvitationController::verify` e a `AcceptInvitePage` não mudam. Renovar um convite **não** reenvia o e-mail (reenvio manual está fora de escopo, Specify §6).
- **Corrida entre dois e-mails novos iguais:** o `ex_users.email` é `unique`; o segundo `INSERT` falharia. Como o lock do grupo já serializa convites do mesmo grupo, só dois grupos diferentes convidando o mesmo e-mail novo no mesmo instante chegariam lá. É a mesma janela que o código tem hoje; fica registrada, sem tratamento novo.

## 3. Aceitar e recusar (Specify R7, R8, F1–F3, CA-4, CA-5, CA-11)

- **Controller novo** `app/Http/Controllers/MyInvitationController.php` (`index`, `accept`, `decline`), sempre escopado ao usuário autenticado, como o `NotificationController`. Os ids da URL passam por `ctype_digit` antes de qualquer consulta (a lição do `isMember`: `where('id', '5abc')` casaria o id 5 no MySQL).
- **`accept`** chama `GroupInvitationService::accept(int $invitationId, User $user): Group`:
  1. Carrega o convite com `id` e `user_id = quem chama`; ausente = 404.
  2. `DB::transaction`: trava o **grupo** primeiro e depois o **convite** (`lockForUpdate`), sempre nessa ordem (a mesma do `invite`, sem deadlock); releia tudo sob o lock: grupo existe e não está `deleted`, convite é do usuário, `status = pending`, `expires_at > now()`, e ele **ainda não é membro**. Qualquer falha é o **mesmo `abort(404)`** (R7, F1, F3).
  3. `attach`, `status = accepted`, `responded_at = now()`.
  4. Depois do commit: `Notifier::groupInvitationAccepted` para `invited_by`, só se ele não for nulo, não for o próprio aceitante e **ainda for membro** (quem convidou pode ter saído; a notificação leva `groupId`, e para um ex-membro seria um 404 no clique, o mesmo problema do achado 2.6).
  5. Resposta 200 `{message: 'Você entrou no grupo.', group: {id, name}}`.
- **`decline`**: um único `UPDATE` condicional (`id`, `user_id = quem chama`, `status = pending`, `expires_at > now()`) para `declined`, `responded_at = now()`; 0 linhas afetadas = 404 (R8, repetir dá 404). Não notifica ninguém (D6-A). Não consulta o grupo: a linha é do próprio convidado e não revela nada.
- **Concorrência (F2, CA-11):** o aceite trava o convite; um `decline` ou `destroy` concorrente espera no `UPDATE` e, quando o aceite comita, o `WHERE status = pending` não casa mais e dá 404. A PK composta de `ex_groups_members` é a última barreira contra um `attach` duplo. O PHPUnit não reproduz duas requisições simultâneas; o teste cobre o aceite repetido em sequência (um só vínculo, o segundo é 404) e o lock fica garantido por revisão e pelo mutation check do relido sob lock.
- **Teto de 3 grupos:** o aceite não conta, porque o limite é de grupos **criados** (`GroupController.php:61-64`).

## 4. Convites do grupo: listar e cancelar (Specify R9, R11, D7, CA-6, CA-8)

- **Controller novo** `app/Http/Controllers/GroupInvitationController.php` (`index`, `destroy`), com `authorizeMembership` privado, igual aos outros controllers (extrair para um lugar comum seria refactor de passagem, fora de escopo). Grupo ausente ou `deleted` e não membro dão o mesmo `abort(404)`.
- **`index($groupId)`**: membros veem `GroupInvitation::open()` do grupo, em ordem de `id`, mapeado explicitamente para `{id, email, invited_by: {id, name}|null, expires_at}` (o formato fica fixo; não é um dump do model). Convite expirado some (CA-8).
- **`destroy($groupId, $invitationId)`**, nesta ordem: grupo e membership (404); convite **deste grupo** e `open()` (404, cobre respondido, cancelado e expirado); permissão (`invited_by` é quem chama **ou** `created_by` do grupo é quem chama; senão **403**); `UPDATE` condicional em `status = pending` para `cancelled` com `responded_at` (0 linhas = 404, é a corrida com o aceite). Resposta 200 `{message: 'Convite cancelado.'}`. Grupo antigo sem `created_by` só deixa quem convidou cancelar.
- Quem cancela um convite não é avisado nem avisa ninguém (nada na Specify pede).

## 5. Convites recebidos (Specify R10, CA-7)

- **`MyInvitationController::index`**: `GroupInvitation::open()` do `user_id` autenticado, só de grupo não excluído (`whereHas('group', deleted = 0)`), com `group:id,name` e `inviter:id,name`, mais recentes primeiro, mapeado para `{id, group: {id, name}, invited_by: {id, name}|null, expires_at}`.
- **Nada além disso sai** para quem ainda não é membro: sem e-mail de quem convidou nem de outros membros, sem despesas, sem saldos. O mapeamento explícito (e um teste que compara as chaves) é o que segura o R10; o convidado nunca passa por `authorizeMembership`, e todas as rotas do grupo seguem dando 404 para ele.

## 6. Notificações (Specify 2.6, R6, D6, R15)

- **`app/Support/Notifier.php`:**
  - `groupInvitation(Group $group, int $inviteeId, ?string $actorName): bool` grava `group_invitation` com `group_id` **nulo** e `data = {actorName, groupName}`, **sem** `groupId`. É o mesmo desenho do `groupMemberRemoved` (`:224-232`), pelo mesmo motivo: o sino só navega quando há grupo, e o convidado ainda não pode abri-lo.
  - `groupInvitationAccepted(Group $group, int $inviterId, ?string $accepterName): void` grava `group_invitation_accepted` com `group_id` e `data = {actorName, groupId, groupName}` (quem convidou é membro e pode abrir o grupo).
  - `guard()` passa a devolver `bool` (ver o ajuste 2); `fanOut` não muda.
  - **`groupMemberAdded` é removido** (`:202-215`): perde o único chamador. As linhas `group_member_added` já gravadas ficam intactas, e o frontend continua com o texto delas.
- A recusa e o cancelamento não geram notificação (D6-A).

## 7. E-mail de convite (Specify R5)

- **`resources/views/email/invitation.blade.php`**: depois de "clique no botão abaixo e ative sua conta", entra um parágrafo dizendo que, ao entrar, o convite aparece em **Meus Grupos** e que a pessoa só entra em "{{ $groupName }}" se aceitar. O assunto e a assinatura de `UserInvitedMail::build()` (`:31-43`) ficam: continuam verdadeiros ("convidado"). A view não ganha variável nova, então o `EmailBrandingTest` segue valendo sem edição.

## 8. Rotas, contrato e Constitution (Specify 3.3)

- **`backend/routes/api.php`**, dentro do grupo `jwt.auth` (Constitution §6.5), ao lado do bloco `groups/{groupId}/members` (`:54-58`), rotas manuais (sem `apiResource`, para não repetir o erro do bugfix 132; o `RoutesPointToExistingControllerMethodsTest` já barra método ausente):

  | Rota | Controller@método |
  |---|---|
  | `GET /me/invitations` | `MyInvitationController@index` |
  | `POST /me/invitations/{invitationId}/accept` | `MyInvitationController@accept` |
  | `POST /me/invitations/{invitationId}/decline` | `MyInvitationController@decline` |
  | `GET /groups/{groupId}/invitations` | `GroupInvitationController@index` |
  | `DELETE /groups/{groupId}/invitations/{invitationId}` | `GroupInvitationController@destroy` |

  Os nomes ficam os da Specify §3.3 (propostos lá, fechados aqui). Não há conflito com `/me`, `/me/overview` nem com `apiResource('groups')`. Sem `throttle`: o item 128 está fora de escopo.
- **Contrato (Constitution §4.1):** rotas novas são aditivas; `POST /groups/{id}/members` mantém rota, 201 e `message` e ganha `pending` e `invitation`, mas **o 201 deixa de significar "virou membro"**. O único consumidor é a web, atualizada no mesmo PR; o `app/` Flutter ainda não existe. Fica sinalizado (ajuste 6), sem editar a Constitution.
- **Constitution §4.2 e ADR-008:** a migration é só `create`. O deploy aplica migrations sozinho ao mergear em `main`, então o PR de promoção precisa dizer isso, e o gate humano vale antes de qualquer banco compartilhado.

## 9. Frontend: tipos, texto do sino e clique (Specify 2.6, 3.6)

- **`frontend/src/types/group.ts`**: `ReceivedGroupInvitation` (`{id, group: {id, name}, invited_by: {id, name} | null, expires_at}`) e `PendingGroupInvitation` (`{id, email, invited_by: {id, name} | null, expires_at}`), ancorados no que cada endpoint devolve, como o resto do arquivo.
- **`notificationText.ts`** (`:28-31`): `group_invitation` → "{ator} convidou você para o grupo "{grupo}"" e `group_invitation_accepted` → "{ator} aceitou o convite para o grupo "{grupo}"". O caso `group_member_added` **fica**, para as linhas antigas.
- **`NotificationsMenu.tsx`** (`handleItemClick`, `:88-101`): o clique em `group_invitation` navega para `/meus-grupos`; os demais seguem a regra de hoje (`data.groupId ?? group_id` → `/groups/{id}/summary`). `group_invitation_accepted` tem `groupId` e cai na regra existente.
- Sem evento de analytics novo.

## 10. Frontend: Meus Grupos (Specify 3.6, CA-14)

- **Componente novo** `frontend/src/components/ReceivedInvitations.tsx`, autocontido (busca, lista e ações), montado em `Dashboard.tsx` logo acima da linha de busca (`:231`). Mantém o `Dashboard`, que já tem ~350 linhas, quase intacto.
- Busca `GET /api/me/invitations` ao montar, **best effort**: erro ou resposta que não seja array não mostra nada e não quebra Meus Grupos; lista vazia também não renderiza nada.
- Cada convite mostra "{quem convidou ou "Alguém"} convidou você para o grupo "{grupo}"" e os botões **Aceitar** e **Recusar**, desabilitados enquanto a chamada daquele convite está em curso. **Aceitar** faz o `POST` e navega para `/groups/{id}/summary`. **Recusar** faz o `POST` e tira o convite da lista. Um 404 em qualquer dos dois mostra "Este convite não está mais disponível." e tira o convite da lista.
- **Por que o guard de array importa:** nos testes do `Dashboard`, o mock por URL usa `url.includes('/api/me')`, que também casa `/api/me/invitations` e devolveria o objeto do usuário. O componente tolerante mantém esses testes verdes; `mockGroupsAndMe` ganha mesmo assim um ramo explícito para a rota nova, antes do `/api/me`.

## 11. Frontend: Participantes (Specify 3.6, CA-15)

- **`GroupMembersForm.tsx`**:
  - Cartão "Adicionar novo membro" (`:219-251`) vira "Convidar novo membro" e o botão "Adicionar" vira "Convidar" (ajuste 7). O campo e o rótulo "E-mail do membro" ficam.
  - `handleAddMember` (`:86-105`): depois do `POST` mostra um `Alert` de sucesso ("Convite enviado para {e-mail}. A pessoa só entra no grupo depois de aceitar.") e **recarrega os convites pendentes, não a lista de membros** (nada mudou nela). A mensagem de erro passa a "Erro ao enviar o convite.".
  - Novo cartão "Convites pendentes": e-mail, "convidado por {nome}" e validade, com **Cancelar** só para quem pode (`invited_by.id === currentUserId` ou `isCreator`, que o arquivo já calcula). Cancelar faz o `DELETE` e recarrega a lista; erro mostra um `Alert`. Um 404 (já respondido) só recarrega.
  - A busca de convites é separada da carga principal e tolerante (erro ou não-array = lista vazia), para um problema nela não derrubar a tela de Participantes.

## 12. Testes (Specify 5.1, 5.2, CA-12, CA-13, CA-17)

- **Backend, existentes que mudam por design:** `GroupMemberControllerTest::test_member_can_add_member_to_group` (`:34`, passa a afirmar convite pendente e nenhum vínculo novo), `NotifierTriggersTest::test_group_member_added_notifies_the_new_member_only` (`:352`, vira o teste de `group_invitation`) e o terceiro, do ajuste 1 (`:372`). **Seguem sem edição:** `GroupMemberInvitationMailTest` e `GroupMemberInvitationTokenTest` (afirmam 201, e-mail e token, nunca vínculo), o 404 de não membro e de grupo excluído, `FormerMemberSecurityReviewTest:209` (`POST /members` de ex-membro, 404 antes de qualquer outra coisa), `NotificationModelTest` (usa `group_member_added` só como texto de tipo) e `EmailBrandingTest`.
- **Backend, novos** (no padrão `DatabaseTransactions` + `tokenFor`, sem factory nova: o convite é montado com `GroupInvitation::create` num helper de teste):
  - criação do convite: CA-1, CA-2, CA-3 (inclui a janela de 24 h com `Carbon::setTestNow`), CA-9, CA-10, F5, F6, R13 e o e-mail com falha (R15);
  - convites do grupo: lista, `expires_at`, CA-6 (quem convidou, criador, membro comum 403, não membro 404, respondido 404, grupo sem `created_by`) e CA-8;
  - convites recebidos: lista com as chaves exatas (CA-7), aceitar, recusar, F1, F2 (em sequência), F3 e CA-8;
  - matriz de autorização do convidado pendente, no modelo do `FormerMemberSecurityReviewTest`: 404 em toda rota do grupo, `GET /groups` sem o grupo, e aceitar, recusar ou cancelar o convite de **outra** pessoa dando 404 ou 403 conforme a regra (RK1, CA-7);
  - `Notifier`: `group_invitation` sem `group_id` e sem `groupId`, `group_invitation_accepted` com `groupId`, aceitante e quem convidou ausente ou ex-membro (não notifica) e a falha engolida e logada (CA-9).
- **Mutation check** (script que restaura os arquivos, como nas tasks da feature 086), cada uma tem que derrubar pelo menos um teste: `user_id` do convidado ausente no `accept` e no `decline`; permissão de cancelar (`invited_by`/`created_by`); filtro `expires_at` do `open()`; `status = pending` no `UPDATE` condicional; releitura sob lock (grupo `deleted`, já membro); `authorizeMembership` nas duas rotas do grupo; o 409 de já membro; a janela de 24 h; "quem convidou ainda é membro" na notificação de aceite.
- **Frontend:** `notificationText.test.ts` e `NotificationsMenu.test.tsx` ganham os casos novos (inclusive o clique em `group_invitation` indo para `/meus-grupos`); `ReceivedInvitations.test.tsx` novo; `Dashboard.test.tsx` ganha o ramo no `mockGroupsAndMe` e um teste da seção; `GroupMembersForm.test.tsx` muda no teste de adicionar (nome do botão, e agora afirma a mensagem de convite enviado e o recarregamento dos pendentes) e `GroupMembersForm.invitations.test.tsx` novo (lista, quem vê **Cancelar**, cancelar), no precedente do `GroupMembersForm.exit.test.tsx`.
- **Verificação:** `./vendor/bin/pint --test`; `php artisan test` (suíte inteira, com o banco de teste montado pela receita local); `php artisan route:list` conferindo as cinco rotas novas; `npx tsc --noEmit`; Vitest dos arquivos tocados e, no fim, a suíte inteira com `--maxWorkers=1`, conferindo que rodaram todos os arquivos (nesta máquina é a única execução válida).

## 13. Documentação (Specify CA-18)

- **`docs/sdd/01-specify.md`**: §3.2 item 2 é reescrito (hoje fala em `Password::getRepository()`, que o código já não usa desde o token de convite em cache; passa a descrever: cria o `User` quando o e-mail é novo, token de 2 dias, `UserInvitedMail` e **convite pendente**) e a linha de `GET/POST /groups/{groupId}/members` em §3.3 é atualizada (o `POST` cria o convite; as cinco rotas novas; a regra de aceite).
- A **Constitution não é editada**. O que ficar desatualizado nela é sinalizado ao usuário, e a §4.1 já está sinalizada no ajuste 6.
- Se o `docs/sdd/06-context-backend.md` ou outro documento citar a adição direta de membro, atualizo na mesma task; a conferência é um `git grep` nas docs vivas (fora de `docs/feature/concluidas`, que são snapshots).
- **Manual e site (TASK-582, acrescentada em 2026-11-05 a pedido do usuário; item 136 do backlog):** a varredura da TASK-581 achou, fora de `docs/sdd`, quatro textos para o usuário final que dizem que a pessoa convidada entra direto (`MANUAL.md`, `docs/manual/manual.html`, `site/public/manual.php`, `site/public/recursos.php`) e um PDF gerado do `manual.html`. Por serem conteúdo público, ficaram de fora da 581 e viraram a TASK-582: corrige os quatro, acrescenta Convites recebidos e Convites pendentes ao manual e regera o `MANUAL.pdf` com `docs/manual/gerar-pdf.ps1`. Sem imagem nova (as capturas de tela do manual seguem válidas) e sem deploy dentro da feature.

## 14. Ordem de execução

Há dependência técnica, então a ordem de `tasks.md` segue o que cada peça precisa da anterior; onde não há dependência, vai o que é mais arriscado primeiro (a entrada e a autorização).

1. **Migration e model** (§1): a base de tudo.
2. **`Notifier`** (§6): os dois métodos novos e o `guard` com `bool`; a remoção do `groupMemberAdded` só acontece junto com a troca do `store()`.
3. **`store()` vira convite** (§2, §7) e os testes que mudam por design (§12): é o ponto único de entrada (Specify 2.2); depende de 1 e 2.
4. **Aceitar, recusar e convites recebidos** (§3, §5): depende de 1 e 2; sem ele o convite criado em 3 não tem como ser respondido.
5. **Listar e cancelar convites do grupo** (§4): depende de 1; independe de 4, então pode vir antes ou depois dele.
6. **Rotas** (§8) entram junto de cada controller (cada rota nasce com o método e o teste), e não numa task à parte.
7. **Matriz de autorização do convidado pendente** (§12): teste transversal, só faz sentido depois de 3, 4 e 5.
8. **Frontend:** tipos, sino e clique (§9) independem do backend (só o contrato); depois Meus Grupos (§10) e Participantes (§11), que usam os tipos. Em `tasks.md` ficam depois do backend, porque a validação local do usuário exercita as duas pontas juntas.
9. **Documentação** (§13) por último, com o comportamento já confirmado.

**Gates e pontos de parada:** parada após cada task (pergunta se segue); `security-reviewer` nas rotas novas antes do PR (CA-12, RK1); checklist e `pr-readiness-checker` antes de integrar e de abrir o PR; **o usuário valida localmente antes de qualquer push ou PR**; o merge em `dev`, a promoção para `main` (que aplica a migration, ADR-008) e o deploy seguem gate humano.
