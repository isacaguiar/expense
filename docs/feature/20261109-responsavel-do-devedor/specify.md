# Specify — Responsável do devedor

> Feature: um membro de um grupo pode indicar **nenhum, um ou vários outros membros para pagar por ele**. Hoje só o devedor envia o comprovante do próprio acerto do ciclo; com o vínculo, quem foi indicado também pode enviá-lo em nome do devedor, e o credor vê quem pagou. Pedido novo do usuário (conversa de 2026-11-09), sem item prévio em `docs/sdd/03-tasks.md` nem em `docs/backlog/`.

Versão: 1.2 · Criado em: 20261109

**v1.2 (2026-11-10):** a revisão de segurança (BAIXA-3) levou a um **teto de 50 pessoas por lista** no `PUT .../responsibles` (422 com `Escolha no máximo 50 pessoas.`). Muda a R1 e a linha da rota em §3.3; nada mais.

**v1.1 (2026-11-09):** a **D3** passa de A (um responsável) para **B (nenhum ou vários)**, a pedido do usuário, e a D2-A (por grupo) foi reconfirmada. Mudam R1, R3, R4, R8, R11, R12, R13, o contrato (um único `PUT .../responsibles` com a lista inteira, sem `DELETE`), F1 a F3, F5, F9, a interface e os CA-1 a CA-3, CA-10 e CA-11. Nenhuma outra decisão mudou.

**Origem das decisões:** as D1 a D8 (§3.4) foram propostas e aprovadas pelo usuário na conversa de 2026-11-09 ("segue com as recomendadas"). Depois disso ele **trocou a D3 de A para B** (nenhum ou vários responsáveis) e reconfirmou a D2-A (por grupo). A D9 e a D10 são novas, surgiram ao redigir este documento e foram **aprovadas como recomendado** (A e A) em 2026-11-09, na resposta "siga".

**Vocabulário.** No código, `ex_expenses.user_payer_id` é o **credor** (quem pagou e tem a receber), e `ex_expenses_payers` lista os **participantes** que dividem o valor (os devedores). Neste documento, "responsável" é quem pode pagar por um devedor (`responsible` no código). Na **interface** o texto é "Quem paga por você" (D8), porque "Responsável" já designa o criador do grupo (§2.7).

---

## 1. Problema

O devedor é o único que registra o pagamento do próprio acerto: `confirmSettlement` fixa o usuário autenticado como devedor. Quando ele não pode ou não quer fazer isso (outra pessoa da casa cuida das contas, um pai paga pelo filho), o acerto fica sem confirmação, aparece como pendente para o credor e o ciclo não sela. O pedido: um devedor poder ter **responsáveis** (nenhum, um ou vários) que também possam efetuar esse pagamento.

### 1.1 Verificação prévia

Li o código listado abaixo, sem rodar nada: o achado é de leitura, e os testes existentes já fixam o comportamento atual. Nenhum fluxo foi reproduzido em navegador.

- Backend: `ExpenseController` (`pay`, `confirmSettlement`, `sealCycleIfSettled`), `CycleSummaryService` (`compute`, `withConfirmations`, `summaryIsFullySettled`), `GroupMemberController` (`index`, `destroy`), `Controller::authorizeGroupMembership`, `Notifier`, `WhatsAppNotifier::settlementProofConfirmed`, `PixController`, os models `Group`, `User`, `SettlementConfirmation` e `GroupInvitation`, as migrations de `ex_settlement_confirmations`, `ex_groups_members`, `ex_group_invitations`, `ex_notifications` e `ex_users`, e `routes/api.php`.
- Testes: `SettlementConfirmationControllerTest`, `GroupMemberControllerTest`.
- Frontend: `GroupMembersForm`, `Payments`, `PaymentSettlementsPanel`, `PayableSettlementList`, `GroupPositionCard`, `useConfirmSettlement`, `utils/cycleOrdering`, `notificationText` e `types/group`.
- Documentação: a Constitution (§1, §4, §5.2, §6) e o specify e o tasks da feature `20261103-convite-pendente-no-grupo`, que deu o precedente de estilo e de modelagem.

## 2. Achados confirmados

### 2.1 O pagamento do devedor é um único endpoint, e o devedor é `auth()->id()`

`confirmSettlement` (`backend/app/Http/Controllers/ExpenseController.php:1736-1828`) procura o acerto com `from_user_id === auth()->id()` (`:1769`), monta a chave da confirmação com `from_user_id => auth()->id()` (`:1778`) e grava com `updateOrCreate` (`:1787`). Já `pay()` (`:1417-1423`) é o "recebi" do credor e responde 403 a quem não é o credor; esse fluxo **não muda**.

### 2.2 A confirmação é auto-declarada e conta para selar o ciclo

O credor não confere o recebimento (o próprio front registra isso em `GroupPositionCard.tsx:98-101`). Confirmar o último acerto pendente sela o ciclo (`sealCycleIfSettled`, `ExpenseController.php:1801`; regra em `CycleSummaryService::summaryIsFullySettled`, `:405-431`). Consequência de segurança: **quem pode confirmar por outra pessoa tem de ter o consentimento dela**, senão um membro poderia selar o ciclo de outro sem pagamento. É o motivo da D1.

### 2.3 O acerto é do par devedor→credor, e a confirmação é chaveada por esse par

`compute` neta cada par (`CycleSummaryService.php:293-325`), e a confirmação tem unicidade `settlement_confirmation_unique` em `group_id + cycle_start + from_user_id + to_user_id` (migration `2026_08_25_220000_create_ex_settlement_confirmations_table`); `withConfirmations` as cruza pelo par (`:362-384`). Manter `from_user_id` igual ao **devedor** preserva netting, selo, snapshots e o Painel pessoal sem tocar neles. Por isso o desenho **não consolida saldos** no responsável: só registra quem enviou (R7).

### 2.4 O Pix já não exige que quem gera seja o devedor

`PixController::gerarPix` libera o Pix do credor a qualquer co-membro do grupo (`:20-22`, `:54-59`). O botão "Pagar com Pix" do responsável funciona sem mudança no backend.

### 2.5 O front prende os botões ao devedor

`PayableSettlementList.tsx:68` só trata como devedor quem tem `currentUserId === from_user_id`, e os botões e o aviso de ciclo aberto dependem disso (`:146-171`). `Payments.tsx` já carrega `members` (`:57-69`) e monta o `POST` do comprovante à mão (`:158-187`). `orderSettlementsForViewer` (`utils/cycleOrdering.ts`) põe primeiro só as saídas do usuário logado.

### 2.6 A tela de Participantes não tem onde pôr o vínculo, e `GET /members` não o devolve

Cada linha só tem "Sair do grupo" ou, para o criador, "Remover" (`GroupMembersForm.tsx:288-296`). `GroupMemberController::index` devolve `$group->members`, models `User` puros (`:25`).

### 2.7 A palavra "Responsável" já é do criador do grupo na interface

`GroupMembersForm.tsx:264`, `Dashboard.tsx:195` e o cabeçalho de coluna `Dashboard.tsx:270`, com testes que checam o texto (`Dashboard.test.tsx:155` e `:520`, `GroupMembersForm.test.tsx:46`). Dois sentidos na mesma tela confundem; daí a D8.

### 2.8 A saída de membro só faz `detach` e deixaria vínculo órfão

`GroupMemberController::destroy` tira só a linha de `ex_groups_members` (`:117`) e mantém o resto. Sem limpeza própria, o vínculo continuaria apontando para quem saiu (R12).

### 2.9 Tipo de notificação novo não exige migration

`ex_notifications.type` é `string` (`create_ex_notifications_table`, `:20`), e o texto da notificação mora no cliente (`notificationText.ts`).

### 2.10 O aviso de WhatsApp ao credor tem um template de três parâmetros

`WhatsAppNotifier::settlementProofConfirmed` envia nome do devedor, valor e competência (`:84-91`). Acrescentar "quem pagou" é mudar o template; fica fora de escopo (RK5).

### 2.11 Os testes existentes absorvem a mudança sem edição

`GroupMemberControllerTest` afirma `assertJsonStructure` e `assertJsonCount` sobre os membros (`:126`, `:179`, `:201`), nunca JSON exato da lista: o campo novo `responsibles` é aditivo. `SettlementConfirmationControllerTest` cobre `confirmSettlement` (28 testes) e continua valendo sem `from_user_id`.

## 3. Requisitos

### 3.1 O que a feature entrega

- Um membro indica **nenhum, um ou vários outros membros do mesmo grupo** como quem paga por ele, e muda essa lista quando quiser.
- Cada indicado pode, na tela de Pagamentos, **pagar com Pix e enviar o comprovante** dos acertos do devedor, no ciclo fechado, como o próprio devedor faria.
- O acerto continua sendo do devedor; o sistema registra **quem enviou** o comprovante, e o credor vê isso.
- Cada indicado é avisado ao ser escolhido; o credor é avisado "por quem" foi pago.
- Web: Participantes ganha o vínculo; Pagamentos mostra os acertos de quem o usuário paga; sino com textos novos.

### 3.2 Regras adotadas

Não dependem de decisão; as que dependem estão em §3.4.

| # | Regra |
|---|---|
| R1 | O vínculo é **(grupo, devedor, responsável)**. Cada devedor tem, **em cada grupo**, **nenhum, um ou vários** responsáveis, até **50 por lista** (teto da validação, BAIXA-3) e sempre membros do grupo (D2, D3-B). |
| R2 | **Só o próprio devedor** define, troca ou desfaz o seu vínculo (D1). Qualquer outro usuário, inclusive o criador do grupo, recebe 403. |
| R3 | Cada responsável tem de ser **membro do grupo** e **diferente do devedor**, e a lista não pode repetir ninguém; senão 422 com mensagem e **nada** é alterado. |
| R4 | O `PUT` **substitui a lista inteira** (a lista vazia desfaz tudo) e vale na hora: quem sai da lista perde o direito imediatamente, quem entra ganha, e quem continua não é afetado (a linha do vínculo dele não é recriada). |
| R5 | O responsável ganha **uma única permissão**: enviar o comprovante dos acertos do devedor. Não marca despesa como paga (`pay()` segue só do credor), não edita nem remove o vínculo e não vê nada que já não veja como membro. |
| R6 | `POST /groups/{id}/settlements/confirm` aceita `from_user_id` opcional. Se for diferente de quem chama, exige o vínculo **(este grupo, `from_user_id`, quem chama)**; sem ele, 403, **antes** de gravar arquivo ou linha. Sem `from_user_id`, ou igual ao de quem chama, o comportamento é o de hoje. |
| R7 | O acerto **continua do devedor**: `from_user_id` da confirmação é o devedor, e a coluna nova `confirmed_by` guarda quem enviou (nulo = o próprio devedor, e todas as linhas de hoje). Netting, selo, snapshots, Painel e `/me/overview` não mudam. |
| R8 | Reenviar, por qualquer pessoa autorizada (o devedor ou um responsável), **substitui** foto, nota e `confirmed_by` (o último vence), como o "reenviar substitui" de hoje; a foto anterior sai do disco. |
| R9 | Todo o resto da regra de hoje vale para o responsável: só competência fechada e não selada, comprovante obrigatório, nota opcional de até 500 caracteres, valor igual ao líquido do devedor para o credor. |
| R10 | O resumo traz `settlements[].confirmedBy` = `{id, name}` **só quando difere do devedor**, senão `null`. A notificação `settlement_confirmed` do credor ganha `data.paidByName` só nesse caso (D5). |
| R11 | Ao ser escolhido, **cada responsável novo** recebe a notificação in-app `payment_responsible_assigned`, com `group_id` (ele é membro e pode abrir o grupo). Quem já estava na lista **não** é avisado de novo, e quem sai da lista não é avisado. |
| R12 | Os vínculos **somem** quando qualquer ponta sai ou é removida do grupo, na mesma transação de `GroupMemberController::destroy` (as duas direções: como devedor e como responsável). |
| R13 | `GET /groups/{id}/members` passa a trazer `responsibles` (lista de `{id, name}`, vazia quando não há) em cada membro. Todos os membros veem os vínculos: o credor precisa saber quem pode pagar por quem. Só `id` e `name`, nunca e-mail, Pix ou contato do responsável. |
| R14 | Responsável que **também é o credor** do acerto: o backend aceita (sem efeito financeiro) e a interface **não oferece** os botões, porque ele já é quem recebe. |
| R15 | Falha do `Notifier` não desfaz o vínculo nem a confirmação (best effort, `Notifier::guard`). |
| R16 | Acerto de quem **já saiu** do grupo segue sem quem o confirme: o vínculo some na saída (R12), então ninguém pode pagar por ele. É o comportamento de hoje. |

### 3.3 Contrato da API (proposta; o Plan fecha os nomes)

Rota nova e campos novos, todos aditivos, sob `jwt.auth`.

| Rota | Quem | Efeito |
|---|---|---|
| `PUT /groups/{groupId}/members/{userId}/responsibles` | o próprio devedor (`userId` = quem chama) | corpo `{responsible_user_ids: [...]}`: a lista **inteira** (vazia = nenhum), que substitui o conjunto; 200 `{message, responsibles: [{id, name}]}`; 403 se `userId` não é quem chama; 404 se não é membro ou o grupo foi excluído; 422 se algum responsável não é membro, é o próprio devedor, repete, passa de 50 pessoas ou o corpo não traz a lista (nada é alterado) |
| `GET /groups/{id}/members` (existente) | membro | cada membro ganha `responsibles` (lista) |
| `POST /groups/{id}/settlements/confirm` (existente) | devedor ou seu responsável | ganha `from_user_id` opcional |
| `summary`, `close`, `reopen` (existentes) | membro | `settlements[]` ganha `confirmedBy` |

- **Notificações:** `payment_responsible_assigned` (nova, a cada responsável novo) e `settlement_confirmed` com `data.paidByName` opcional (ao credor). As já gravadas continuam com o texto de hoje.
- **Migrations (2), ambas aditivas:** tabela nova `ex_group_member_responsibles` e coluna nula `confirmed_by` em `ex_settlement_confirmations`. Constitution §4, item 2: sem `drop`, `rename` nem alterar coluna existente. **Gate humano antes de rodar em banco compartilhado ou produção**, e atenção: o deploy aplica as migrations ao mergear em `main` (ADR-008).
- **Constitution §4, item 1:** só campos e rotas novos, nenhum contrato existente muda de sentido. O único consumidor é a web, atualizada no mesmo PR; o app Flutter (`app/`) ainda não existe.

### 3.4 Decisões

| # | Decisão | Opções | Resultado | Por quê | Status |
|---|---|---|---|---|---|
| D1 | Quem designa o responsável | A) o próprio devedor<br>B) o responsável propõe e o devedor aceita<br>C) o criador do grupo | **A** | Dá consentimento sem criar fluxo de aceite. A C reabre o risco de selar ciclo sem pagamento (§2.2). | Aprovada em 2026-11-09 |
| D2 | Escopo do vínculo | A) por grupo<br>B) na conta inteira | **A** | O responsável só age onde também é membro, e a regra de acesso do grupo não muda. | Aprovada em 2026-11-09 e reconfirmada pelo usuário no mesmo dia |
| D3 | Quantos responsáveis por devedor | A) um<br>B) nenhum ou vários | **B** | Pedido do usuário em 2026-11-09, que **trocou** a A aprovada antes: o devedor pode não ter ninguém ou ter mais de uma pessoa. Decidir agora poupa mudar o formato da API depois (`responsible` virar `responsibles`). Custo pequeno: o índice único passa a incluir o responsável e o `PUT` substitui a lista. | Decidida pelo usuário em 2026-11-09 (substitui a A) |
| D4 | Onde guardar | A) tabela nova<br>B) coluna no pivô `ex_groups_members` | **A** | Guarda as datas de criação e de troca e deixa de fora o pivô que todo `authorizeMembership` lê, o mesmo critério da D1 da feature de convites. Escolha apertada: a coluna é um pouco menor. | Aprovada em 2026-11-09 |
| D5 | O que o credor vê | A) "enviado por X"<br>B) nada | **A** | A prova é auto-declarada (§2.2); a transparência compensa. | Aprovada em 2026-11-09 |
| D6 | Alcance da primeira entrega | A) só Pagamentos do grupo<br>B) também o Painel pessoal e `/me/overview` | **A** | `GroupPositionCard` e `/me/overview` assumem o usuário logado como devedor de tudo (`GroupPositionCard.tsx:106-112`). Fica para um segundo passo. | Aprovada em 2026-11-09 |
| D7 | Quem vê o botão de escolher | A) só o próprio devedor<br>B) também o criador, em todas as linhas | **A** | Decorre da D1-A. | Aprovada em 2026-11-09 |
| D8 | Texto na interface | A) "Quem paga por você"<br>B) "Responsável" | **A** | Evita a colisão com o criador do grupo sem mexer em tela e testes que já existem (§2.7). | Aprovada em 2026-11-09 |
| D9 | Avisar o devedor quando o responsável paga | A) não<br>B) sim, com uma notificação nova | **A** | O devedor escolheu o responsável e vê o card "enviado por X" em Pagamentos. A B acrescenta um tipo de notificação por um ganho pequeno. | Aprovada em 2026-11-09 (resposta "siga" às recomendadas) |
| D10 | Desfazer o vínculo apaga a linha | A) `DELETE` físico do vínculo<br>B) marcar `ended_at` e manter a linha | **A** | O vínculo é relação, não dado de negócio: o mesmo critério do `detach` do pivô na feature 086 (`01-specify.md` §3.2, "exclusão física do vínculo"). O histórico que importa fica em `confirmed_by`. A B complica o índice único e a re-designação. **Atenção:** Constitution §1.5 e §5.2 reservam a exclusão definitiva de registro de negócio ao gate humano; a aprovação deste specify é esse gate. | Aprovada em 2026-11-09 (resposta "siga" às recomendadas) |

### 3.5 Falhas e consistência

| # | Cenário | Tratamento |
|---|---|---|
| F1 | Dois `PUT` simultâneos do mesmo devedor | Transação com lock do grupo e índice único por (grupo, devedor, responsável): o segundo espera, o último vence com a lista inteira dele, sem 500 e sem linha duplicada |
| F2 | Um dos escolhidos sai do grupo enquanto o devedor monta a lista | O `PUT` trava o grupo (o mesmo lock de `destroy`) e revalida **todos** como membros dentro da transação; se algum saiu, recebe 422 e **nada** muda |
| F3 | Devedor e um ou mais responsáveis enviam o comprovante do mesmo acerto quase juntos | `updateOrCreate`: o último vence (R8); fica um arquivo só, o anterior é apagado |
| F4 | Responsável adultera `from_user_id` para pagar por quem não é | 403 antes de gravar arquivo e linha (R6) |
| F5 | O responsável é tirado da lista entre abrir o diálogo e enviar | 403 no envio; a tela mostra a falha genérica de envio ("Falha ao enviar o comprovante."), porque `Payments` lê só `data.error` |
| F6 | Grupo excluído | 404 em todas as rotas novas, como `destroy` hoje |
| F7 | Falha do `Notifier` | Não desfaz nada (R15) |
| F8 | Conta apagada | FK em cascata remove o vínculo; `confirmed_by` vira nulo (`nullOnDelete`) e a confirmação fica |
| F9 | O responsável é tirado da lista depois de ter pago | A confirmação e o `confirmed_by` permanecem; o card segue mostrando "enviado por X" |

### 3.6 Interface (web)

O mockup desta tela foi mostrado na conversa de 2026-11-09 e **não está versionado**; o que vale é a descrição abaixo. Os rótulos de botão que já existem **não mudam**.

- **Participantes** (`GroupMembersForm.tsx`):
  - Na linha do **próprio usuário**: botão **Quem paga por mim**, antes de "Sair do grupo"; abaixo do e-mail, `Quem paga por você: {nome}, {nome}` (nomes separados por vírgula) quando há vínculo.
  - Na linha de **outro membro** com vínculo: `Quem paga por {nome do membro}: {nomes dos responsáveis}`.
  - Na linha de **quem paga por alguém**: um selo `Paga por {nome do devedor}` por dependente.
  - **Diálogo** "Quem pode pagar por você": texto "As pessoas escolhidas poderão enviar o comprovante dos seus pagamentos neste grupo, além de você. Elas não passam a ver nada que não vejam hoje como membros.", **seletor múltiplo** "Quem paga por você" com os outros membros (nome e e-mail) e os escolhidos como selos, botões **Salvar** e **Cancelar**. Não há botão de desfazer: **esvaziar o seletor e salvar** desfaz tudo. Sem outros membros: "Não há outros membros neste grupo ainda. Convide alguém para poder escolher." Sucesso: `Escolha salva.` (lista com gente) ou `Escolha desfeita.` (lista vazia).
  - O diálogo de sair ou remover ganha uma frase quando o alvo paga por alguém: `{e-mail} também paga por {nomes}; essa escolha será desfeita.`
- **Pagamentos** (`PayableSettlementList.tsx`, `Payments.tsx`):
  - Nos acertos de quem o usuário paga: selo `Você paga por {nome do devedor}` e os botões de hoje (**Pagar com Pix**, **Enviar comprovante** ou **Reenviar comprovante**), com o aviso "Disponível após o fechamento do ciclo." valendo igual.
  - Sem botões quando o usuário é quem **recebe** o acerto (R14) e para os acertos de terceiros.
  - Acerto confirmado por outra pessoa: o selo "Comprovante enviado" ganha o texto `Enviado por {nome}` (R10).
  - Ordem: as saídas do usuário, depois as de quem ele paga, depois as demais.
  - O `POST` leva `from_user_id` **só** ao pagar por um dependente.
- **Sino** (`notificationText.ts`): `Você agora pode pagar por {actorName} no grupo "{groupName}"` (`payment_responsible_assigned`) e, em `settlement_confirmed` com `paidByName`, `{paidByName} confirmou um pagamento de {valor} por {actorName}`.
- Sem rota nova de página no frontend.

## 4. Riscos

| # | Risco | Efeito | Tratamento |
|---|---|---|---|
| RK1 | IDOR no `from_user_id`: pagar por quem não se é responsável | Confirmação em nome de outra pessoa | R6, F4, matriz de autorização nos testes e revisão do `security-reviewer` |
| RK2 | A confirmação é auto-declarada | Um responsável mal-intencionado, ou uma conta invadida, sela o ciclo sem pagar | D1-A (só o devedor escolhe), D5 (o credor vê quem enviou) e `confirmed_by` auditável. Limite conhecido: o credor não confere o recebimento, como hoje (§2.2) |
| RK3 | As migrations rodam no deploy | Tabela e coluna em produção sem aval | Migrations aditivas; gate humano antes de banco compartilhado; atenção ao ADR-008 no PR de promoção |
| RK4 | O responsável é escolhido sem aceitar | Recebe uma permissão que não pediu | A permissão é só pagar (R5) e ele é avisado (R11); recusar ou largar o papel fica fora de escopo |
| RK5 | O WhatsApp ao credor segue citando só o devedor | O aviso não diz que outra pessoa pagou | O texto continua verdadeiro (o acerto do devedor foi confirmado); o in-app e o card dizem quem enviou; template novo fica fora de escopo (§2.10) |
| RK6 | Colisão do termo "Responsável" | Dois sentidos na mesma tela | D8: nenhum rótulo novo usa a palavra sozinha |
| RK7 | O Painel e `/me/overview` não enxergam quem paga por quem | O responsável não vê os acertos do dependente ali, só em Pagamentos do grupo | D6; candidato a backlog |
| RK8 | Mexer em `destroy` de membro (feature 086, recém-entregue) | Regressão na saída e remoção | Mudança mínima dentro da transação existente; os testes de saída e remoção existentes seguem sem edição |
| RK9 | Apagar a linha do vínculo | Exclusão definitiva sem gate (Constitution §1.5, §5.2) | D10 explícita e aprovada (A) |

## 5. Critérios de aceite

### 5.1 Backend

- **CA-1**: `PUT .../members/{userId}/responsibles` substitui a lista (200): dois responsáveis gravam duas linhas; um segundo `PUT` com outra lista tira quem saiu, acrescenta quem entrou e **mantém** a linha de quem ficou; a lista vazia desfaz tudo; 403 para quem não é o próprio devedor, inclusive o criador do grupo; 404 para não membro e grupo excluído; 422, **sem alterar nada**, para responsável que não é membro, que é o próprio devedor, repetido, com id inválido, ou corpo sem a lista.
- **CA-2**: o `PUT` com lista vazia remove todos os vínculos do devedor naquele grupo e não notifica ninguém; repetir o mesmo `PUT` não muda nada; `DELETE` na mesma rota dá 405.
- **CA-3**: `GET /members` traz `responsibles` (lista de `{id, name}`, vazia quando não há) e nunca e-mail, Pix ou contato do responsável.
- **CA-4**: `confirmSettlement` com `from_user_id` do dependente, enviado pelo responsável, devolve 200; a linha tem `from_user_id` do dependente e `confirmed_by` do responsável; o credor é notificado com `paidByName`; o selo do ciclo funciona como hoje.
- **CA-5**: sem vínculo, com vínculo de **outro grupo**, com vínculo **desfeito** ou de **outro devedor**, o mesmo `POST` dá 403, sem arquivo gravado e sem linha criada.
- **CA-6**: sem `from_user_id`, ou igual ao de quem chama, o comportamento é o de hoje e `confirmed_by` fica nulo; os 28 testes de `SettlementConfirmationControllerTest` passam sem edição.
- **CA-7**: reenvio por outra pessoa substitui foto, nota e `confirmed_by`; a foto anterior sai do disco.
- **CA-8**: `summary`, `close` e `reopen` trazem `confirmedBy` só quando o remetente difere do devedor.
- **CA-9**: sair ou ser removido apaga o vínculo nas duas direções, na transação de `destroy`.
- **CA-10**: `payment_responsible_assigned` vai a cada responsável **novo** da lista, uma vez, com `group_id` e `groupId`; quem já estava e quem saiu não recebem; falha do `Notifier` não desfaz o vínculo.
- **CA-11**: dois `PUT` simultâneos não dão 500 nem duplicam linha (o último vence); um `PUT` concorrente com a saída de um dos escolhidos não deixa vínculo com quem saiu.
- **CA-12**: `pint --test` e a suíte inteira do backend verdes; as duas migrations só aditivas; o `security-reviewer` rodou na rota nova e na mudança do `confirmSettlement`.
- **CA-13**: nenhum teste que já existia é editado (§2.11).

### 5.2 Frontend

- **CA-14**: Participantes mostra o botão **só** na linha do próprio usuário; o diálogo salva e desfaz; os textos secundários, os selos e a frase extra da remoção aparecem como em §3.6.
- **CA-15**: Pagamentos mostra o selo e os botões nos acertos de quem o usuário paga, e **não** nos de terceiros nem nos que ele recebe; o `POST` leva `from_user_id` só ao pagar por dependente; "Enviado por {nome}" aparece quando há `confirmedBy`.
- **CA-16**: o sino tem os textos novos.
- **CA-17**: `npx tsc --noEmit` limpo e os testes Vitest dos arquivos tocados verdes; a suíte inteira só vale com `--maxWorkers=1` nesta máquina, conferindo que rodaram todos os arquivos.

### 5.3 Documentação

- **CA-18**: `docs/sdd/01-specify.md` descreve o vínculo e o `confirmed_by` onde trata membros e acertos. A Constitution, se algo nela ficar desatualizado, só é sinalizada ao usuário, nunca editada.

## 6. Fora de escopo desta feature

- O Painel pessoal (`GroupPositionCard`) e `/me/overview` para o responsável (D6): candidato a backlog.
- **Dependente sem conta** (sem e-mail e login): hoje todo membro é um `ex_users` com e-mail único e obrigatório; seria outra feature, bem maior.
- Escolher o responsável uma vez na conta, valendo em todos os grupos em comum (D2-B).
- Avisar o devedor quando o responsável paga (D9-B).
- O responsável recusar ou largar o papel sozinho.
- O texto do WhatsApp ao credor: template fixo de três parâmetros (§2.10).
- O responsável marcar despesa como paga ou recebida: `pay()` segue só do credor.
- Consolidar saldos ou mudar o netting (§2.3).
- Manual (`docs/manual`) e site: só a pedido do usuário, como foi na feature do convite pendente.
- O app Flutter (`app/` ainda não existe).
