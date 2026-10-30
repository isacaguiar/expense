# Tasks — Saída e Remoção de Membro do Grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261030

**Status: v1.0, aguardando aprovação (Constitution §5.2).** O specify (v1.1) e o plan foram aprovados em 2026-10-30, com D1 a D5 como recomendado, **D6 = B** e o ajuste da D3-B (ii) (fixa ativa bloqueia também o credor).

IDs a partir de **TASK-549**: o maior em `docs/`, em todas as branches remotas e nas mensagens de commit de todas as refs é TASK-548 (feature `20261027-pagamento-no-cadastro-despesa`), conferido em 2026-10-30 com `git grep` e `git log --all`. Reconfirmar a numeração antes de criar a primeira branch de task, caso outra feature em andamento reserve IDs.

Escopo: `backend/`, `frontend/` e documentação. Esta feature **absorve a `TASK-017`** do Épico C (`docs/sdd/03-tasks.md`), cujo ponteiro é a TASK-559. Ordem (plan §9): primeiro a regra de bloqueio isolada, depois o endpoint, depois os ajustes de histórico, a revisão de segurança da API pronta, e por último a tela e a documentação, para o PR único nunca ter a tela apontando para um endpoint incompleto. As tasks 552 a 555 não dependem umas das outras e podem sair em qualquer ordem depois da 551. A TASK-558 reúne **Sair do grupo** e **Remover** de propósito: os dois botões compartilham o mesmo diálogo e o mesmo tratamento de erro, e separá-los obrigaria a construir o diálogo duas vezes. **Nenhum teste existente é editado**; todo teste novo entra em caso novo ou arquivo novo.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-549 | Criar `MemberExitBlockers`, a regra de bloqueio da saída (D3-B), com testes de unidade | backend | plan.md §1 | nenhum | Concluída |
| TASK-550 | Criar `Notifier::groupMemberRemoved` (tipo `group_member_removed`, `group_id` nulo) com teste | backend | plan.md §2 | nenhum | Concluída |
| TASK-551 | Implementar `GroupMemberController::destroy` (autorização, transação com lock, bloqueio, notificação e exclusão lógica do grupo sem membros) | backend | plan.md §1 | antes do merge | Concluída |
| TASK-552 | Fazer `ExpenseController::update` aceitar quem já estava na despesa, mesmo que já não seja membro | backend | plan.md §3 | nenhum | Concluída |
| TASK-553 | Contar no limite de 3 grupos só os grupos de que o criador ainda é membro | backend | plan.md §5 | nenhum | Pendente |
| TASK-554 | Mostrar no painel pessoal o nome do ex-devedor, sem contato nem avatar (D6-B) | backend | plan.md §4 | nenhum | Pendente |
| TASK-555 | Travar com teste de regressão que o relatório de fechamento preserva o nome do ex-membro | backend | plan.md §4 | nenhum | Pendente |
| TASK-556 | Rodar o `security-reviewer` na rota e no controller novos e tratar os achados | backend | plan.md §1 | nenhum | Pendente |
| TASK-557 | Adicionar o texto da notificação `group_member_removed` no frontend | frontend | plan.md §2 | nenhum | Pendente |
| TASK-558 | Adicionar **Sair do grupo**, **Remover** e o diálogo de confirmação em `GroupMembersForm` | frontend | plan.md §6 | nenhum | Pendente |
| TASK-559 | Atualizar `01-specify.md` §3.3 e deixar o ponteiro da `TASK-017` em `03-tasks.md` | doc | plan.md §8 | nenhum | Pendente |

## Critérios de aceite

- **TASK-549**:
  - Classe nova `backend/app/Services/MemberExitBlockers.php` com `for(Group $group, int $userId): array`, devolvendo no máximo 10 itens `['expense_id', 'description', 'reason']`, `reason` = `creditor_open_quotas` ou `active_fixed`.
  - `cd backend && php artisan test --filter MemberExitBlockersTest` verde, com casos para: devedor de parcelada com parcela não paga (lista vazia); credor com parcela não paga e outro participante, **inclusive parcela futura** (`creditor_open_quotas`); credor que é o único participante (vazia); credor com todas as parcelas pagas (vazia); fixa ativa com recorrência nula e com `fixed_recurrence_ends_at` depois do mês do ciclo: participante não credor e credor com outros participantes (`active_fixed`), credor sozinho (vazia); fixa já interrompida (vazia); despesa `deleted` e despesa de outro grupo ignoradas; limite de 10 itens; despesa que cai nos dois critérios aparece uma só vez.
  - `git diff --stat -- backend/routes backend/app/Http` vazio (a classe ainda não é chamada por ninguém); `./vendor/bin/pint --test` limpo.
- **TASK-550**:
  - `Notifier::groupMemberRemoved(Group, int $removedUserId, ?string $actorName)` em `backend/app/Support/Notifier.php`, no molde de `groupMemberAdded`, dentro de `guard`.
  - Caso novo em `NotifierTriggersTest`: grava 1 linha em `ex_notifications` para o removido com `type = group_member_removed`, `group_id` nulo e `data` com `actorName` e `groupName` e **sem** `groupId`; uma falha ao gravar (simulada como nos testes existentes de `guard`) não propaga.
  - Testes existentes de `NotifierTriggersTest` e `NotificationModelTest` verdes e sem edição; `pint --test` limpo.
- **TASK-551**:
  - Método `destroy($groupId, $userId)` em `GroupMemberController`, na ordem do plan §1: 404 (grupo inexistente, alheio ou excluído), 404 (alvo não membro), 403 (não é a própria saída nem o criador), transação com `lockForUpdate`, bloqueio por `MemberExitBlockers` (422 `{ message, blocking }`, mensagem "Você ainda tem…" ou "Este membro ainda tem…"), `detach`, `deleted = true` se não sobrar membro, e notificação só quando não é a própria saída. Resposta 200 `{ message }`. `routes/api.php` sem diff.
  - `php artisan test --filter GroupMemberControllerTest` verde: os **9 existentes sem edição** mais casos novos para **CA-1** (200; some de `GET /api/groups` e de `GET /api/me/overview`; a linha do pivô some; contagens de `ex_expenses`, `ex_quotas`, `ex_expenses_payers` e `ex_group_cycle_snapshots` iguais antes e depois), **CA-2** (não membro, grupo inexistente e grupo excluído com o **mesmo** status e corpo; alvo não membro; repetir o `DELETE` dá 404), **CA-3** (criador remove outro: 200; membro comum remove outro: 403; grupo com `created_by` nulo só aceita saída própria; criador que já saiu não remove ninguém), **CA-4** (devedor sai; credor com parcela aberta e fixa ativa dão 422 com `blocking` e nada muda; o criador removendo alguém bloqueado também dá 422), **CA-7** (removido por outro recebe `group_member_removed`; saída própria não cria notificação; falha do `Notifier` não impede o 200), **CA-9** (último membro que sai deixa `deleted = true` e nenhuma linha some; dois últimos saindo em sequência marcam o grupo uma vez só).
  - `cd backend && php artisan test` (suíte inteira) verde e `./vendor/bin/pint --test` limpo.
- **TASK-552**:
  - `ExpenseController::update` troca `Rule::exists('ex_groups_members', …)` por `Rule::in($permitidos)` em `user_payer_id` e `payers.*`, com `$permitidos` = membros do grupo ∪ `payers` atuais ∪ `user_payer_id` atual (método privado novo). `store()` sem diff nas linhas 462-464.
  - Arquivo novo `ExpenseControllerUpdateFormerMemberTest`: despesa com ex-membro em `payers` e como credor, reenviada mantendo-os, responde 200 e preserva o pivô (**CA-5**); incluir alguém que nunca esteve na despesa e não é membro continua dando 422.
  - `php artisan test --filter ExpenseController` verde com os testes existentes sem edição; `pint --test` limpo.
- **TASK-553**:
  - `GroupController::store` conta `Group::where('deleted', 0)->where('created_by', auth()->id())` **com** `whereHas('members', user_id = auth()->id())`.
  - Caso novo em `GroupControllerTest` (**CA-8**): criador com 3 grupos sai de um deles pelo `DELETE` de membro e consegue criar outro (201); um quarto dá 422; o grupo que ele ainda integra continua contando.
  - Os testes de limite existentes e `AuthControllerMeExposesGroupLimitTest` seguem verdes sem edição; `pint --test` limpo.
- **TASK-554**:
  - `PersonalOverviewService::forGroup` busca **uma vez** os usuários de `from_user_id` que não são membros (`User::whereIn`), só se houver algum, e o lado `to_receive` usa essa lista; `person()` devolve só o nome para quem não é membro e força `avatar_url` nulo.
  - Casos novos em `PersonalOverviewServiceTest` (**CA-6**): ex-devedor aparece em `to_receive[].from_user` com nome real e **sem** `email`, `pix` nem avatar; ex-credor segue `Desconhecido` sem contato; sem ex-membro, o número de consultas não muda.
  - `test_a_creditor_who_left_the_group_shows_as_unknown_and_without_contact` (`:561-576`) e os tetos de consultas das TASK-427/428 passam **sem edição**; `php artisan test --filter PersonalOverviewServiceTest` verde; `pint --test` limpo.
- **TASK-555**:
  - Caso novo em `ExpenseControllerClosingReportTest` (**CA-6**, metade do fechamento): relatório com devedor que saiu do grupo traz o nome real dele em `creditors[].debtors[]`, nunca "Desconhecido". O teste registra também o que o código faz hoje com `id` e `avatarUrl` do ex-membro, sem mudar o comportamento.
  - `git diff --stat -- backend/app` vazio (task só de teste); `php artisan test --filter ExpenseControllerClosingReportTest` verde; testes existentes sem edição.
- **TASK-556**:
  - O agent `security-reviewer` roda sobre `routes/api.php:57`, `GroupMemberController::destroy`, `MemberExitBlockers`, a validação nova do `update()` e a contagem do `store()` de grupo; o relatório fica registrado em `implementation.md`.
  - Nenhum achado de severidade alta ou média sem correção (com teste) ou item de `docs/backlog/`; achado baixo vira item de backlog (**CA-11**). Suíte do backend verde depois de qualquer correção.
- **TASK-557**:
  - `case 'group_member_removed'` em `frontend/src/components/notificationText.ts`, com o texto `${actorName} removeu você do grupo "${groupName}"`.
  - Casos novos: em `notificationText.test.ts`, `notificationText('group_member_removed', { actorName: 'Ana', groupName: 'Casa' })` devolve `Ana removeu você do grupo "Casa"`; em `NotificationsMenu.test.tsx`, clicar numa notificação desse tipo com `group_id` nulo marca como lida e **não** navega.
  - `cd frontend && npx vitest run src/components/notificationText.test.ts src/components/NotificationsMenu.test.tsx` verde; testes existentes sem edição; `npx tsc --noEmit` sem erro.
- **TASK-558**:
  - `GroupMembersForm.tsx` usa `useCurrentUserId()`; `isCreator = group.created_by != null && group.created_by === currentUserId`; cada `ListItem` ganha `secondaryAction`: **Sair do grupo** na linha do usuário logado e **Remover** nas linhas dos outros, só para o criador; diálogo MUI (`Dialog`, `DialogActions`) com o texto fixo do specify §3.6; `axios.delete` em `/api/groups/${id}/members/${userId}`. Sem rota nova (`git diff -- frontend/src/App.tsx` vazio).
  - Casos novos em `GroupMembersForm.test.tsx` (**CA-12**, **CA-13**), com os 2 existentes verdes **sem edição**: o usuário comum vê **Sair do grupo** só na própria linha e nenhum **Remover**; o criador vê **Remover** nas outras; **Cancelar** não chama `axios.delete`; confirmar a saída chama o `DELETE` com o id do usuário e navega para `/meus-grupos`; confirmar a remoção chama o `DELETE`, recarrega `GET …/members` e fecha o diálogo; 422 com `message` e `blocking` mantém o diálogo aberto mostrando a mensagem e as descrições, sem navegar; outro erro mostra mensagem genérica.
  - `cd frontend && npx vitest run src/pages/GroupMembersForm.test.tsx` verde; `npx tsc --noEmit` sem erro e sem `any` novo (**CA-14**).
- **TASK-559**:
  - `docs/sdd/01-specify.md` §3.3 passa a descrever o `destroy` (quem pode, bloqueio da D3-B, efeito só no pivô) no lugar de "método não implementado"; a linha da `TASK-017` em `docs/sdd/03-tasks.md` vira `~~…~~ — migrada para docs/feature/20261030-saida-e-remocao-de-membro/ (TASK-551)`, no formato das demais (**CA-15**).
  - `git diff -- docs/sdd/00-constitution.md` vazio: a Constitution §2.4, que cita a rota como quebrada, **não é editada** (gate humano) e é apenas sinalizada ao usuário no relatório final.

## Depois da aprovação deste arquivo

Antes da primeira task: atualizar a linha do item 086 em `docs/backlog/README.md` e o campo `Status` do arquivo do item para `Promovido para TASK-549`; o arquivo permanece em `docs/backlog/` até a execução terminar (`.claude/skills/promover-backlog/SKILL.md` §4 e §6).
