# Specify — Saída e Remoção de Membro do Grupo

> Feature: o usuário passa a conseguir **sair de um grupo** (e o criador do grupo a **remover** outro membro), pela API e por um botão na tela de membros da web. Hoje a rota `DELETE /groups/{groupId}/members/{userId}` existe, mas o método não, e quem é adicionado a um grupo nunca consegue sair. Promove o item 086 do backlog e absorve a `TASK-017` do Épico C de `docs/sdd/03-tasks.md`.

Versão: 1.2 · Criado em: 20261030

**Status: v1.2.** A v1.0 foi aprovada em 2026-10-30 com **D1 a D5 como recomendado** (inclusive a D3-B), e a v1.1 no mesmo dia, com a **D6 = B**. A v1.2 nasceu da TASK-554 (achado §2.9, opção A escolhida pelo usuário) e acrescenta R11, D7, CA-16 e CA-17 e as TASK-560 e TASK-561; **aprovada em 2026-10-31, com a D7 = B**. Escopo decidido pelo usuário: **só o item 086**, com **backend e botão na web**; os itens 095 (apagar grupo em cascata) e 072 (excluir conta) ficam de fora.

### Mudanças da v1.1 em relação à v1.0

| Onde | Mudança | Por quê |
|---|---|---|
| §2.6, R7, RK3, CA-6 | Reescritos. O painel pessoal **anonimiza o ex-membro de propósito** (TASK-432), e o fechamento **já preserva o nome**. A "nome real, nunca Desconhecido" da v1.0 contrariava um teste existente. | `PersonalOverviewServiceTest.php:561-576` trava a política; eu só tinha lido o código, não o teste. |
| D6 (nova) | Decide o que o painel pessoal mostra do ex-membro. | Consequência direta da D3-B: o devedor sai com parcela em aberto e o credor passaria a ver "Desconhecido". |
| D3-B (ii), §2.4, CA-4 | Fixa ativa bloqueia o **participante** e também o **credor** (com outros participantes). | A v1.0 só citava o participante, mas as parcelas futuras de um credor que saiu ficariam sem ninguém para confirmar (§2.3). |

### Mudanças da v1.2 em relação à v1.1

| Onde | Mudança | Por quê |
|---|---|---|
| §2.9 (novo), R11 (nova), CA-16 (novo) | As telas de acerto do **grupo** também mostravam o ex-devedor como "Desconhecido": `balances` só tem membros atuais. `balances` passa a trazer quem é ponta de um acerto, só com nome. | Achado da TASK-554, confirmado com uma sonda descartável. A v1.1 só tinha analisado o painel pessoal e o relatório de fechamento. |
| D7 (nova), CA-17 (novo) | Decide como o ex-membro aparece nos cartões de saldo. | Incluí-lo em `balances` o faz aparecer em `BalanceCards`. |
| §3.1 item 3, RK3, RK7 (novo), §6 | Referências ao §2.9; risco novo dos cartões; dois itens fora de escopo. | Coerência com o acima. |

---

## 1. Problema

`GroupMemberController::store()` coloca no grupo qualquer usuário cadastrado, pelo e-mail, sem aceite, e notifica o adicionado. A rota para tirar alguém existe, mas aponta para um método que não existe, então a pessoa não sai nunca. Juntos, um membro pode listar qualquer conta em seus grupos e a vítima fica presa, com e-mail e Pix visíveis aos demais (problema de produto e de LGPD). O limite de 3 grupos **criados** por conta (`GroupController.php:51-53`) encarece o abuso, mas não há teto de grupos **em que alguém participa**. A Constitution §2.4 já proíbe task "pronta" com endpoint quebrado e lista esta rota como quebrada.

### 1.1 Verificação prévia

O pedido corresponde à `TASK-017` (`docs/sdd/03-tasks.md:39`, Épico C, ainda sem pasta) e ao item 086 do backlog, promovido pelo usuário em 2026-10-30 com o escopo acima. Esta pasta passa a ser o dono dessa task; no `03-tasks.md` fica só um ponteiro (feito na etapa de Tasks).

## 2. Achados confirmados

Tudo abaixo foi lido no código em 2026-10-30.

### 2.1 A rota existe e o método não

`backend/routes/api.php:54-58` registra `GET`, `POST` e `DELETE /{userId}` em `groups/{groupId}/members`; `GroupMemberController` só tem `index` (linha 17), `store` (30) e `authorizeMembership` (96). Chamar o `DELETE` quebra hoje.

### 2.2 Sair do grupo é só remover a linha do pivô, e o resto do sistema não depende dela para calcular dívidas

A tabela `ex_groups_members` tem chave composta `(group_id, user_id)` e `ON DELETE CASCADE` nas duas pontas (`2025_06_12_024544_create_ex_group_members_table.php`). **Quem deve a quem não vem dos membros**: o credor é `user_payer_id` da despesa e os devedores são os participantes do pivô `ex_expenses_payers` (`Expense::payers()`, `Expense.php:75-78`), menos o credor (`ExpenseController.php:1247-1257`). Logo, tirar alguém de `ex_groups_members` **não apaga nem recalcula nada**: despesas, quotas, participantes, comprovantes e fechamentos continuam intactos. O que muda é o **acesso**: `GET /groups`, `GET /me/overview` e toda rota do grupo filtram por `members` (`GroupController.php:19-21`, `PersonalOverviewService.php:43-47`, `Controller.php:19`).

### 2.3 Depois de sair, o ex-membro não consegue confirmar nada

`pay()` e as demais ações de despesa passam por `findExpenseForMember` (`ExpenseController.php:1891-1896`), que exige ser membro e devolve 404 caso contrário. Ou seja: **um credor que sai deixa parcelas que ninguém mais pode confirmar** (`pay()` só aceita o credor, `auth()->id() !== $expense->user_payer_id`, `ExpenseController.php:1325-1326`). O devedor que sai perde só a tela, porque quem confirma o pagamento da parcela é o credor.

### 2.4 Despesa fixa continua cobrando o participante que saiu

A despesa `FIXED` nasce sem quota e materializa uma por ciclo enquanto `fixed_recurrence_ends_at` for nulo ou posterior ao mês (`CycleSummaryService.php:52-53`, `ExpenseController.php:64-65`). Um participante que saiu continuaria sendo cobrado todo mês; se for o **credor** que saiu, as parcelas futuras nasceriam sem ninguém para confirmá-las (§2.3). Quem pode interromper a recorrência (`stopRecurrence`, `ExpenseController.php:821-855`) é qualquer **membro**, não o ex-membro.

### 2.5 Editar uma despesa antiga falha se ela tem um ex-membro

`update()` valida `user_payer_id` e `payers.*` contra `ex_groups_members` (`ExpenseController.php:174-176`; o `store()` faz o mesmo em 462-464). Depois da saída, reenviar uma despesa que ainda lista o ex-membro daria 422. É uma consequência direta desta feature, que precisa ser tratada.

### 2.6 O painel pessoal anonimiza o ex-membro de propósito, e o fechamento não

`PersonalOverviewService::person()` devolve `'Desconhecido'`, sem e-mail nem Pix, a quem não está em `$group->members` (`PersonalOverviewService.php:147-151`). Foi decisão da **TASK-432** ("o contato dele não é mais de um membro"), travada em `PersonalOverviewServiceTest.php:561-576` para o **credor** que saiu. A função é a mesma para o **devedor** que saiu: com a D3-B ele sai com parcela em aberto, e o credor passaria a ver "Desconhecido" no que tem a receber. Já `ClosingBreakdown::build` **preserva o nome** do ex-membro: usa `participantDetails` e `payerName` do payload da despesa e, sem membro correspondente, o ex-membro entra sem `id` e sem avatar (`ClosingBreakdown.php:33-36` e `106`).

### 2.7 O limite de 3 grupos criados conta grupos que o criador já não enxerga

`GroupController.php:51-53` conta `Group` por `created_by`, sem olhar se o criador ainda é membro. O criador que sair continuaria com a vaga ocupada por um grupo que não vê nem consegue excluir. `created_by` é nullable e `nullOnDelete` (`2026_08_20_010000_add_created_by_to_ex_groups_table.php:15-21`), então grupos antigos podem não ter criador.

### 2.8 A web já lista membros, mas não tem ação de saída

`GroupMembersForm.tsx` lista os membros (linhas 116-125) e adiciona por e-mail (`handleAddMember`, linha 61), sem nenhuma chamada de remoção. `GET /api/groups/{id}` já devolve `creator {id, email}` (`GroupController.php:77`, `types/group.ts`), e `GET /api/me` devolve o usuário logado (`AuthController.php:53`). A notificação `group_member_added` tem texto em `frontend/src/components/notificationText.ts:28`; um tipo novo precisa de entrada ali.

### 2.9 As telas de acerto do grupo também mostram o ex-devedor como "Desconhecido"

Achado da TASK-554 (2026-10-30), confirmado com uma sonda descartável. `CycleSummaryService::compute` semeia `balances` **só** com `$group->members` (`CycleSummaryService.php:222-230`), mas `settlements` nasce de `$owed`, que inclui qualquer participante da despesa (`:238-314`); as guardas `isset($balances[...])` (`:261` e `:265`) existem porque o ex-membro pode não estar lá. Com o devedor fora do grupo, `balances` traz só o credor e `settlements` traz devedor→credor. O frontend resolve o nome de cada ponta do acerto por `balances` (`SettlementList.tsx:35-36`, `PayableSettlementList.tsx:52-53`, `PaymentSettlementsPanel.tsx:55-56`, `CycleClosingAlert.tsx:30-35`; o relatório de fechamento passa `report.balances` em `ClosingReportPanel.tsx:205`) e cai em "Desconhecido", sem foto. `balances` também alimenta os cartões de saldo (`BalanceCards.tsx`), então incluir o ex-membro ali o faz aparecer nesses cartões. `payer` e `payers` já são carregados por `entries()` (`CycleSummaryService.php:55`, `:83` e `:109`): o nome do ex-membro já está em memória, sem consulta nova. Snapshots já selados guardam o `balances` do momento do fechamento e não são reescritos.

## 3. Requisitos

### 3.1 O que a feature entrega

1. `GroupMemberController::destroy($groupId, $userId)`, com autorização, regra de bloqueio (D3) e testes.
2. Botão **Sair do grupo** (e, para o criador, **Remover** em cada outro membro) na tela de membros da web, com confirmação.
3. Ajustes para o histórico seguir funcionando com ex-membros: edição de despesa antiga (§2.5) e nome real nas telas (§2.6 e §2.9).
4. Notificação ao membro removido por outra pessoa.

### 3.2 Regras adotadas

Não dependem de decisão; o usuário pode vetar qualquer uma na aprovação.

| # | Regra |
|---|---|
| R1 | `destroy` responde **404** igual para grupo inexistente, grupo alheio (quem chama não é membro) e grupo excluído logicamente, como o `index` (item 085) e o `store`. Nenhuma resposta confirma a existência do grupo a quem não é membro. |
| R2 | Alvo que não é membro do grupo: **404**. |
| R3 | O efeito é **só** remover a linha de `ex_groups_members`, em transação. Não toca despesas, quotas, participantes (`ex_expenses_payers`), pagamentos, comprovantes nem fechamentos: o histórico é preservado. Nenhum `DELETE` físico de dado de negócio (gate da Constitution §5.2). |
| R4 | Contrato aditivo (Constitution §4, item 1): a rota já existe e passa de quebrada a funcional; sem migration. Sucesso: **200** com `{ "message": "…" }`. |
| R5 | Depois de sair, o grupo some de `GET /api/groups` e do `GET /api/me/overview`, e qualquer rota do grupo responde 404 ao ex-membro. |
| R6 | `update()` de despesa passa a aceitar como `user_payer_id` e `payers.*` quem **já estava** na despesa, mesmo sem ser mais membro. Quem não estava continua tendo de ser membro, e o `store()` não muda. |
| R7 | **E-mail e Pix de ex-membro nunca são exibidos.** O painel pessoal segue a D6; o relatório de fechamento já preserva o nome do ex-membro e ganha só um teste de regressão. O teste da TASK-432 não muda. |
| R8 | O membro **removido por outra pessoa** recebe a notificação `group_member_removed` (best effort, no padrão `Notifier::guard`: falha ao notificar não desfaz a remoção). Sair por conta própria não notifica ninguém. |
| R9 | Quem sai e depois é adicionado de novo volta como membro normal, com o histórico que já tinha (`store()` não muda). |
| R10 | Rodar o agent `security-reviewer` na rota nova e o `pr-readiness-checker` antes do PR. |
| R11 | Toda pessoa que é ponta de um acerto (`settlements`) de um ciclo tem entrada em `balances` com o nome. Quem já saiu do grupo entra com `user_id`, `name`, `avatarUrl` **nulo**, `balance` e a chave nova `formerMember: true` (só nessas entradas); e-mail e Pix nunca. Os saldos dos membros não mudam (mesmas chaves, números e ordem; os ex-membros vêm depois). Contrato aditivo (Constitution §4, item 1). Snapshots já selados não são reescritos. |

### 3.3 Contrato da API (proposta, aditiva)

| Chamada | Quem pode | Respostas |
|---|---|---|
| `DELETE /api/groups/{groupId}/members/{userId}` | O próprio usuário (`userId` = quem chama) ou, conforme D2, o criador do grupo | **200** `{ message }`; **404** (R1, R2); **403** membro que não é o criador tentando remover outro; **422** `{ message, blocking }` quando a D3 bloqueia, com a lista curta de despesas que impedem a saída |

### 3.4 Decisões em aberto

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como a pessoa entra num grupo que não conhece | A) entra direto, como hoje, e passa a poder sair<br>B) convite pendente que a pessoa aceita ou recusa | **A** | Resolve "não consigo sair" sem tabela, migration nem tela de convites. B é a correção completa do consentimento, mas exige migration, notificação de aceite e UI nos dois clientes; pode vir depois, aditivo (candidato a backlog). |
| D2 | Quem pode remover quem | A) o próprio usuário sai e o **criador** do grupo remove outros<br>B) só a saída própria | **A** | Cobre o convidado indevido sem depender de ele agir. Grupo antigo sem `created_by` (§2.7) só permite saída própria. |
| D3 | Saída de quem tem valores em aberto | A) bloquear com **qualquer** parcela não paga ou recorrência fixa ativa, como credor ou participante<br>B) bloquear só nos **dois casos que quebram o sistema**: (i) é **credor** de despesa com parcela não paga de outra pessoa (§2.3) e (ii) é **participante** de despesa **fixa ativa**, ou **credor** dela com outros participantes (§2.4)<br>C) nunca bloquear | **B** (muda a recomendação do item 086, que era A) | O **A** prende o usuário por meses em compra parcelada (as parcelas já nascem geradas) e, pior, **recria a armadilha que a feature quer desfazer**: um membro mal-intencionado cadastra uma despesa pondo a vítima como participante e ela não consegue sair. O **C** deixa a fixa cobrando para sempre e o credor sem poder confirmar nada (§2.3 e §2.4). O **B** bloqueia só o que de fato corrompe o fluxo; o devedor de parcelas sai e o credor segue vendo a dívida e confirmando. A brecha do B (vítima nomeada credor ou posta em fixa) só some com a D1-B. |
| D4 | Saída do criador do grupo | A) permitir, como qualquer membro, e o limite de 3 grupos passa a contar só grupos de que ele **ainda é membro**<br>B) bloquear até haver outro criador | **A** | `GroupController` autoriza tudo por **membro**, não por criador, então sair não deixa o grupo órfão. A mudança no limite (§2.7) evita a vaga presa; sem ela o criador que sai perde a vaga para sempre. |
| D5 | Grupo que fica sem nenhum membro | A) marcar `deleted = true` (exclusão lógica) na mesma transação<br>B) deixar como está | **A** | Sem membros ninguém alcança o grupo; marcar como excluído é o mesmo caminho lógico do `GroupController::destroy` e **não apaga nada** (sem hard delete). |
| D6 | O que o painel pessoal mostra de um ex-membro (§2.6) | A) manter **"Desconhecido"** para qualquer ex-membro, devedor ou credor (política da TASK-432 inteira, nenhum teste muda)<br>B) mostrar o **nome** (nunca e-mail, Pix nem avatar) do ex-membro que **deve** ao usuário, e manter anônimo o ex-**credor** | **B** | A TASK-432 escondeu o credor que saiu porque ninguém mais tem a quem pagar, e esse caso continua anônimo (o teste dela não muda). O devedor que saiu é outro caso: o credor já vê o nome dele nas telas de despesa e precisa saber quem deve. Com a D3-B, "Desconhecido deve R$ 50" seria rotina. |
| D7 | Como o ex-membro aparece nos cartões de saldo (`BalanceCards`), já que `balances` passa a trazê-lo (§2.9, R11) | A) igual aos demais, **sem rótulo** (nada muda no frontend)<br>B) com o rótulo **"saiu do grupo"** (acrescenta a TASK-561, só frontend)<br>C) **fora** dos cartões: `balances` serve só para o nome nos acertos (também exige mudança no frontend) | **B** | O cartão mostra uma posição que existe de verdade (o ex-devedor ainda deve), então esconder (C) apaga informação, e deixar sem rótulo (A) faz aparecer um nome que o usuário não reconhece como membro. O rótulo é pequeno e só usa a chave `formerMember` da R11. Com a **A**, a TASK-560 sozinha basta. |

### 3.5 Falhas e consistência

| # | Situação | Resposta | Estado |
|---|---|---|---|
| F1 | Grupo inexistente, alheio ou excluído | 404 | Nada muda. |
| F2 | Alvo não é membro | 404 | Nada muda. |
| F3 | Não criador tenta remover outro | 403 | Nada muda. |
| F4 | D3 bloqueia | 422 com `message` e `blocking` | Nada muda; a tela mostra a mensagem. |
| F5 | `Notifier` falha ao avisar o removido | 200 | Remoção feita; falha só no log (`guard`). |
| F6 | Dois últimos membros saem ao mesmo tempo | 200 para ambos | Grupo marcado como excluído uma vez só (D5-A, idempotente). |
| F7 | Mesmo usuário repete o `DELETE` | 404 (já não é membro) | Nada muda. |

### 3.6 Interface (web)

- `GroupMembersForm`: botão **Sair do grupo** para o usuário logado e, se ele é o criador (comparando `creator.id` com o id de `GET /api/me`), **Remover** nas linhas dos outros membros.
- Diálogo de confirmação com texto fixo: sair **não apaga** suas despesas e dívidas neste grupo; quem for credor continua vendo o que você deve.
- Erro 422: mostrar a `message` da API. Depois de sair, ir para `/meus-grupos`; depois de remover, recarregar a lista.
- Sem tela nova e sem rota nova no frontend.

## 4. Riscos

| # | Risco | Efeito | Tratamento |
|---|---|---|---|
| RK1 | Vítima presa por bloqueio da D3 (credor ou fixa) | A armadilha original volta pela porta dos fundos | D3-B reduz ao mínimo; a correção completa é a D1-B (candidato a backlog). Documentado como limite conhecido. |
| RK2 | `update()` recusa despesa com ex-membro | Edição de despesa antiga dá 422 | R6 e teste dedicado. |
| RK3 | Painel pessoal mostra "Desconhecido" para o devedor que saiu | Credor não sabe quem deve | D6 (recomendada B) e testes no painel pessoal; teste de regressão no fechamento. As telas de acerto do grupo têm o mesmo efeito (§2.9): R11 e TASK-560. |
| RK4 | Notificação nova sem texto no frontend | Cai em "Nova notificação" | Entrada em `notificationText.ts` na mesma task. |
| RK5 | Limite de grupos criados | Criador que sai perde a vaga | D4-A altera a contagem, com teste. |
| RK6 | Item 095 (qualquer membro apaga o grupo em cascata) | Um membro pode apagar o grupo de quem ficou | Fora de escopo; segue no backlog. |
| RK7 | `balances` com ex-membro muda a lista de cartões de saldo | Aparece um cartão de quem não é mais do grupo | R11 (`formerMember` só nas entradas novas) e D7 (recomendada B: rótulo "saiu do grupo"). |

## 5. Critérios de aceite

### 5.1 Backend

- **CA-1**: membro sai: `DELETE` 200; some de `GET /groups` e de `GET /me/overview`; a linha do pivô some; despesas, quotas, participantes e fechamentos continuam (contagens iguais antes e depois).
- **CA-2**: não membro, grupo inexistente e grupo excluído recebem o **mesmo** 404 (R1); alvo não membro recebe 404 (R2); repetir o `DELETE` dá 404.
- **CA-3**: criador remove outro membro (200); membro comum tentando remover outro recebe 403; grupo sem `created_by` só permite saída própria.
- **CA-4**: D3-B: credor com parcela não paga de outra pessoa, e participante ou credor (com outros participantes) de despesa fixa ativa, recebem 422 com `blocking` e nada muda; devedor de parcelas, e participante de fixa já interrompida, saem (200). Vale igual quando o criador remove outro membro.
- **CA-5**: `update()` de despesa que tem ex-membro em `payers` ou como credor passa; incluir alguém que nunca esteve na despesa e não é membro continua dando 422.
- **CA-6**: com a D6-B, o painel pessoal mostra o ex-devedor com nome e **sem** e-mail, Pix nem avatar, e o ex-credor segue "Desconhecido" (teste da TASK-432 inalterado); o relatório de fechamento mostra o nome do ex-membro.
- **CA-7**: o removido por outra pessoa recebe `group_member_removed`; quem sai sozinho não gera notificação; falha do `Notifier` não impede o 200.
- **CA-8**: criador que sai libera a vaga do limite de 3 (volta a poder criar) e o grupo dele deixa de contar.
- **CA-9**: o último membro que sai deixa `deleted = true`, sem apagar nenhuma linha.
- **CA-10**: suíte inteira do backend verde e `pint --test` limpo; testes novos em `GroupMemberControllerTest` e nos testes de despesa, painel e fechamento; nenhum teste existente alterado sem motivo.
- **CA-11**: o agent `security-reviewer` rodou na rota e nenhum achado ficou sem tratamento ou item de backlog.
- **CA-16** (R11, TASK-560): com o devedor fora do grupo, `summary.balances` traz o ex-devedor com nome, `avatarUrl` nulo e `formerMember: true`, e o mesmo vale para o ex-credor com outros participantes; toda ponta de `settlements` tem entrada em `balances`; os saldos dos membros, as chaves e a ordem deles não mudam; sem ex-membro o `balances` é idêntico ao de hoje (sem a chave `formerMember`); ex-membro sem dívida no ciclo não aparece; nenhuma consulta a mais.

### 5.2 Frontend

- **CA-12**: o usuário logado vê **Sair do grupo**; só o criador vê **Remover** nas linhas dos outros; confirmação antes de qualquer remoção.
- **CA-13**: 422 mostra a mensagem da API sem sair da tela; sucesso ao sair leva a `/meus-grupos`; sucesso ao remover atualiza a lista.
- **CA-14**: a notificação nova tem texto próprio; `npx tsc --noEmit` limpo e testes Vitest dos arquivos tocados verdes.
- **CA-17** (D7-B, TASK-561): o cartão de saldo do ex-membro mostra o rótulo "saiu do grupo"; os cartões dos membros não mudam; `npx tsc --noEmit` limpo e testes Vitest dos arquivos tocados verdes.

### 5.3 Documentação

- **CA-15**: `docs/sdd/01-specify.md` §3.3 deixa de dizer que o `destroy` não existe; `TASK-017` em `03-tasks.md` vira ponteiro para esta pasta. A **Constitution §2.4** cita esta rota como quebrada: a edição é gate humano, então só é sinalizada ao usuário, nunca feita pela IA.

## 6. Fora de escopo desta feature

- **095**: `GroupController::destroy` apaga o grupo em cascata a pedido de qualquer membro.
- **072**: exclusão de conta (backend, web e página pública).
- **D1-B**: convite pendente com aceite ou recusa (candidato a backlog depois desta feature).
- Notificar o criador quando alguém sai por conta própria, e uma tela de "grupos de que saí".
- Teto de grupos **em que alguém participa** (o item 086 menciona a ausência; esta feature não cria o teto).
- Aplicar a mesma saída no app Flutter (`app/` ainda não existe).
- Reescrever o `balances` de snapshots já selados (guardam o saldo do momento do fechamento).
- Trocar a consulta `formerDebtors()` do painel pessoal (TASK-554, concluída) por leitura de `balances`: simplificação possível depois da TASK-560, não requisito.
