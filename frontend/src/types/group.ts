/**
 * Tipos do recurso Grupo, ancorados no que **cada endpoint devolve** — não na
 * união do que cada página lê.
 *
 * Antes desta extração, `type Group` era redeclarado em 8 arquivos em 4 formas
 * diferentes; olhando de perto, eram projeções parciais de apenas **dois**
 * payloads (`GET /api/groups` e `GET /api/groups/{id}`). Tipar pelo endpoint
 * mantém a informação de qual rota traz o quê, que um tipo único com tudo
 * opcional apagaria.
 *
 * Item de backlog 003 · docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/plan.md §4
 */

/** `creator:id,email`, eager-loaded pelos dois endpoints de grupo. */
export type GroupCreator = {
  id: number;
  email: string;
};

/**
 * Quem paga por um membro num grupo (feature 20261109-responsavel-do-devedor): só `id` e `name`,
 * nunca e-mail, Pix ou contato.
 */
export type MemberResponsible = {
  id: number;
  name: string;
};

/**
 * Membro como `GET /api/groups/{id}/members` devolve (`GroupMemberController::index()`):
 * models `User` inteiros — `password`, `remember_token`, `google_id` e
 * `photo_path` ficam de fora por `$hidden`, e `avatar_url` sai resolvido pelo
 * accessor (foto cadastrada > foto do Google > null).
 */
export type GroupMember = {
  id: number;
  name: string;
  email: string;
  avatar_url: string | null;
  /**
   * Quem pode pagar por este membro neste grupo (lista vazia quando ninguém). Opcional no tipo para as
   * fixtures e respostas anteriores à feature, que não o trazem; a API sempre o devolve.
   */
  responsibles?: MemberResponsible[];
};

/**
 * Membro como vem embutido em `GET /api/groups` — lá o eager load é `members:id,name,email`, sem
 * `avatar_url` e sem `responsibles` (que só `GET /api/groups/{id}/members` devolve).
 */
export type GroupMemberBasic = Omit<GroupMember, 'avatar_url' | 'responsibles'>;

/**
 * Colunas de `ex_groups`. `description`, `closing_day` e `created_by` são
 * nullable na tabela — um grupo criado sem descrição devolve `null`, não `''`.
 */
export type Group = {
  id: number;
  name: string;
  description: string | null;
  create_date: string;
  created_by: number | null;
  closing_day: number | null;
  deleted: boolean;
};

/** `GET /api/groups/{id}` — `GroupController::show()`: o model + `creator`. */
export type GroupDetail = Group & {
  creator: GroupCreator | null;
};

/**
 * `GET /api/groups` — `GroupController::index()`: o mesmo model, mais os
 * membros e dois agregados (`withMax('expenses', 'date_payment')` e
 * `withExists('cycleSnapshots')`).
 */
export type GroupListItem = GroupDetail & {
  members: GroupMemberBasic[];
  expenses_max_date_payment: string | null;
  cycle_snapshots_exists: boolean;
};

/** Só id + nome: o seletor de grupo do shell não precisa do resto do payload. */
export type GroupOption = Pick<Group, 'id' | 'name'>;

/**
 * Convite para entrar num grupo, como `GET /api/me/invitations` devolve
 * (`MyInvitationController::index()`, feature 20261103-convite-pendente-no-grupo): só o nome
 * do grupo e de quem convidou, porque quem recebe ainda não é membro. `invited_by` é nulo
 * quando a conta de quem convidou deixou de existir.
 */
export type ReceivedGroupInvitation = {
  id: number;
  group: { id: number; name: string };
  invited_by: { id: number; name: string } | null;
  expires_at: string;
};

/**
 * Convite pendente de um grupo, como `GET /api/groups/{id}/invitations` devolve
 * (`GroupInvitationController::index()`, feature 20261103-convite-pendente-no-grupo): o e-mail que
 * um membro digitou, quem convidou e a validade. Só membros veem esta lista. `invited_by` é nulo
 * quando a conta de quem convidou deixou de existir.
 */
export type PendingGroupInvitation = {
  id: number;
  email: string;
  invited_by: { id: number; name: string } | null;
  expires_at: string;
};

/**
 * Uma despesa que impede a saída ou a remoção de um membro: item de `blocking` na
 * resposta 422 de `DELETE /api/groups/{id}/members/{userId}` (`MemberExitBlockers`).
 * `reason` é `creditor_open_quotas` (credor de parcela em aberto de outra pessoa) ou
 * `active_fixed` (despesa fixa ainda ativa).
 */
export type MemberExitBlocker = {
  expense_id: number;
  description: string;
  reason: string;
};
