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
 * Item de backlog 003 · docs/feature/20260912-expense-view-tipo-e-pagadores/plan.md §4
 */

/** `creator:id,email`, eager-loaded pelos dois endpoints de grupo. */
export type GroupCreator = {
  id: number;
  email: string;
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
};

/** Membro como vem embutido em `GET /api/groups` — lá o eager load é `members:id,name,email`. */
export type GroupMemberBasic = Omit<GroupMember, 'avatar_url'>;

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
