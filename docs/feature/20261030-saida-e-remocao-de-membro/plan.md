# Plan — Saída e Remoção de Membro do Grupo

> Traduz `specify.md` (v1.2) em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20261030

---

## 0. Pontos que o plano ainda deixa para o usuário

O `specify.md` v1.1 foi aprovado nas decisões D1 a D5 (como recomendado). Dois pontos novos aguardam OK junto com este plano:

- **D6** (§4): o que o painel pessoal mostra do ex-membro. Recomendação **B** (nome do ex-devedor, sem contato; ex-credor continua "Desconhecido"). Com a **A**, a §4 inteira cai: nenhum arquivo do painel pessoal muda.
- **Correção da D3-B (ii)**: fixa ativa também bloqueia o **credor** (com outros participantes), não só o participante.

**Emenda da v1.1 deste plano (2026-10-30):** o specify v1.2 acrescenta a **R11** e a **D7** (achado §2.9 da TASK-554, opção A escolhida pelo usuário). A §4.1 abaixo traduz a R11 na **TASK-560**. Aguarda OK a **D7**: como o ex-membro aparece nos cartões de saldo. Recomendação **B** (rótulo "saiu do grupo", que acrescenta a TASK-561, só frontend); com a **A**, a TASK-560 sozinha basta.

## 1. `GroupMemberController::destroy` e a regra de bloqueio (specify R1 a R5, R9, D2, D3, D5, F1 a F7)

**Onde:** `backend/app/Http/Controllers/GroupMemberController.php` (método novo, depois do `store`); classe nova `backend/app/Services/MemberExitBlockers.php`. A rota `DELETE /groups/{groupId}/members/{userId}` (`routes/api.php:57`) já existe e não muda.

**Fluxo do `destroy($groupId, $userId)`**, nesta ordem (a ordem decide qual código sai primeiro):

1. `Group::find` + `abort_if($group === null || $group->deleted, 404)` e `authorizeMembership($group)` (404). Mesmo desenho do `index` (item 085): `find` e `abort`, não `findOrFail`, para o corpo do 404 ser igual com grupo inexistente, alheio ou excluído (R1, CA-2).
2. Alvo não é membro: `abort_unless(... exists(), 404)` (R2).
3. Quem pode (D2-A): `$isSelf = (int) $userId === auth()->id()`; se não é a própria saída, exige `$group->created_by !== null && $group->created_by === auth()->id()`, senão **403**.
4. Dentro de `DB::transaction`: `Group::lockForUpdate()->find()` (serializa dois últimos membros saindo juntos, F6), roda `MemberExitBlockers` (D3-B) **depois do lock**, e se houver bloqueio devolve 422 sem alterar nada; senão `members()->detach($userId)` e, se `members()->count() === 0`, `update(['deleted' => true])` (D5-A, exclusão lógica: nunca `DELETE` físico, Constitution §5.2).
5. Fora da transação, só se **não** é a própria saída: `Notifier::groupMemberRemoved(...)` (R8, §2 deste plano). A saída própria não notifica.
6. Resposta **200** `{ message }`: "Você saiu do grupo." ou "Membro removido do grupo."

**Por que checar o bloqueio dentro do lock:** o lock fecha a janela entre "olhei" e "tirei" apenas para saídas concorrentes; criar despesa não toma esse lock, então uma despesa cadastrada no meio continua possível. É aceito: o efeito é uma parcela que o ex-membro deve, o mesmo estado que a D3-B já tolera para devedores.

**`MemberExitBlockers::for(Group $group, int $userId): array`** devolve lista de `['expense_id', 'description', 'reason']`, no máximo 10, `reason` = `active_fixed` ou `creditor_open_quotas`. Consultas diretas em `Expense` (`group_id`, `deleted = false`), em vez de reaproveitar `CycleSummaryService::entries`, que é por ciclo e montaria dados que aqui não importam:

- **Fixa ativa** (`expense_type = 'FIXED'` e `fixed_recurrence_ends_at` nulo ou maior que o primeiro dia do mês do ciclo corrente, a mesma condição de `CycleSummaryService.php:52-53`, com o início do ciclo vindo de `BillingCycle::cycleFor($group->closing_day, now())`): bloqueia se o usuário é **participante não credor**, ou **credor** com ao menos um outro participante em `payers`.
- **Credor com parcela aberta** (qualquer tipo, fora as já listadas como fixa ativa): `user_payer_id = usuário`, `whereHas('quotas', paid = false)` e `whereHas('payers', id <> usuário)`. Cobre parcelas futuras de compra parcelada, já geradas.

O **devedor** de parcela avulsa ou parcelada não entra em nenhuma consulta: sai (200). O mesmo bloqueio vale quando o criador remove outro membro (CA-4). O corpo do 422 é `{ message, blocking: [...] }`, com `message` na pessoa certa ("Você ainda tem…" / "Este membro ainda tem…").

**Por que não outra abordagem:** bloquear qualquer pendência (D3-A) já foi descartado no specify; calcular saldo líquido por ciclo é mais caro e não responde à pergunta ("alguém consegue confirmar isto depois?").

## 2. Notificação ao removido (R8)

- `Notifier::groupMemberRemoved(Group $group, int $removedUserId, ?string $actorName)` em `backend/app/Support/Notifier.php`, no molde de `groupMemberAdded` (linha 206): `guard('group_member_removed', …)` e `fanOut([$removedUserId], 'group_member_removed', null, ['actorName' => …, 'groupName' => …])`.
- **`group_id` fica `null` e `groupId` fica fora do `data`**: `NotificationsMenu.tsx:96-99` navega para `/groups/{id}/summary` quando há id, e o removido receberia 404. Com `null`, o clique só marca como lida. A coluna é nullable e a listagem (`NotificationController`) filtra por destinatário, não por grupo.
- Frontend: `case 'group_member_removed'` em `frontend/src/components/notificationText.ts` (texto: `${actorName} removeu você do grupo "${groupName}"`).

## 3. Despesa antiga com ex-membro (R6, RK2)

`ExpenseController::update` (`:174` e `:176`) troca `Rule::exists('ex_groups_members', …)` por `Rule::in($permitidos)` nos campos `user_payer_id` e `payers.*`, onde `$permitidos` = membros do grupo ∪ `payers` atuais da despesa ∪ `user_payer_id` atual (método privado novo). Quem nunca esteve na despesa e não é membro continua recebendo 422 (CA-5), e a mensagem de erro é a mesma do `exists`: o projeto usa o locale `en` sem arquivos de idioma próprios, e as duas regras têm o texto padrão do framework `The selected … is invalid`. O `store()` (`:462-464`) **não muda**. Por que `Rule::in` e não estender o `exists`: o `exists` só consulta uma tabela; a união com o pivô de pagadores exige lista.

## 4. Nome e contato do ex-membro no painel pessoal (R7, D6, §2.6 do specify)

Só se a D6 for **B**. `backend/app/Services/PersonalOverviewService.php`:

- Em `forGroup`, depois de `$members = $group->members->keyBy('id')`, buscar **uma vez** os usuários de `from_user_id` que não estão em `$members` (`User::whereIn('id', …)->get()->keyBy('id')`, só quando houver algum, para não gastar consulta no caso comum; o orçamento de consultas travado em `PersonalOverviewServiceTest` fica intacto).
- Para o lado `to_receive` (`from_user`, `withContact: false`) usar `$members` ∪ ex-devedores; `person()` devolve o **nome** e força `avatar_url = null` para quem não é membro.
- O lado `to_pay` (`to_user`, o credor) **não muda**: ex-credor segue `Desconhecido`, sem e-mail, Pix nem avatar, e o teste `test_a_creditor_who_left_the_group_shows_as_unknown_and_without_contact` (`PersonalOverviewServiceTest.php:561-576`) fica **inalterado**.
- `ClosingBreakdown` **não muda**: já preserva o nome do ex-membro (specify §2.6). Entra só um teste de regressão.

## 4.1 Nome do ex-membro nas telas de acerto do grupo (R11, D7, specify §2.9, CA-16)

Independente da D6 e da §4. `backend/app/Services/CycleSummaryService.php`, método `compute` (`:222-271`):

- Dentro do loop dos participantes (`:256`), **depois** do `continue` do credor e antes das guardas `isset`, garantir uma entrada em `$balances` para o participante e para `$expense->payer` (quando não é nulo). Se `$balances[$user->id]` não existe, criar `['user_id' => …, 'name' => $user->name, 'avatarUrl' => null, 'balance' => 0.0, 'formerMember' => true]`. As guardas `isset` ficam como estão (passam a ser sempre verdadeiras, o que as deixa inofensivas) para o diff ser mínimo.
- Por que dentro do loop e depois do `continue` de `born_paid` (`:246`): é exatamente a condição em que nasce um item de `$owed` e, portanto, um `settlement`. A regra "toda ponta de acerto tem nome" vale por construção, e quem só tem parcela retroativa quitada ou nenhuma dívida no ciclo não aparece.
- Os membros ficam como estão: mesmas chaves, mesmos números, mesma ordem. A chave `formerMember` só existe nas entradas novas, e elas vêm depois de todos os membros.
- `avatarUrl` nulo de propósito (R7): a foto de quem saiu não sai. E-mail e Pix `balances` nunca teve.
- Sem consulta nova: `entries()` já carrega `payer` e `payers` (`:55`, `:83`, `:109`). Os tetos de consulta de `PersonalOverviewServiceTest` (TASK-427/428), que passam por `compute`, continuam valendo sem edição.
- Contrato aditivo (Constitution §4, item 1): `balances` ganha entradas e uma chave opcional, sem mudar tipo nem sentido de campo nenhum. `ExpenseController` só repassa `balances` e **não muda**. O que o `compute` devolve também é o que se sela: fechamentos futuros guardam essas entradas, e os já selados não são reescritos.

Frontend: nada muda com a D7-A. Com a D7-B (recomendada), a TASK-561 acrescenta `formerMember?: boolean` a `SummaryBalance` (`useGroupCycle.ts:69-74`) e o rótulo "saiu do grupo" em `BalanceCards.tsx`, sem mexer nas quatro telas que resolvem nome por `balances`, que passam a achá-lo.

A TASK-554 (painel pessoal) não muda: o `formerDebtors()` dela é independente e seus testes seguem valendo. Com a 560 pronta o painel poderia ler o nome de `balances` e dispensar a consulta, mas isso reabriria task concluída; fica como simplificação posterior.

## 5. Limite de 3 grupos criados (D4-A, RK5)

`GroupController::store` (`:51-53`): a contagem passa a `Group::where('deleted', 0)->where('created_by', auth()->id())->whereHas('members', fn ($q) => $q->where('user_id', auth()->id()))->count()`. Por que não zerar `created_by` na saída: perderia o registro de quem criou e o `created_by` é o que autoriza a D2. Os testes de limite de `GroupControllerTest` continuam valendo (o criador segue membro nos cenários deles).

## 6. Interface web (§3.6 do specify, CA-12 a CA-14)

`frontend/src/pages/GroupMembersForm.tsx`, sem rota nova, sem componente genérico novo:

- `useCurrentUserId()` (já existe em `hooks/useCurrentUserId.ts`, chama `/api/me`) e `isCreator = group.created_by != null && group.created_by === currentUserId` (o tipo `GroupDetail` já traz `created_by`).
- Na lista (linhas 116-125) cada `ListItem` ganha `secondaryAction`: **Sair do grupo** na linha do usuário logado; **Remover** nas linhas dos outros, só se `isCreator`.
- Diálogo MUI de confirmação, com `Dialog`, `DialogTitle`, `DialogContent` e `DialogActions` do MUI, como `ExpenseManager.tsx` e `PaymentProofDialog.tsx` já usam (o repositório não tem um `ConfirmDialog` genérico), com o texto fixo do specify (sair não apaga despesas nem dívidas; o credor continua vendo o que você deve).
- Chamada `axios.delete(`${API_BASE_URL}/api/groups/${id}/members/${userId}`, config)`. Sucesso ao sair: `navigate('/meus-grupos')`; ao remover: recarrega `GET …/members` e fecha. Erro: `err.response?.data?.message` (idioma de `PixPaymentDialog.tsx:72`), e quando vier `blocking`, a lista de descrições aparece no próprio diálogo, que permanece aberto.
- **Testes:** os dois testes existentes de `GroupMembersForm.test.tsx` seguem verdes **sem edição**: o mock devolve o grupo para qualquer URL que não seja `/members`, então `/api/me` responde com `id: 1`, e o fixture não tem `created_by`, logo ninguém é criador. Os novos casos entram em testes **novos** no mesmo arquivo; o `beforeEach` não reseta `axios.delete`, então cada caso novo reseta o seu.

## 7. Testes (specify §5)

| Camada | Onde | Cobre |
|---|---|---|
| Feature | `GroupMemberControllerTest` (casos novos, sem mexer nos 9 existentes) | CA-1, CA-2, CA-3, CA-4 (os dois motivos, mais o criador removendo), CA-7, CA-9, F6 e F7 |
| Unidade | `MemberExitBlockersTest` (novo) | os casos de §1, inclusive fixa interrompida (sai), participante único e credor sem outros participantes |
| Feature | `ExpenseControllerUpdateFormerMemberTest` (novo) | CA-5 |
| Feature | `GroupControllerTest` (caso novo) | CA-8 |
| Feature | `PersonalOverviewServiceTest` (casos novos) e `ExpenseControllerClosingReportTest` (caso novo) | CA-6 |
| Feature | `NotifierTriggersTest` (caso novo) | R8 e `group_id` nulo |
| Feature | `CycleSummaryServiceFormerMemberTest` (novo) | CA-16 |
| Frontend | `GroupMembersForm.test.tsx` (casos novos), `notificationText.test.ts` (caso novo) | CA-12 a CA-14 |
| Frontend | `BalanceCards.test.tsx` (caso novo ou arquivo novo) | CA-17, só com a D7-B (TASK-561) |

Nenhum teste existente é editado. Antes do PR: `security-reviewer` na rota (CA-11) e `pr-readiness-checker`.

## 8. Documentação (CA-15)

`docs/sdd/01-specify.md` linha 53 passa a descrever o `destroy`; `docs/sdd/03-tasks.md` linha 39 (`TASK-017`) vira ponteiro para esta pasta. **Constitution §2.4** cita a rota como quebrada: edição é gate humano, então só é avisada ao usuário no relatório final.

## 9. Ordem de execução

Há dependência técnica: o `destroy` (§1) chama o `Notifier` (§2), e a tela (§6) depende da API pronta e do texto da notificação. §3, §4 e §5 não dependem de nada e podem sair em qualquer ordem depois do `destroy`. Critério de ordenação em `tasks.md`: primeiro a regra de bloqueio isolada (testável sozinha), depois o endpoint, depois os ajustes de histórico, por último a tela e a documentação, para o PR único nunca ter a tela apontando para um endpoint ainda incompleto. A §4.1 (TASK-560) também não depende de nada e deve sair **antes** da revisão de segurança (TASK-556), porque passa a expor o nome do ex-membro no `summary`; ela entra no escopo dessa revisão.
