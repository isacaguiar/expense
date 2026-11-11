# Specify — Quem paga se declara e o credor recusa o comprovante

> Feature: quem paga por outra pessoa poder **se declarar** responsável por ela, **sem aceite do devedor** (caso da criança que não abre o app), com o **credor** podendo recusar um comprovante indevido enquanto o ciclo não está encerrado. Origem: backlog **144**, pedido dele em 2026-11-10 durante a validação local de `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/`; agrupa os itens **141** e **143**, que mexem nos mesmos avisos.

Versão: 1.1 · Criado em: 20261110

**v1.1 (2026-11-10):** aprovado por ele ("aprovado, siga com as recomendadas"). **D2, D3, D4-B, D6 e D8 a D12** como recomendado; **D5** com a leitura confirmada ("ainda não fechado" = ciclo **não encerrado**, isto é, não selado); **D7-A aprovada, com o aval de hard delete** (exclusão física da linha e do arquivo, só do **próprio** comprovante, enquanto não aceito e com o ciclo não encerrado). A D1 já era dele.

---

## 1. Problema

Na feature anterior ("quem paga por quem") só o **devedor** escolhe quem paga por ele (D1-A e R2): o vínculo é uma permissão que ele dá, e é isso que impede um membro de mandar comprovante em nome de outro. Ao validar, ele pediu o caminho inverso: **quem paga poder declarar "eu pago por Ana e Davi"**, sem a confirmação do dependente. O caso que motiva é uma criança que não acessa o app: ela nunca aceitaria um pedido, e um vínculo "propõe e aceita" (a D1-B descartada na feature anterior) ficaria pendente para sempre.

Declarar sem consentimento reabre o risco que a D1-A fechava: um membro se declarar pagador de outro e enviar um comprovante falso (RK2 da feature anterior). A rede de segurança que ele propôs é o **credor poder recusar o pagamento** de um comprovante indevido, devolvendo-o a "pendente", para o pagador corrigir ou excluir, até o credor aceitar. A regra dele: **a recusa só funciona em ciclo que ainda não foi encerrado**.

### 1.1 Verificação prévia

- O ponto de partida é a feature já em produção desde a `v1.4.0`: tabela `ex_group_member_responsibles`, coluna `ex_settlement_confirmations.confirmed_by`, `PUT /groups/{id}/members/{userId}/responsibles`, o diálogo "Quem paga por mim", o selo "Você paga por {nome}" e a legenda "Enviado por {nome}".
- **Interpretação (D5, confirmada por ele em 2026-11-10):** na tela, "Ciclo fechado" já é o estágio em que se envia comprovante (`confirmSettlement` recusa ciclo aberto, ver §2.5), então "ainda não foram fechados" só pode significar "ainda não **encerrados**" (selados). A leitura literal (antes de fechar) tornaria a recusa impossível, porque ainda não há comprovante.
- Os itens **141** (alternar a lista reavisa a mesma pessoa) e **143** (responsável que também é o credor avisa a si mesmo) entram por mexerem nos mesmos avisos: o aviso novo ao devedor herdaria o defeito do 141.

---

## 2. Achados confirmados

Cada item foi conferido no código desta branch (`dev` em `3beed8f915`).

### 2.1 Só o devedor define o vínculo
`GroupMemberResponsibleController::update` recusa com 403 quem não é o próprio devedor (`abort_unless(... === (int) auth()->id(), 403)`, `:36`), e a única rota é `PUT /groups/{groupId}/members/{userId}/responsibles` (`routes/api.php:65`). `MemberResponsibleService::replace` (`:32`) troca a lista **inteira** do devedor sob `lockForUpdate` do grupo; `clearFor` (`:94`) apaga os vínculos nas duas direções na saída do membro; `canPayFor` (`:102`) é o que `confirmSettlement` consulta.

### 2.2 A confirmação do comprovante é auto-declarada e conta para selar
`SettlementConfirmation` é uma linha por (grupo, ciclo, devedor, credor). `CycleSummaryService::summaryIsFullySettled` (`:415-442`) considera o ciclo quitado quando `totals.pending == 0` **e** existe uma confirmação para cada par de `settlements`; **qualquer** confirmação basta, não importa quem a enviou. É o que a feature anterior chamou de RK2.

### 2.3 O credor não tem nenhuma ação sobre o comprovante
A única rota de acerto é `POST /groups/{groupId}/settlements/confirm` (`routes/api.php:81`), que cria ou reenvia. O credor só tem `pay` e `unpay` **por despesa** (`ExpenseController.php:1418` e `:1518`), que mexem na parcela, não no comprovante. Na tela, o card do credor só mostra o selo "Pagou a você" ou "Deve pagar a você" (`frontend/src/components/PayableSettlementList.tsx:124-131`) e a legenda "Enviado por".

### 2.4 O reenvio substitui o comprovante e apaga o arquivo anterior
`confirmSettlement` faz `updateOrCreate` pela chave do par e, depois de gravar, `ProofStorage::delete($previousProofPath)` (`ExpenseController.php:1801-1819`). Hoje **nenhum código apaga uma linha** de `ex_settlement_confirmations` (conferido: só o model cita a tabela).

### 2.4.1 O reenvio não olha quem enviou nem se já foi aceito
Como não existe "aceito", qualquer membro autorizado (o devedor ou um responsável vinculado) reenvia a qualquer momento até o ciclo selar; o último a enviar vence (R8 da feature anterior).

### 2.5 Estados do ciclo e onde cabe a recusa
`confirmSettlement` só aceita ciclo **fechado** (por data ou manualmente) e recusa ciclo selado com 422 "Esta competência já foi encerrada." (`ExpenseController.php:1767-1782`). O ciclo é selado em `sealCycleIfSettled` (`:1908`), logo depois de gravar a confirmação ou marcar a última parcela, e `unsealIfBroken` (`:1963`) o reabre quando algo deixa de estar quitado (hoje só `unpay`, `:1556`). A janela da recusa é, portanto, **ciclo fechado e ainda não selado**, e o selo é a transição que a fecha: se o último comprovante sela o ciclo na hora, o credor não teria janela. Por isso a D6.

### 2.6 Item 141: alternar a lista reavisa a mesma pessoa
O controller chama `Notifier::paymentResponsibleAssigned` para cada id em `$result['added']` (`GroupMemberResponsibleController.php:56-58`). `PUT []` seguido de `PUT [R]` faz R sair e voltar, e a cada volta recebe um aviso novo. Não há janela de silêncio; só o limiter `api` (60 por minuto) segura o ritmo.

### 2.7 Item 143: o autor não é pulado
`Notifier::fanOut` grava uma linha por destinatário sem olhar o autor (`Notifier.php:306-316`). `settlementConfirmed` avisa o credor (`:281-298`) e o WhatsApp ao credor sai sempre que a confirmação é gravada (`ExpenseController.php:1840-1850`). Se quem enviou é o próprio credor, ele recebe aviso da ação que acabou de fazer. Só acontece por chamada direta à API: a interface não oferece os botões ao recebedor do acerto (`PayableSettlementList.tsx:84`, `canPayFor` exige `!isReceiver`).

### 2.8 Exclusão física exige gate humano
A Constitution §1.5 e a linha 105 proíbem `DELETE` físico de registro de negócio sem o gate humano de hard delete (§5.2). Um comprovante de acerto é registro financeiro. Por isso a recusa (D4) e a exclusão pelo pagador (D7) têm uma opção compatível e uma que precisa do aval dele, como a D10 da feature anterior. **Decisão dele (2026-11-10):** a recusa **não** apaga nada (D4-B) e a exclusão pelo pagador (D7-A) **tem o aval de hard delete**, limitado ao próprio comprovante.

### 2.9 Janela de silêncio por cache já tem precedente
`Cache::put` é usado para janelas curtas em produção (`InvitationController.php:106`, 15 minutos; `GoogleAuthController.php`). O convite usa a coluna `notified_at` (`GroupInvitation::RENOTIFY_AFTER_HOURS`), que **não serve** aqui: a linha do vínculo é apagada quando a pessoa sai da lista, então não guardaria a marca entre sair e voltar.

---

## 3. Requisitos

### 3.1 O que a feature entrega

1. **Quem paga se declara.** Em Participantes, o membro marca "eu pago por Ana e Davi" na própria linha. O vínculo vale **sem aceite** do devedor.
2. **O devedor é avisado e pode remover.** Ele recebe um aviso no sino e remove quem se declarou pelo diálogo "Quem paga por mim" que já existe.
3. **Comprovante de responsável fica "aguardando aceite do credor".** Quando `confirmed_by` não é nulo, o comprovante **não conta para selar o ciclo** até o credor aceitar. O comprovante que o próprio devedor envia segue valendo na hora, como hoje.
4. **O credor aceita ou recusa.** Recusar devolve o acerto a "a pagar"; o pagador corrige (reenvia) ou exclui; só em ciclo fechado e ainda não encerrado.
5. **Avisos novos:** ao devedor quando alguém se declara por ele; ao remetente e ao devedor quando o credor recusa; ao remetente quando o credor aceita.
6. **Item 141:** os avisos de vínculo não se repetem para o mesmo par em 24 h.
7. **Item 143:** o credor não recebe aviso (sino nem WhatsApp) de uma confirmação que ele mesmo enviou.

### 3.2 Regras adotadas

| # | Regra |
|---|---|
| R1 | Quem paga adiciona e remove **só a si mesmo** como responsável de outro membro **do mesmo grupo**. Nunca mexe na lista de terceiros. Quem não é membro, ou grupo excluído, recebe 404 (como `GroupMemberResponsibleController`). |
| R2 | O devedor continua escolhendo, trocando e desfazendo a lista dele (`PUT .../responsibles` não muda de contrato). Se ele omitir quem se declarou, o vínculo some. |
| R3 | Cada vínculo guarda **quem o criou** (`created_by`: o devedor ou o responsável), para auditar e para o front distinguir (D3). |
| R4 | O vínculo continua dando **só** o poder de enviar o comprovante em nome do devedor (R5 da feature anterior). Nada de ver dado novo, pagar com dinheiro, nem aceitar ou recusar. |
| R5 | Comprovante com `confirmed_by` não nulo nasce **aguardando aceite**. Só conta em `summaryIsFullySettled` depois de aceito. O do próprio devedor conta como hoje (D6). |
| R6 | Só o **credor do par** (`to_user_id`) aceita ou recusa, e só comprovante com `confirmed_by`. Qualquer outro recebe 403. |
| R7 | Recusar devolve o acerto a pendente e impede o selo. Quem enviou e o devedor podem **reenviar** (substitui, como hoje) ou **excluir** (D7), enquanto não aceito. |
| R8 | **Aceitar é definitivo nesta feature:** depois do aceite o comprovante não pode ser recusado, reenviado nem excluído. |
| R9 | **Ciclo encerrado (selado) é imutável:** aceitar, recusar e excluir dão 422, igual ao reenvio hoje. Esta feature **não** reabre ciclo selado (D5). |
| R10 | Saída ou remoção de membro segue apagando os vínculos nas duas direções; o comprovante que ele enviou fica e o credor ainda pode aceitar ou recusar. |
| R11 | Os dois avisos de vínculo (`payment_responsible_assigned`, que já existe, e o novo) **não se repetem** para o mesmo par (grupo, devedor, responsável) em 24 h; a marca fica no cache, sem migration (item 141, D11). |
| R12 | Sem aviso ao credor (sino e WhatsApp) quando `confirmed_by` é o próprio credor (item 143, D12). |
| R13 | Aviso é best effort: falha vira log e não desfaz a ação (como `Notifier::guard`). |

### 3.3 Contrato da API (proposta; o Plan fecha os nomes)

| Quando | Contrato |
|---|---|
| Quem paga se declara | Uma rota nova do próprio usuário, no estilo "lista inteira" da feature anterior: ex. `PUT /groups/{groupId}/me/pays-for` com `{ debtor_user_ids: [...] }`; a lista vazia deixa todos os papéis. Devolve `{ message, pays_for: [{ id, name }] }`. 422 para si mesmo, repetido, não membro ou acima do teto de 50. |
| Ler | **Nenhuma rota nova:** `GET /members` já devolve `responsibles` por membro; o front deriva "você paga por" de lá (`payForUserIds`). |
| Aceitar e recusar | Duas rotas do credor sobre o comprovante do par, ex. `POST /groups/{groupId}/settlements/accept` e `.../refuse`, com o par (e `cycles_ago`, como em `confirm`). |
| Excluir (D7) | Uma rota do remetente (`confirmed_by`) ou do devedor, ex. `DELETE /groups/{groupId}/settlements/confirmation` (D7-A, aprovada). |
| `summary`, `close`, `reopen` | `settlements[]` ganha, **de forma aditiva**, o estado da confirmação (ex. `confirmationStatus`: `sent`, `awaiting_acceptance`, `refused`, `accepted`). Nenhum campo existente muda. |
| Tipos de aviso novos | `payment_responsible_self_declared`, `settlement_refused` e `settlement_accepted`. Sem migration (a coluna `type` é livre). |

### 3.4 Decisões

D1 e D5 são **dele**; as demais eram recomendações e foram **todas aprovadas em 2026-11-10**.

| # | Decisão | Opções | Recomendação | Por quê | Status |
|---|---|---|---|---|---|
| D1 | Quem vincula | A) o responsável se adiciona sem aceite<br>B) propõe e o devedor aceita | **A** | B trava o caso da criança que não abre o app | Decidida por ele em 2026-11-10 |
| D2 | Avisar o devedor | A) sim, aviso in-app novo<br>B) não | **A** | É a única chance de um adulto notar; o devedor continua podendo remover pelo diálogo que já existe | Aprovada em 2026-11-10 |
| D3 | Registrar quem criou o vínculo | A) coluna `created_by` em `ex_group_member_responsibles` (nula para os vínculos antigos, que são do devedor)<br>B) nada | **A** | Audita e permite endurecer depois sem perder dado. Exige migration aditiva | Aprovada em 2026-11-10 |
| D4 | O que a recusa faz com a linha do comprovante | A) apaga a linha e o arquivo (**exclusão física, precisa do aval de hard delete**, Constitution §1.5/§5.2)<br>B) mantém a linha marcada como recusada (`refused_at`, `refused_by`) e o arquivo até o reenvio; o acerto conta como não confirmado enquanto estiver recusado | **B** | **Mudei em relação ao backlog 144, que sugeria A:** B não depende de gate de hard delete e guarda o motivo da pendência. Custo: "existe confirmação" passa a "existe confirmação não recusada" em `summaryIsFullySettled`, `withConfirmations` e no front | Aprovada em 2026-11-10 |
| D5 | Até quando a recusa vale | A) só em ciclo ainda **não encerrado**; o encerrado é imutável e nunca reabre<br>B) também em ciclo encerrado, reabrindo-o por `unsealIfBroken` | **A** | É a regra dele. Leitura de "fechado" confirmada (§1.1): fechado para enviar comprovante, encerrado quando selado | Regra dele; leitura confirmada em 2026-11-10 |
| D6 | Quem precisa do aceite do credor | A) só comprovante com `confirmed_by` não nulo: só conta para selar depois do aceite<br>B) todo comprovante<br>C) ninguém (a recusa não teria janela no último comprovante, §2.5) | **A** | Fecha o risco real sem mudar o fluxo de quem paga por conta própria. Limite: a recusa vale só para comprovante de responsável | Aprovada em 2026-11-10 |
| D7 | Quem exclui o comprovante antes do aceite | A) quem enviou e o devedor, com **exclusão física da linha e do arquivo** (precisa do aval de hard delete)<br>B) ninguém exclui: retificar é reenviar, e recusar é o que tira o comprovante do acerto | **A**, com o aval | É o "retificar e excluir" que ele pediu. Só o próprio comprovante, enquanto não aceito e o ciclo não selado. Se ele não aprovar o hard delete, cai para **B** | Aprovada em 2026-11-10, **com o aval de hard delete** (só o próprio comprovante, enquanto não aceito e com o ciclo não encerrado) |
| D8 | O responsável larga o papel | A) pode remover o próprio vínculo (a mesma rota, sem o devedor na lista)<br>B) fora de escopo (como na RK4 atual) | **A** | Custa pouco e fecha a RK4 da feature anterior | Aprovada em 2026-11-10 |
| D9 | Aviso ao recusar | A) sino para quem enviou e para o devedor<br>B) só para quem enviou | **A** | Os dois são afetados: o devedor é de quem é o acerto | Aprovada em 2026-11-10 |
| D10 | Aviso ao aceitar | A) sino para quem enviou<br>B) nenhum | **A** | Quem enviou está esperando; fecha o ciclo da conversa. Um tipo de aviso a mais | Aprovada em 2026-11-10 |
| D11 | Janela contra aviso repetido (item 141) | A) 24 h por par, `Cache::add` com TTL, sem migration<br>B) `throttle` próprio na rota<br>C) deixar como está | **A** | Precedente de cache em produção (§2.9); a coluna do convite não serve porque a linha do vínculo é apagada ao sair. B limita o devedor, não o aviso | Aprovada em 2026-11-10 |
| D12 | Aviso da própria confirmação (item 143) | A) pular sino e WhatsApp quando `confirmed_by` é o credor<br>B) deixar como está | **A** | A feature mexe no mesmo ponto (`settlementConfirmed` e `settlementProofConfirmed`) e o custo é uma condição | Aprovada em 2026-11-10 |

### 3.5 Falhas e consistência

| # | Falha | Tratamento |
|---|---|---|
| F1 | Responsável se declara por si mesmo, repetido, não membro, ou lista acima de 50 | 422 com mensagem; **nada** é alterado (valida antes de escrever, sob `lockForUpdate` do grupo) |
| F2 | Quem não é o credor do par tenta aceitar ou recusar | 403, antes de qualquer escrita |
| F3 | Aceitar ou recusar comprovante sem `confirmed_by`, ou inexistente | 422 / 404 por convenção do projeto, sem escrever |
| F4 | Ciclo selado | 422 "Esta competência já foi encerrada." em aceitar, recusar, excluir e reenviar (R9) |
| F5 | Reenviar um comprovante já aceito | 422; depois do aceite nada substitui o comprovante (R8) |
| F6 | Aceitar e reenviar ao mesmo tempo | A linha do comprovante é travada (`lockForUpdate`) e relida: vence o que commitou primeiro e o outro vê o estado novo |
| F7 | Aceitar o último comprovante do ciclo | Depois de gravar o aceite, chama `sealCycleIfSettled`, que sela se `totals.pending == 0` e todos os pares estão aceitos ou são do próprio devedor |
| F8 | Falha no aviso | Log; não desfaz o vínculo, o aceite nem a recusa (R13) |

### 3.6 Interface (web)

- **Participantes, linha própria:** botão novo **"Eu pago por…"** (junto de "Quem paga por mim" e "Sair do grupo") abre um diálogo com seletor múltiplo dos outros membros; salvar mostra "Escolha salva."; a linha mostra "Você paga por: Ana, Davi". Como na V1 da feature anterior, os botões vão **numa linha própria abaixo do e-mail** e o layout é medido em 375, 768, 1024, 1280 e 1440 px.
- **Participantes, linha do devedor:** o texto "Quem paga por você: …" já lista quem se declarou; o devedor remove pelo diálogo de hoje.
- **Pagamentos, credor:** no acerto com comprovante de responsável, selo "Aguardando seu aceite" e os botões **Aceitar** e **Recusar**, mantendo "Enviado por {nome}".
- **Pagamentos, remetente e devedor:** selo "Aguardando aceite do credor"; se recusado, "Recusado por {credor}" com **Reenviar comprovante** e **Excluir** (D7-A).
- **Sino:** textos para os três tipos novos, abrindo o resumo do grupo.

---

## 4. Riscos

| # | Risco | Impacto | Mitigação |
|---|---|---|---|
| RK1 | Comprovante falso por vínculo sem consentimento | Selar o ciclo de outra pessoa sem pagamento (era a RK2) | D6-A (não sela sem o aceite do credor), D2 (o devedor é avisado), D3 e `confirmed_by` (auditoria), D5-A (ciclo encerrado imutável) |
| RK2 | Aviso repetido por alternar o vínculo | Assédio de baixo custo contra o devedor ou o responsável | R11 (janela de 24 h) |
| RK3 | O credor nunca aceita | Comprovante de responsável nunca sela o ciclo | É o efeito desejado da D6-A; o acerto aparece em "aguardando seu aceite" e o aviso `settlement_confirmed` já existe |
| RK4 | Mexer em `confirmSettlement` e `summaryIsFullySettled` (selo) | Regressão na rota financeira | Matriz de autorização, mutation check, revisão do `security-reviewer`, e os testes de selagem atuais sem edição |
| RK5 | Exclusão física (D4-A, D7-A) | Hard delete sem gate | D4-B (a recusa não apaga nada) e D7-A com o aval dele registrado aqui (2026-11-10), limitado ao próprio comprovante; citar no PR |
| RK6 | Contrato de `summary` muda | O app Flutter, quando existir, depende dele | Só campos **aditivos**; nenhum consumidor móvel hoje |
| RK7 | Corrida entre aceitar, recusar e reenviar | Estado inconsistente | F6 (lock da linha e releitura) |
| RK8 | Criança que não usa o app nunca vê o aviso | Nenhum | Esperado: é o caso de uso |

---

## 5. Critérios de aceite

### 5.1 Backend
- CA-1: quem paga se declara por um ou vários membros; o vínculo vale sem aceite; `created_by` guarda quem criou.
- CA-2: quem paga só mexe em si mesmo; 422 para si mesmo, repetido, não membro e teto de 50; 404 antes de 403 nas rotas de grupo.
- CA-3: o devedor é avisado uma vez por par em 24 h, mesmo se o responsável sair e voltar; o `payment_responsible_assigned` ganha a mesma janela.
- CA-4: comprovante com `confirmed_by` não sela o ciclo antes do aceite; o do próprio devedor sela como hoje; teste de regressão com os testes de selagem atuais **sem edição**.
- CA-5: só o credor do par aceita ou recusa, só em ciclo fechado e não selado; recusar impede o selo e devolve o acerto a pendente.
- CA-6: reenviar substitui o comprovante recusado e o devolve a "aguardando aceite"; reenviar um aceito dá 422.
- CA-7: aceitar o último comprovante sela o ciclo; ciclo selado recusa aceitar, recusar e excluir.
- CA-8: sem aviso (sino e WhatsApp) ao credor quando ele mesmo enviou o comprovante.
- CA-9: matriz de autorização das rotas novas (devedor, responsável, credor, terceiro, não membro, criador) e mutation check das regras R1, R5, R6 e R9.

### 5.2 Frontend
- CA-10: "Eu pago por…" em Participantes, com o layout medido nas cinco larguras (nenhuma coluna do e-mail menor que o texto).
- CA-11: Aceitar e Recusar só para o credor e só no comprovante de responsável; os selos e legendas corretos nos três papéis; textos do sino para os três tipos novos.
- CA-12: `npx tsc --noEmit` limpo e suíte inteira do Vitest verde.

### 5.3 Documentação
- CA-13: `docs/sdd/01-specify.md` descreve o novo fluxo (glossário, §3.3 e §4); a Constitution não é editada; o aval de hard delete da D7-A está registrado aqui e deve ser citado expressamente no PR.

---

## 6. Fora de escopo desta feature

- O **142** (janela de milissegundos entre checar o vínculo e gravar em `confirmSettlement`): a recomendação dele é aceitar a janela, e mexer nisso soma risco à rota financeira que esta feature já altera.
- Painel pessoal e `GET /me/overview` (backlog **139**) e manual, PDF e site (backlog **140**).
- Aceite do credor para o comprovante que o **próprio devedor** envia (D6-B).
- **Reabrir ciclo encerrado** para recusar (D5-B) e **desfazer um aceite**.
- O devedor propor ou aceitar vínculo (D1-B); recusar ou largar o papel por parte de quem foi escolhido pelo devedor, além do que a D8 já dá ao próprio responsável.
- O texto do WhatsApp ao credor e o app Flutter.
