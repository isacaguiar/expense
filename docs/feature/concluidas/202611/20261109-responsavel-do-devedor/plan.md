# Plan — Responsável do devedor

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.2 · Criado em: 20261109

**v1.2 (2026-11-10):** o `validate` de `update` (§3) ganha `max:50` e a mensagem `Escolha no máximo 50 pessoas.` (BAIXA-3 da revisão de segurança; specify v1.2).

**v1.1 (2026-11-09):** acompanha o specify v1.1 (D3-B, nenhum ou vários responsáveis). Mudam os §1 a §4, §8 a §11 e o ajuste 8 do topo; os §5 a §7, o §12 e o §13 só no que cita a rota.

**Resumo.** *Objetivo:* um membro indica **nenhum, um ou vários** outros membros do grupo para pagar por ele; cada indicado envia o comprovante dos acertos do devedor, e o credor vê quem enviou. *Arquitetura:* tabela nova de vínculo por (grupo, devedor, responsável), uma coluna `confirmed_by` na confirmação existente e uma permissão a mais em `confirmSettlement`; o acerto continua do devedor, então netting, selo e snapshots não mudam. *Stack:* Laravel 10 e MySQL (backend), React 18, TypeScript e MUI (web). *Spec:* `specify.md` v1.1 desta pasta (D1 a D10 aprovadas, com a D3 trocada para B e a D9 e a D10 como recomendado).

> **O que este plano ajusta ou acrescenta ao Specify** (para o aprovador ver de uma vez; nada aqui muda uma decisão aprovada):
>
> 1. **Rótulos do mockup mostrado na conversa.** O botão real é "Pagar com Pix" (não "Pagar via Pix"), e os de comprovante seguem "Enviar comprovante" e "Reenviar comprovante" (`PayableSettlementList.tsx:154` e `:162`); o mockup tinha "Enviar comprovante por João", que **não** entra: o selo "Você paga por João" já diz de quem é o acerto. O botão "Remover responsável" do mockup **deixa de existir**: com o seletor múltiplo, desfazer é esvaziar o seletor e salvar.
> 2. **Sem coluna `created_by` no vínculo.** Pela D1-A quem cria é sempre o próprio devedor (`user_id`), então a coluna seria redundante. A D4 fica justificada pelas datas e pelo isolamento do pivô; ajustei o texto da D4 no specify.
> 3. **Controller novo e service novo** (`GroupMemberResponsibleController`, `MemberResponsibleService`): o `GroupMemberController` já tem 166 linhas e é compartilhado com a feature de convites; a regra mora no service (Constitution §1.3).
> 4. **Reuso de `Controller::authorizeGroupMembership`** da classe base, em vez de uma quarta cópia do helper de membro (já existe o item de backlog `tres-copias-do-helper-de-membro-do-grupo`; não o corrijo de passagem).
> 5. **`responsibles` é opcional no tipo `GroupMember`** (`responsibles?: MemberResponsible[]`) e `GroupMemberBasic` o omite: assim as fixtures de testes que já existem continuam compilando e `GET /groups`, que não o devolve, segue tipado certo (CA-13).
> 6. **Nenhum campo novo para dizer "por quem o usuário paga"**: o front deriva isso de `responsibles.some(responsible => responsible.id === currentUserId)` na lista de membros que `Payments` já carrega.
> 7. **D9 e D10 aprovadas como recomendado (A e A) em 2026-11-09; o que mudaria se fossem B:** se a D9 virar B (avisar o devedor), entra um `Notifier::settlementPaidOnYourBehalf` e uma linha em `notificationText`, na TASK-587. Se a D10 virar B (soft), a migration ganha `ended_at` e o índice único passa a considerar só vínculos ativos, o que **muda a TASK-583**. Como a D10 foi aprovada como A, a TASK-583 segue com a linha apagada e sem `ended_at`.
> 8. **A D3 virou B (nenhum ou vários), por pedido do usuário.** O contrato muda: **um** `PUT .../responsibles` com a lista inteira no lugar de `PUT` e `DELETE` (o `DELETE` deixa de existir e a lista vazia desfaz tudo); `responsible` vira `responsibles`; o índice único passa a ser `(grupo, devedor, responsável)`, com **nome explícito**, porque o nome que o Laravel geraria passa dos 64 caracteres do MySQL; o seletor do diálogo é múltiplo. Tasks tocadas: 583, 584, 585, 590, 591 e 592; nenhuma task nova.

## Restrições globais

Valem para todas as tasks.

- Tabela nova com prefixo `ex_`; migration **aditiva** (Constitution §1.4, §4.2); **gate humano** antes de rodar em banco compartilhado ou produção (o deploy aplica no merge em `main`, ADR-008).
- Índices e chaves com nome que caiba nos **64 caracteres** do MySQL: nome explícito quando o gerado pelo Laravel passa disso.
- Contrato da API só aditivo: nenhuma rota, campo ou código de status que já existe muda de sentido (§4.1).
- Rota autenticada dentro de `jwt.auth`, com checagem de membership: **404** para não membro, **403** para membro sem permissão (§6.5).
- Controller fino; regra de negócio no service (§1.3). Backend: `./vendor/bin/pint` limpo e PHPUnit para toda regra nova (§2.1, §2.2). Frontend: TypeScript estrito, sem `any` não justificado (§2.3).
- `from_user_id` da confirmação **é sempre o devedor**; `confirmed_by` é quem enviou, nulo quando é o próprio devedor.
- Nenhum rótulo novo usa "Responsável" sozinho; os rótulos de botão existentes não mudam (D8).
- **Nenhum teste que já existia é editado** (CA-13): todo teste novo entra em arquivo novo.
- Frontend: suíte inteira só com `--maxWorkers=1` nesta máquina; confira que rodaram todos os arquivos.

## Foco de revisão

Casos que o specify implica mas que ninguém lembraria de testar, do mais provável ao menos. Cada um tem o teste na task dona.

1. **Devedor e responsável enviam o mesmo acerto** (duas abas, ou um depois do outro): o último vence, `confirmed_by` acompanha o último remetente, e sobra **um** arquivo no disco. Teste em TASK-587.
2. **`from_user_id` adulterado**: o responsável de A manda o de B, e um id que não existe. O primeiro dá 403 sem arquivo e sem linha; o segundo, 422. Teste em TASK-589.
3. **Mudança da lista de responsáveis no meio do ciclo**: quem sai da lista recebe 403 na hora, quem entra passa, quem continua não é afetado, e as confirmações já feitas mantêm o `confirmed_by`. Testes em TASK-584 e TASK-587.
4. **O responsável é o credor do acerto**: o backend aceita; a interface não oferece botão. Testes em TASK-587 e TASK-592.
5. **Saída com acerto pendente**: o responsável sai ou é removido com o acerto do dependente aberto (o vínculo dele some; o dependente e os outros responsáveis continuam); o dependente sai (o vínculo some); e o `PUT` concorrente com a saída do escolhido (F2). Testes em TASK-585 e TASK-584.

---

## 1. Modelo de dados (Specify 2.3, 2.8, R1, R7, D2, D3, D4, F8)

- **Migration 1**, `backend/database/migrations/2026_11_09_000000_create_ex_group_member_responsibles_table.php`, só `Schema::create`. Tabela `ex_group_member_responsibles`:

  | Coluna | Tipo | Observação |
  |---|---|---|
  | `id` | `id()` | |
  | `group_id` | `unsignedBigInteger` | FK `ex_groups`, `onDelete('cascade')` |
  | `user_id` | `unsignedBigInteger` | o **devedor**; FK `ex_users`, `onDelete('cascade')` |
  | `responsible_user_id` | `unsignedBigInteger` | quem paga por ele; FK `ex_users`, `onDelete('cascade')` |
  | `timestamps()` | | criação e troca |
  | índice único | `['group_id', 'user_id', 'responsible_user_id']`, nome explícito `member_responsible_unique` | R1: nenhum, um ou vários responsáveis por devedor por grupo, sem repetir a mesma pessoa (D3-B). O nome gerado pelo Laravel teria 72 caracteres |
  | índice | `['group_id', 'responsible_user_id']`, nome explícito `member_responsible_by_responsible_index` | "por quem este usuário paga", usado na limpeza de `destroy` |

- **Migration 2**, `backend/database/migrations/2026_11_09_000001_add_confirmed_by_to_ex_settlement_confirmations_table.php`, só `Schema::table` que **acrescenta** `confirmed_by` (`unsignedBigInteger`, nula, depois de `to_user_id`, FK `ex_users` com `nullOnDelete()`); `down()` desfaz só o que ela criou. Nenhuma linha existente é tocada: `null` já significa "o próprio devedor".
- **Models:** `App\Models\GroupMemberResponsible` (`$table = 'ex_group_member_responsibles'`, `$fillable = ['group_id', 'user_id', 'responsible_user_id']`, `group()`, `debtor()` e `responsible()` como `BelongsTo`); `Group::memberResponsibles(): HasMany`; em `SettlementConfirmation`, `confirmed_by` no `$fillable` e `confirmedByUser(): BelongsTo` (FK `confirmed_by`).
- Sem soft delete: desfazer apaga a linha (D10-A, ver Specify §3.4).

## 2. Regras do vínculo: `MemberResponsibleService` (Specify R1, R3, R4, R12, F1, F2, D10)

`backend/app/Services/MemberResponsibleService.php`, sem HTTP. Uma exceção própria, `App\Exceptions\MemberResponsibleRefused` (`extends \RuntimeException`), no padrão de `PurchaseUndoRefused`, para o controller virar 422.

- `replace(Group $group, int $debtorId, array $responsibleIds): array{added: list<int>, removed: list<int>}` — numa `DB::transaction` com `Group::lockForUpdate()->find()` (o mesmo lock de `destroy`, F1 e F2): 404 (`abort`) se o grupo sumiu ou foi excluído; confere, já com o lock, que o devedor e **todos** os `$responsibleIds` são membros, que nenhum é o devedor e que não há repetidos, senão lança `MemberResponsibleRefused` **antes de alterar qualquer linha**; compara com as linhas atuais, apaga as de quem saiu da lista e insere as de quem entrou (quem ficou **não** é recriado, R4). A lista vazia apaga todas as linhas do devedor. Devolve os ids que entraram e os que saíram.
- `clearFor(int $groupId, int $userId): int` — apaga as linhas em que `$userId` é o devedor **ou** o responsável; devolve quantas.
- `canPayFor(int $groupId, int $responsibleId, int $debtorId): bool` — existe a linha exata (grupo, devedor, responsável).
- `responsiblesByDebtor(int $groupId): Collection` — chaveada por `user_id` do devedor; cada valor é uma lista de `['id' => ..., 'name' => ...]` ordenada por nome (só esses dois campos, R13); `with('responsible:id,name')`, uma consulta.

## 3. Definir a lista de responsáveis: `GroupMemberResponsibleController` (Specify 3.3, R2, R3, R4, R11, CA-1, CA-2, CA-10)

`backend/app/Http/Controllers/GroupMemberResponsibleController.php`, dentro do grupo de rotas `groups/{groupId}/members` que já existe em `routes/api.php`:

- **Uma** rota: `PUT /{userId}/responsibles` → `update(Request $request, $groupId, $userId)`. Não há `DELETE`: a lista vazia desfaz tudo (um `DELETE` nessa URL dá 405).
- **Ordem das checagens em `update`:** `Group::find` e 404 se nulo ou excluído → `authorizeGroupMembership` (base; 404) → `userId` precisa ser dígitos e igual a `auth()->id()`, senão **403** (R2, vale também para o criador) → `validate(['responsible_user_ids' => 'present|array|max:50', 'responsible_user_ids.*' => 'integer|distinct'])` (422; `present`, e não `required`, para aceitar a lista vazia) → `MemberResponsibleService::replace` (`MemberResponsibleRefused` vira 422 com `message`) → um `Notifier::paymentResponsibleAssigned` para **cada** id em `added` (R11) → 200 `{message, responsibles: [{id, name}]}` com a lista já gravada.
- Mensagens 422: "Escolha só membros deste grupo.", "Você não pode ser quem paga por você mesmo." e "A lista não pode repetir ninguém."

## 4. Membros: `index` traz `responsibles`, `destroy` limpa os vínculos (Specify R12, R13, CA-3, CA-9, 2.8, 2.11)

- `GroupMemberController::index`: depois de `$group->members`, usa `responsiblesByDebtor($group->id)` e `setAttribute('responsibles', $map->get($member->id, []))` em cada membro (lista de arrays `{id, name}`, vazia quando não há; nunca o model `User` inteiro, para não vazar e-mail nem contato). A chave nova é aditiva: os testes de `index` usam `assertJsonStructure` e `assertJsonCount`.
- `GroupMemberController::destroy`: **uma** chamada, `clearFor($locked->id, (int) $userId)`, logo depois do `detach` e **dentro** da transação existente; o ramo que devolve 422 de bloqueio sai antes e não limpa nada. Os testes de saída e remoção que já existem seguem sem edição.

## 5. `confirmSettlement` aceita `from_user_id` (Specify 2.1, R5 a R9, R14, F3, F4, CA-4 a CA-7)

Em `ExpenseController::confirmSettlement` (`:1736`):

- Validação: acrescenta `'from_user_id' => 'nullable|integer|exists:ex_users,id'`.
- Logo depois da validação e **antes** de qualquer consulta de ciclo ou `ProofStorage::store`: `$debtorId = (int) ($data['from_user_id'] ?? auth()->id())`; se `$debtorId !== auth()->id()` e `! canPayFor((int) $groupId, auth()->id(), $debtorId)`, `abort(403, 'Você não pode pagar por esse membro.')` (R6, F4: nada é gravado).
- Troca os dois usos de `auth()->id()` (a busca do acerto, `:1769`, e a chave, `:1778`) por `$debtorId`.
- `updateOrCreate` grava `'confirmed_by' => $debtorId === auth()->id() ? null : auth()->id()` (R7, R8: o último remetente vence, e o reenvio do próprio devedor zera).
- `Notifier::settlementConfirmed` passa o **nome do devedor** (`User::find($debtorId)->name`, antes era `auth()->user()->name`) e, como último argumento novo, `$paidByName` (o nome de quem chama, ou `null` quando o remetente é o devedor).
- O disparo de WhatsApp fica como está: usa `fromUser`, que continua sendo o devedor (Specify 2.10).
- Um helper privado `responsibles(): MemberResponsibleService` resolvido pelo container, no padrão de `cycles()` e `payments()`, porque os testes instanciam o controller com `new ExpenseController` por reflexão.
- R14 não tem código: o backend aceita o responsável que também é o credor; só a interface esconde os botões.

## 6. `confirmedBy` no resumo (Specify R10, CA-8)

`CycleSummaryService::withConfirmations` (`:362`): carrega as confirmações com `with('confirmedByUser:id,name')` e acrescenta a cada acerto `confirmedBy` = `['id' => ..., 'name' => ...]` **só** quando `confirmed_by` existe e é diferente de `from_user_id` (comparados com `(int)`), senão `null`. É o ponto único que `summary`, `close` e `reopen` já usam, então nada muda nos três; o caminho de snapshot não é afetado, porque as confirmações são lidas ao vivo.

## 7. Notificações (Specify R10, R11, R15, D9)

- `Notifier::paymentResponsibleAssigned(Group $group, int $responsibleId, ?string $debtorName): void` — `guard('payment_responsible_assigned', ...)` e `fanOut([$responsibleId], 'payment_responsible_assigned', $group->id, ['actorName' => $debtorName, 'groupId' => $group->id, 'groupName' => $group->name])`. Com `group_id` e `groupId`, porque o responsável é membro e o sino pode abrir o grupo.
- `Notifier::settlementConfirmed(...)` ganha um último parâmetro, `?string $paidByName = null`, e só inclui `'paidByName'` no `data` quando ele não é nulo. Os chamadores e testes de hoje não mudam.
- Nenhuma migration: `ex_notifications.type` é `string` (Specify 2.9).

## 8. Frontend: tipos e texto do sino (Specify 2.9, 3.6, R10, CA-16)

- `frontend/src/types/group.ts`: `export type MemberResponsible = { id: number; name: string }`; `GroupMember` ganha `responsibles?: MemberResponsible[]`; `GroupMemberBasic = Omit<GroupMember, 'avatar_url' | 'responsibles'>`.
- `frontend/src/hooks/useGroupCycle.ts`: `SummarySettlement` ganha `confirmedBy?: MemberResponsible | null`.
- `frontend/src/components/notificationText.ts`: novo caso `payment_responsible_assigned` → `Você agora pode pagar por ${actorName} no grupo "${groupName}"`; em `settlement_confirmed`, se `data.paidByName` existir, `${paidByName} confirmou um pagamento de ${valor} por ${actorName}`, senão o texto de hoje.

## 9. Frontend: Participantes (Specify 3.6, CA-14)

- **Componente novo** `frontend/src/components/PaymentResponsibleDialog.tsx`, com o estado e as chamadas dele, para o `GroupMembersForm` (426 linhas) mudar pouco. Props: `open: boolean`, `groupId: string`, `currentUserId: number`, `members: GroupMember[]` (todos; o componente exclui o próprio usuário), `current: MemberResponsible[]`, `onClose: () => void`, `onChanged: (message: string) => void`. O seletor é o `Autocomplete` do MUI com `multiple` e `disableCloseOnSelect`, com os escolhidos como selos. Salvar faz `PUT /api/groups/{groupId}/members/{currentUserId}/responsibles` com `{responsible_user_ids}` (a lista inteira, vazia inclusive); **não há botão de desfazer**; os textos são os de Specify §3.6 (`Escolha salva.` ou `Escolha desfeita.`); erro mostra "Não foi possível salvar agora. Tente novamente."
- **`GroupMembersForm.tsx`:** o botão "Quem paga por mim" na linha do próprio usuário (perto de `:288`); o texto secundário no `ListItemText` (`:287`); os selos "Paga por {nome}" derivados dos membros cuja lista `responsibles` contém o `id` da linha; a frase extra no diálogo de sair e remover (`:395`) quando o alvo paga por alguém; e `onChanged` recarrega os membros (o mesmo `GET` do fluxo de saída) e mostra o aviso de sucesso.

## 10. Frontend: Pagamentos (Specify 2.5, 3.6, R9, R14, D6, CA-15)

- `PayableSettlementList.tsx`: prop opcional `payForUserIds?: readonly number[]` (padrão `[]`). `canPayFor = !isDebtor && !isReceiver && payForUserIds.includes(settlement.from_user_id)`; `canAct = isDebtor || canPayFor` substitui `isDebtor` nos dois blocos de ação (`:146` e `:167`). Selo `Você paga por {nome}` quando `canPayFor`; texto `Enviado por {nome}` ao lado do selo "Comprovante enviado" quando `settlement.confirmedBy`. Rótulos de botão iguais aos de hoje.
- `PaymentSettlementsPanel.tsx`: recebe `payForUserIds` e repassa à lista e à ordenação.
- `utils/cycleOrdering.ts`: `orderSettlementsForViewer(settlements, viewerId, nameFor, alsoFirstIds: readonly number[] = [])`: as saídas do usuário primeiro, depois as de `alsoFirstIds`, depois as demais, mantendo o critério de hoje dentro de cada grupo.
- `Payments.tsx`: `payForUserIds` = ids dos `members` cuja lista `responsibles` contém `currentUserId`; `confirmSettlementPayment` (`:158`) acrescenta `from_user_id` ao `FormData` **só** se `confirmSettlementTarget.from_user_id !== currentUserId`. O tipo local `GroupMemberPix` ganha `responsibles?: MemberResponsible[]`.
- `GroupPositionCard` e `useConfirmSettlement` **não** mudam (D6): o Painel segue como hoje.

## 11. Testes e validação (Specify 5.1, 5.2, CA-12, CA-13, CA-17)

Cada task é **teste primeiro**: escrever o teste, ver falhar pelo motivo certo, implementar, ver passar, commitar.

- **Backend, arquivos novos em `backend/tests/Feature/`:**
  - `MemberResponsibleServiceTest` (TASK-583): `replace` cria, troca e esvazia a lista, mantém a linha de quem ficou e devolve `added` e `removed`; recusa o próprio devedor, repetidos e quem não é membro **sem alterar nada**; `clearFor` apaga nas duas direções e devolve a contagem; `canPayFor` só com a linha exata; o mesmo responsável serve a vários devedores.
  - `GroupMemberResponsibleControllerTest` (TASK-584): CA-1, CA-2 e CA-10; 403 para o criador; a mudança da lista avisa só quem entrou e não avisa em `PUT` idêntico; `DELETE` dá 405; `Notifier` falhando não desfaz.
  - `GroupMemberResponsibleMembersTest` (TASK-585): `responsibles` no `GET /members` sem e-mail; sair, ser removido e o responsável sair apagam o vínculo; o bloqueio 422 de saída não apaga.
  - `SettlementConfirmationOnBehalfTest` (TASK-586 a 588): CA-4 a CA-8 e o foco de revisão 1, 3 e 4.
  - `ResponsibleAuthorizationMatrixTest` (TASK-589): a **matriz de autorização** do `from_user_id` (foco 2): sem vínculo, vínculo de outro grupo, vínculo desfeito, vínculo de outro devedor, id inexistente, chamador não membro e ex-membro como devedor; todo 403 sem arquivo e sem linha.
- **Mutation check** das regras de autorização e de consistência (R2, R3, R6, R12): quebrar a regra à mão, ver o teste falhar, restaurar, e registrar no `implementation.md`.
- **Frontend, arquivos novos:** `PaymentResponsibleDialog.test.tsx`, `GroupMembersForm.responsible.test.tsx`, `PayableSettlementList.responsible.test.tsx`, `Payments.responsible.test.tsx`, `cycleOrdering.responsible.test.ts` e `notificationText.responsible.test.ts`.
- **Comandos:** `cd backend && ./vendor/bin/pint --test` e `php artisan test` (com o banco de teste de pé, contêiner `mysql-expense-test` e `backend/.env.testing`, e `php artisan migrate:fresh --env=testing --force` depois das TASK-583 e 586); `cd frontend && npx tsc --noEmit` e `npx vitest run --maxWorkers=1`.
- **Revisão de segurança (TASK-589):** o agent `security-reviewer` na rota nova e na mudança do `confirmSettlement`, com o resultado no `implementation.md`.

## 12. Documentação (Specify CA-18)

`docs/sdd/01-specify.md`: uma linha no glossário (§2) para o vínculo, um parágrafo em §3.3 (Grupos) com a rota nova (`PUT .../responsibles`) e o `responsibles` em `GET /members`, e uma regra em §4 ("só o devedor escolhe quem paga por ele; o acerto continua do devedor e `confirmed_by` registra quem enviou"). O baseline não descreve `confirmSettlement`; não o completo aqui (fora de escopo).

## 13. Ordem de execução

Primeiro a base de dados e o service, depois as rotas de vínculo, depois a lista e a saída de membros, depois a coluna e o `confirmSettlement`, depois a matriz de segurança, e por último a web e a documentação, para o PR único nunca ter tela apontando para endpoint incompleto.

- **Dependências:** 584, 585 e 587 dependem da 583 (service); 587 depende da 586 (coluna); 588 e 589 dependem da 587, e a 589 também da 584; 590 não depende de nenhuma; 591 depende da 584, 585 e 590; 592 depende da 587, 588 e 590; 593 por último.
- **Antes da 583:** a **D10** foi aprovada em 2026-11-09 (ver item 7 do topo).
- **Gate de migration:** as TASK-583 e 586 criam migrations; rodar em banco compartilhado ou produção é gate humano.
