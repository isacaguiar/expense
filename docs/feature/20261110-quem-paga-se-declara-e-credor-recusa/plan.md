# Plan — Quem paga se declara e o credor recusa o comprovante

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261110

**Status: aprovado por ele em 2026-11-10** ("aprovado, siga com as tasks"), sobre o specify v1.1.

**Resumo.** *Objetivo:* quem paga se declara responsável por outros membros sem aceite do devedor, e o credor aceita ou recusa o comprovante enviado por responsável, só em ciclo não encerrado. *Arquitetura:* duas colunas aditivas em `ex_settlement_confirmations` (`accepted_at`, `refused_at`) e uma em `ex_group_member_responsibles` (`created_by`); o **estado do comprovante é derivado** dessas colunas e de `confirmed_by`, sem coluna `status`; o selo do ciclo passa a contar só o comprovante "que vale"; um service novo concentra aceitar, recusar e excluir; o selo sai do controller para o `CycleSummaryService`; o front ganha o diálogo "Eu pago por…" e as ações do credor em Pagamentos. *Escopo:* backlog 144, 141 e 143.

> **O que este plano ajusta ou acrescenta ao Specify** (nada aqui muda uma decisão aprovada):
>
> 1. **Sem `refused_by` nem `accepted_by`.** Só o credor do par aceita ou recusa, então quem decidiu é sempre `to_user_id`; a coluna seria redundante. A D4-B fica com `refused_at` e `accepted_at`.
> 2. **Estado derivado, sem coluna `status`.** `refused_at` preenchido = recusado; senão `accepted_at` = aceito; senão `confirmed_by` não nulo = aguardando aceite; senão = enviado pelo próprio devedor. Um `status` gravado poderia ficar inconsistente com as datas.
> 3. **Recusado aparece como "não confirmado" na API.** Em `withConfirmations`, o acerto recusado sai com `confirmedAt`, `confirmedProofUrl` e `confirmedNote` **nulos** (a linha e o arquivo continuam no banco, D4-B), e ganha `confirmationStatus` e `refusedAt`. Assim os oito arquivos do front que leem `confirmedAt` (`cycleOrdering.ts`, `useGroupCycle.ts`, `PaymentSettlementsPanel.tsx`, `PayableSettlementList.tsx`, `SettlementList.tsx`, `GroupPositionCard.tsx`, `CycleClosingAlert.tsx`, `ClosingReportPanel.tsx`) já tratam o recusado como "a pagar" sem edição.
> 4. **O selo sai do controller para o `CycleSummaryService`.** `accept` precisa selar o ciclo, e `sealCycleIfSettled` é `private` em `ExpenseController` (2000 linhas). Extrai-se para `CycleSummaryService::sealIfSettled`; o método privado do controller vira um delegado de uma linha, porque `ExpenseControllerCycleIsFullySettledTest` o chama por reflexão. É uma task própria, sem mudar comportamento.
> 5. **Aceitar vale só para "aguardando aceite".** Recusar **não se desfaz**: quem enviou reenvia (o comprovante volta a "aguardando aceite"). É o mais simples e o recusado nem tem URL de arquivo na API para o credor rever.
> 6. **O credor que é o próprio remetente pode aceitar o seu comprovante** (senão o ciclo nunca selaria nesse caso, que só ocorre por chamada direta à API); os avisos nunca vão para quem fez a ação (R12).
> 7. **"Excluir" vale só para comprovante com `confirmed_by`** (o que passa pelo aceite). O comprovante que o devedor envia por conta própria não ganha exclusão nova.
> 8. **Reenvio depois de recusa reavisa o credor.** Hoje o aviso `settlement_confirmed` só sai na criação da linha (`wasRecentlyCreated`); depois de uma recusa o reenvio é um `update`, então a condição ganha "ou estava recusado".
> 9. **Controller novo, service novo:** `SettlementDecisionController` + `SettlementConfirmationService` (aceitar, recusar, excluir) e `GroupPaysForController` (o responsável se declara). Nada disso vai para o `ExpenseController` nem para o `GroupMemberController`.

## Restrições globais

Valem para todas as tasks.

- Migrations **aditivas** (Constitution §1.4, §4.2), com `up` e `down`; **gate humano** antes de rodar em banco compartilhado ou produção (o deploy aplica no merge em `main`, ADR-008, e a janela deploy antes de `migrate` da INFO-1 se repete).
- Índices e chaves com nome dentro dos **64 caracteres** do MySQL.
- Contrato da API só aditivo: nenhum campo, rota ou status que já existe muda de sentido (§4.1). `confirmedAt` do acerto recusado passar a ser nulo **é** a semântica nova do estado novo, não uma troca de sentido para quem nunca recusou.
- Rota dentro de `jwt.auth`; **404** para quem não é membro (e grupo excluído), **403** para membro sem permissão, **422** para regra de negócio; 404/403 **antes** de qualquer escrita de arquivo ou linha (§6.5).
- Controller fino, regra no service (§1.3). Backend: `./vendor/bin/pint` limpo e PHPUnit para toda regra nova (§2.1, §2.2). Frontend: TypeScript estrito, sem `any` não justificado (§2.3).
- `from_user_id` da confirmação **é sempre o devedor**; `confirmed_by` é quem enviou, nulo se foi o devedor; **nenhum rótulo novo usa "Responsável" sozinho**.
- **Nenhum teste que já existia é editado**: todo teste novo entra em arquivo novo. Se algum teste antigo precisar mudar, é sinal de regressão e a task para.
- Backend: suíte inteira só com o banco de teste **zerado** (`migrate:fresh`) e `./vendor/bin/phpunit`; frontend: `--maxWorkers=1` nesta máquina, conferindo que rodaram todos os arquivos.
- Exclusão física **só** na TASK de excluir (D7-A, aval dele em 2026-11-10): citar no PR. A recusa e o aceite não apagam nada (D4-B).

## Foco de revisão

Casos que o specify implica mas que ninguém lembraria de testar, do mais provável ao menos. Cada um tem o teste na task dona.

1. **O último comprovante é de um responsável:** não sela o ciclo; o aceite do credor sela (e só se `totals.pending == 0`). O de quem paga por conta própria sela como hoje. Testes em TASK-597 e TASK-598.
2. **O devedor reenvia por cima de um comprovante de responsável aguardando aceite:** `confirmed_by` volta a nulo e o comprovante passa a valer na hora (o devedor responde por ele). Teste em TASK-601.
3. **Recusa, reenvio e novo aceite em sequência:** recusar zera nada além de `refused_at`; reenviar limpa `refused_at` e `accepted_at`, apaga o arquivo anterior (já faz) e **reavisa o credor**; aceitar sela. Testes em TASK-599 e TASK-601.
4. **Aceitar e reenviar ao mesmo tempo (F6):** a linha é travada e relida; vence o primeiro que commita e o outro vê o estado novo (um 422 limpo, não um estado misto). Teste em TASK-598.
5. **O responsável sai da lista (ou do grupo) com comprovante aguardando:** o comprovante fica; o credor ainda aceita ou recusa; quem saiu da lista já não reenvia (403, R6 da feature anterior) mas ainda **exclui o próprio** comprovante. Testes em TASK-600 e TASK-604.
6. **O credor é o remetente:** aceita sem aviso a si mesmo, e a confirmação dele não gera sino nem WhatsApp (R12). Testes em TASK-598 e TASK-602.
7. **Ciclo selado:** aceitar, recusar, excluir e reenviar dão 422 e **não escrevem**; ciclo que ainda não fechou dá 422 de "só depois do fechamento". Teste em TASK-605.
8. **Aviso repetido (item 141):** `PUT []` seguido de `PUT [R]` em menos de 24 h avisa uma vez; depois de 24 h, de novo; falha do cache não derruba a ação. Testes em TASK-603.
9. **Teto e duplicados** da lista de `me/pays-for` (50, repetido, si mesmo, não membro): 422 e **nada** muda. Teste em TASK-604.

---

## 1. Modelo de dados (Specify R3, R5, R7, R8, D3, D4; 2.2)

- **TASK-594, `ex_group_member_responsibles.created_by`:** migration aditiva `2026_11_10_000000_add_created_by_to_ex_group_member_responsibles_table`: `unsignedBigInteger('created_by')->nullable()->after('responsible_user_id')`, chave estrangeira para `ex_users` com `nullOnDelete` (nome gerado `ex_group_member_responsibles_created_by_foreign`, 46 caracteres, cabe). Nula para os vínculos que já existem (do devedor). `GroupMemberResponsible::$fillable` ganha `created_by`. `MemberResponsibleService::replace` passa a gravar `created_by = $debtorId` nas linhas que cria; o resto do service não muda.
- **TASK-595, `ex_settlement_confirmations.accepted_at` e `refused_at`:** migration aditiva `2026_11_10_000001_add_acceptance_to_ex_settlement_confirmations_table`: dois `timestamp()->nullable()` depois de `confirmed_at`. Sem índice (a consulta é sempre pela chave do par). Model `SettlementConfirmation`: `$fillable` e `$casts` (`datetime`) dos dois campos; constantes `STATUS_SENT`, `STATUS_AWAITING_ACCEPTANCE`, `STATUS_REFUSED`, `STATUS_ACCEPTED`; `isThirdParty()` (`confirmed_by` não nulo e diferente de `from_user_id`); `status()` pela regra do ajuste 2; e o escopo `scopeCountsForSettlement` (ver §3), que é **o único lugar** que define "este comprovante vale para o selo".
- **Por que estado derivado:** as quatro situações saem de duas datas e de `confirmed_by`, e `status()` é testável sozinho; uma coluna `status` poderia divergir das datas.

## 2. Extrair o selo do ciclo (Specify 2.5; ajuste 4)

- **TASK-596:** `CycleSummaryService::sealIfSettled(Group $group, Carbon $start, Carbon $end): bool` recebe o corpo de `ExpenseController::sealCycleIfSettled` (`:1908-1954`) **sem mudar uma linha de comportamento**: mesma condição (`isFullySettled`), mesmo `GroupCycleSnapshot::updateOrCreate`, mesma notificação `Notifier::cycleSettled` só na transição. `ExpenseController::sealCycleIfSettled` vira `return $this->cycles()->sealIfSettled($group, $start, $end);` e `unsealIfBroken` e `cycleIsFullySettled` ficam como estão (reflexão em `ExpenseControllerCycleIsFullySettledTest`).
- **Por que task à parte:** é refatoração de uma rota financeira; a prova é a suíte atual inteira verde **sem edição** (CA-4) e um teste novo do método extraído. Se algo quebrar, o problema está isolado.

## 3. O selo conta só o comprovante que vale; a API expõe o estado (Specify R5, 2.2, CA-4)

- **TASK-597, `summaryIsFullySettled`** (`CycleSummaryService.php:415-442`): trocar `SettlementConfirmation::where(...)` por `->countsForSettlement()`. O escopo: `whereNull('refused_at')` **e** (`whereNull('confirmed_by')` **ou** `accepted_at` não nulo). Ou seja, o do devedor conta como hoje, o de responsável só depois do aceite, e o recusado nunca. Como `focus`, `isFullySettled`, `sealIfSettled` e os ciclos pendentes passam por aí, **uma** mudança cobre todos.
- **`withConfirmations`** (`:362-394`): cada acerto ganha `confirmationStatus` (`sent`, `awaiting_acceptance`, `refused`, `accepted`, ou nulo sem comprovante) e `refusedAt`; quando recusado, `confirmedAt`, `confirmedProofUrl` e `confirmedNote` saem nulos (ajuste 3) e `confirmedBy` continua, para a tela dizer "enviado por". A ordenação por `confirmedAt === null` põe o recusado entre os "a pagar". Campos novos só **aditivos**.
- **Teste de não regressão:** `confirmed_by` só aparece em `SettlementConfirmationOnBehalfTest` (34 ocorrências, nenhuma espera selo), então os testes atuais de selagem não mudam.

## 4. Aceitar, recusar e excluir: `SettlementConfirmationService` e `SettlementDecisionController` (Specify R5 a R9, F2 a F7, D4-B, D7-A, D9, D10)

Rotas dentro de `jwt.auth`, no grupo `groups/{groupId}/settlements` (ao lado de `confirm`): `POST accept`, `POST refuse` e `DELETE confirmation`. **Tasks:** TASK-598 (aceitar, que cria o service, o controller e a exceção), TASK-599 (recusar) e TASK-600 (excluir, a única com exclusão física).

- **Entrada de aceitar e recusar:** `from_user_id` e `to_user_id` obrigatórios (existem em `ex_users`) e `cycles_ago` opcional, o mesmo formato de `confirm`. `to_user_id` diferente do usuário logado: **403** antes de qualquer consulta (F2). A de excluir lê os mesmos três campos da query string.
- **Service** (`app/Services/SettlementConfirmationService.php`), tudo numa transação com a linha do comprovante em `lockForUpdate` e relida (F6):
  1. `resolveCycle`: o ciclo precisa estar **fechado** (por data ou manualmente, como `confirmSettlement`, `:1767-1778`) e **não selado**; senão 422 com as mesmas mensagens de hoje ("O acerto só pode ser confirmado depois que a competência é fechada.", "Esta competência já foi encerrada.", F4).
  2. Achar o comprovante do par no ciclo; **404** se não existe. Sem `confirmed_by` (ou igual ao devedor), **422** "Só o comprovante enviado por quem paga por outra pessoa passa pelo aceite." (R6).
  3. **`accept`:** só de `awaiting_acceptance` (senão 422); grava `accepted_at = now()`; depois da transação, `sealIfSettled`; aviso `settlement_accepted` a `confirmed_by`.
  4. **`refuse`:** só de `awaiting_acceptance`; grava `refused_at = now()`; **não apaga arquivo nem linha** (D4-B); avisos `settlement_refused` a `confirmed_by` e ao devedor.
  5. **`destroy`** (quem chama é `confirmed_by` ou o devedor, senão 403; só de `awaiting_acceptance` ou `refused`, senão 422): apaga a linha e **depois** o arquivo com `ProofStorage::deleteIfUnreferenced` (que olha quotas e comprovantes de acerto antes). É o único `DELETE` físico da feature (D7-A).
- **Avisos** (`Notifier`, mesmo `guard` best effort, R13): `settlementRefused`, `settlementAccepted` e, com os dados do acerto, `actorName` (o credor), `amount`, `cycleLabel`, `groupId`, `groupName`; ao remetente o recusado diz também de **quem** é o acerto (`debtorName`). Ninguém é avisado de uma ação que ele mesmo fez (R12).
- **Exceção de domínio** `SettlementDecisionRefused` (como `MemberResponsibleRefused`), que o controller devolve como `422 { error }`, o formato das rotas de acerto.
- **Por que service e não método no `ExpenseController`:** o controller já tem 2000 linhas e esta regra é nova; o service é testável sem HTTP.

## 5. `confirmSettlement` respeita o aceite e reavisa depois de recusa (Specify R5, R8, F5; ajuste 8)

- **TASK-601**, em `ExpenseController::confirmSettlement`, **antes** de `ProofStorage::store`: se já existe o comprovante e ele está **aceito**, 422 "Este comprovante já foi aceito." (F5), sem gravar arquivo. No `updateOrCreate`, `accepted_at` e `refused_at` voltam a `null` (reenvio = comprovante novo, volta a "aguardando aceite" se for de responsável). O aviso `settlement_confirmed` passa a sair quando `wasRecentlyCreated` **ou** o comprovante anterior estava recusado. O resto da rota (403 antes de gravar, `confirmed_by`, `from_user_id`) **não muda**.
- **Sem aviso de quem enviou a si mesmo (item 143, R12), TASK-602:** quando `auth()->id()` é o `to_user_id`, pula `Notifier::settlementConfirmed` **e** o `dispatch` do WhatsApp (`:1840-1850`). É uma condição, e fica em task própria porque o item 143 é um achado separado.

## 6. Janela contra aviso repetido (Specify R11, item 141, D11)

- **TASK-603:** em `Notifier`, um helper privado `oncePer(string $key, int $minutes, callable $send): void` que faz `Cache::add($key, true, now()->addMinutes($minutes))` e só envia se a chave não existia; se o envio falhar, `Cache::forget($key)` para não suprimir o próximo; qualquer exceção do cache vira log (dentro do `guard`). A chave é `payment-responsible:{grupo}:{devedor}:{responsável}:{tipo}`. `paymentResponsibleAssigned` passa a usar o helper, com 24 h. **Por que cache e não coluna:** a linha do vínculo é apagada quando a pessoa sai da lista, então não guardaria a marca entre sair e voltar; já há `Cache::put` em produção (`InvitationController.php:106`).

## 7. O responsável se declara: `GroupPaysForController` (Specify R1 a R4, R11, F1, D1, D2, D3, D8)

- **TASK-604:** `PUT /groups/{groupId}/me/pays-for` com `debtor_user_ids` (`present|array|max:50`, itens `integer`; mensagem "Escolha no máximo 50 pessoas."). Ordem: grupo inexistente ou excluído, 404; não membro, 404 (`authorizeGroupMembership`); validação do corpo.
- **`MemberResponsibleService::declare(Group $group, int $responsibleId, array $debtorIds): array{added, removed}`:** mesma forma de `replace`, mas pelo outro lado: o grupo é travado e relido; recusa (`MemberResponsibleRefused`, **antes de escrever**) a si mesmo, o repetido e quem não é membro; a lista atual é **todos** os devedores cujo responsável é quem chama (inclusive os vínculos que o devedor criou); o que sai da lista é apagado (D8, o responsável larga o papel) e o que entra é criado com `created_by = $responsibleId` (D3). 422 devolve `{ message }`, como `GroupMemberResponsibleController`.
- **Avisos:** para cada devedor que **entrou**, `Notifier::paymentResponsibleSelfDeclared` (tipo `payment_responsible_self_declared`, `actorName` = quem se declarou, com `groupId`), pelo helper da §6; quem já estava ou saiu não é avisado. O devedor remove pelo `PUT .../responsibles` que já existe (R2): se omitir quem se declarou, o vínculo some, o que **já funciona sem mudança**.
- **Resposta:** `{ message: 'Escolha salva.', pays_for: [{ id, name }] }`, só id e nome (R13 da feature anterior).

## 8. Matriz de autorização e revisão de segurança (Specify 5.1 CA-9, RK1, RK4)

- **TASK-605:** testes de matriz para as quatro rotas novas (`me/pays-for`, `accept`, `refuse`, `confirmation`): devedor, responsável, credor, terceiro, criador do grupo, não membro, grupo excluído, ciclo aberto, ciclo selado, comprovante inexistente e sem `confirmed_by`. **Mutation check** das regras R1, R5, R6 e R9 (cada mutante é uma condição trocada: remover o `whereNull('confirmed_by')` do escopo, deixar o credor errado passar, aceitar em ciclo selado, escrever antes do 403). Depois, o agent `security-reviewer`; o resultado vai para o `implementation.md`. **Gate humano:** revisão antes do merge (é rota financeira).

## 9. Frontend: tipos e textos do sino (Specify 3.6, CA-11)

- **TASK-606:** `SummarySettlement` ganha `confirmationStatus?` (união das quatro) e `refusedAt?`; `notificationText.ts` ganha os três tipos novos (`payment_responsible_self_declared`: "{nome} agora paga por você no grupo "{grupo}""; `settlement_refused`: "{credor} recusou o comprovante de {devedor} de R$ {valor}"; `settlement_accepted`: "{credor} aceitou o comprovante de R$ {valor}") e, no `settlement_confirmed` com `paidByName`, o complemento "Aguarda o seu aceite." Os tipos novos abrem o resumo do grupo no sino (`group_id`), como `payment_responsible_assigned`. Teste em arquivo novo, sem editar `notificationText.responsible.test.ts`.

## 10. Frontend: Participantes, "Eu pago por…" (Specify 3.6, CA-10)

- **TASK-607:** componente novo `PaysForDialog.tsx` (clone enxuto do `PaymentResponsibleDialog`): seletor múltiplo dos **outros** membros, `PUT /api/groups/{id}/me/pays-for`, mensagens "Escolha salva." e o erro genérico "Não foi possível salvar agora. Tente novamente.". Em `GroupMembersForm.tsx`: botão "Eu pago por…" na linha própria (junto de "Quem paga por mim" e "Sair do grupo", dentro do **mesmo grupo de botões já em linha própria** da V1) e o texto "Você paga por: Ana, Davi", derivado de `members.filter(m => m.responsibles?.some(r => r.id === currentUserId))`, sem campo novo na API. **O layout é medido no navegador em 375, 768, 1024, 1280 e 1440 px**, porque o jsdom não mede (lição da V1); com três botões o grupo quebra em duas linhas no celular e isso é esperado.

## 11. Frontend: Pagamentos, aceitar, recusar e excluir (Specify 3.6, R6 a R8, CA-11)

- **TASK-608:** hook novo `useSettlementDecision` (aceitar, recusar e excluir, com o mesmo cabeçalho e `cycles_ago` de `useConfirmSettlement`); `PayableSettlementList` e `PaymentSettlementsPanel` recebem os estados:

  | Quem vê | Estado | O que a tela mostra |
  |---|---|---|
  | Credor | `awaiting_acceptance` | "Enviado por {nome}", selo "Aguardando seu aceite", botões **Aceitar** e **Recusar** |
  | Credor | `refused` | "Você recusou o comprovante" (fica em "a pagar") |
  | Devedor e quem paga por ele | `awaiting_acceptance` | selo "Aguardando aceite do credor" e **Excluir** |
  | Devedor e quem paga por ele | `refused` | selo "Recusado por {credor}", **Enviar comprovante** (já existe) e **Excluir** |
  | Todos | `accepted` | selo "Aceito pelo credor", sem reenvio nem exclusão |
  | Todos | `sent` ou nulo | como hoje |

  `Payments.tsx` liga os três handlers e recarrega o ciclo depois de cada ação. A exclusão pede confirmação num diálogo (é definitiva). Os botões seguem a regra de largura da V1 (nenhuma coluna de texto sem espaço).

## 12. Testes e validação (Specify 5.1, 5.2, CA-12)

- Cada task backend: **RED executado**, GREEN, **mutation check** por regra e a suíte inteira com banco zerado; cada task frontend: `tsc` limpo e `vitest related` dos arquivos tocados, e a **suíte inteira** (`--maxWorkers=1`) na revisão final.
- Os testes novos usam `tests/Concerns/BuildsSettlementScenarios.php` (acerto de agosto/2026 com `Carbon::setTestNow('2026-08-19')`) e criam o ciclo fechado manualmente, como `SettlementConfirmationOnBehalfTest`.
- **Validação local no navegador** (pedido dele, como na feature anterior): três usuários (devedor, responsável, credor) em stack isolada, com o layout de Participantes e de Pagamentos medido nas cinco larguras.

## 13. Documentação (Specify CA-13)

- **TASK-609:** `docs/sdd/01-specify.md` v1.4: glossário (o termo "Quem paga por quem" ganha "se declara" e "aceite do credor"), o bloco de §3.3 e uma regra em §4. A Constitution **não** é editada.

## 14. Ordem de execução

Há dependência técnica; o critério é "base de dados e regra primeiro, tela por último", parando após cada task.

1. **Dados:** TASK-594 (`created_by`) e TASK-595 (aceite e recusa no model), independentes entre si.
2. **Selo:** TASK-596 (extrair, sem mudar comportamento) e, sobre ela e a 595, TASK-597 (o selo conta só o que vale; a API expõe o estado).
3. **Credor:** TASK-598 (aceitar), TASK-599 (recusar) e TASK-600 (excluir), nessa ordem; depois TASK-601 (`confirmSettlement` respeita o aceite) e TASK-602 (item 143).
4. **Quem paga:** TASK-603 (janela contra aviso repetido, item 141) antes da TASK-604 (declarar), que a usa.
5. **Fechamento do backend:** TASK-605 (matriz, mutation check e revisão de segurança).
6. **Frontend:** TASK-606 (tipos e textos), depois TASK-607 (Participantes, depende da 604) e TASK-608 (Pagamentos, depende da 597 a 601).
7. **Docs:** TASK-609.
