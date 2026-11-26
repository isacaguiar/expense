# Specify — Janela anti-assédio no aviso do complemento

> Feature: limitar quantos avisos de "justificativa adicionada" quem paga pelo devedor consegue mandar ao credor e ao devedor. Origem: ponto 4 do item 155 do backlog (`docs/backlog/achados-informativos-da-revisao-de-seguranca-do-historico-de-comprovantes.md`), achado informativo do `security-reviewer` na feature `docs/feature/concluidas/202611/20261114-historico-de-comprovantes-e-justificativa/` (TASK-628). Promovido por ele em 2026-11-25, **só o ponto 4**.

Versão: 1.0 · Criado em: 20261125

---

## 1. Problema

Quem paga pelo devedor pode adicionar uma justificativa com foto ao comprovante que o devedor enviou (`POST /api/groups/{groupId}/settlements/complement`). Cada complemento gravado gera **dois avisos no sino**, um ao credor e outro ao devedor (`Notifier::settlementComplementAdded`). O único freio é o teto de **10 complementos por par e ciclo**, que existe para proteger o histórico e não o destinatário.

Resultado: um responsável mal-intencionado (ou só aflito) manda até 10 avisos iguais ao mesmo credor e ao mesmo devedor, por par e por ciclo, e o texto do aviso é sempre o mesmo ("X adicionou uma justificativa…"). Nenhum outro aviso do projeto de vínculo tem esse problema: os de vínculo (`paymentResponsibleAssigned`, `paymentResponsibleSelfDeclared`) já têm uma janela de 24 h por par, justamente para não virar assédio.

É um achado **informativo, prioridade BAIXA**: não há dado exposto nem escrita indevida, só ruído no sino de duas pessoas que são parte do acerto. Ele escolheu tratá-lo agora (decisão D13 da feature de origem foi a opção A, sem a C).

## 2. Achados confirmados (código lido em 2026-11-25)

### 2.1 O aviso do complemento não tem janela

`Notifier::settlementComplementAdded` (`backend/app/Support/Notifier.php:394`) chama `guard` e `fanOut` direto, sem `oncePer`. O teste `test_a_second_complement_notifies_again` (`backend/tests/Feature/SettlementComplementTest.php:558`) **fixa o comportamento atual**: dois complementos seguidos geram 4 avisos. Esse teste muda nesta feature.

### 2.2 O mecanismo de janela já existe e é reaproveitável

`Notifier::oncePer($key, $minutes, $send)` (`Notifier.php:451`) usa `Cache::add`: roda o envio só se a chave ainda não existe, desfaz a marca (`Cache::forget`) se o envio falha e fica dentro de `guard` (falha do cache vira log e a ação segue, sem o aviso). `linkNoticeKey` monta a chave `payment-responsible:{grupo}:{devedor}:{responsável}:{tipo}` e `LINK_NOTICE_WINDOW_MINUTES` vale 24 h. Os dois avisos de vínculo (`Notifier.php:269` e `:290`) são os únicos usuários.

### 2.3 A rota não tem throttle próprio

`POST /groups/{groupId}/settlements/complement` (`backend/routes/api.php:94`) só tem o limiter global `api` (60/min), que, com o guard padrão `web`, acaba chaveando **por IP** (`RouteServiceProvider.php:27`). O `overview` mostra o padrão de limiter por usuário: `RateLimiter::for('overview', …)` resolvendo `$request->user('api')` (`RouteServiceProvider.php:42`, com a explicação da ordem do `ThrottleRequests` antes do `jwt.auth`).

### 2.4 O front trata 429 com a mensagem genérica

`useSettlementComplement` (`frontend/src/hooks/useSettlementComplement.ts:54`) usa `apiErrorMessage`, que só lê `error` e `errors`. Um 429 do Laravel vem como `{"message": "Too Many Attempts."}`, então a tela mostraria "Não foi possível enviar a justificativa. Tente novamente." Funciona, mas não diz que é preciso esperar.

## 3. Requisitos e decisões

### 3.1 Regras propostas

- **R1.** O aviso do complemento não se repete dentro de uma janela para a mesma combinação de quem adicionou, devedor, credor, grupo e ciclo. Dentro da janela o complemento **continua sendo gravado** (histórico, arquivo e teto de 10 inalterados): só o aviso deixa de sair.
- **R2.** A janela vale **por evento, não por destinatário**: os dois avisos (credor e devedor) saem juntos ou ficam juntos de fora. Nunca um sai e o outro não.
- **R3.** Falha do cache **não** impede o complemento (já é o contrato do `guard`, R13 da feature de origem) e não derruba o 201.
- **R4.** Nada muda no que o aviso diz (`data` com `actorName`, `debtorName`, `groupId`, `groupName`, `amount`, `cycleLabel`; sem a justificativa).
- **R5.** O limite da rota (se adotado, D3) vale por usuário, não por IP, e não conta quem não tem token válido.

### 3.2 Decisões abertas (tabela)

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Aplicar a janela do aviso? | A) sim, igual à dos avisos de vínculo<br>B) não, só o throttle | **A** | É o que o item pede primeiro e reaproveita `oncePer` sem código novo de cache. É o que realmente reduz o ruído no sino: o throttle sozinho só limita a velocidade, e 10 avisos em 10 minutos continuam sendo 10 avisos |
| D2 | Chave da janela | A) grupo + ciclo + devedor + credor + **autor**<br>B) grupo + ciclo + devedor + credor (qualquer autor)<br>C) autor + devedor (sem ciclo) | **A** | Com B, um segundo responsável legítimo seria silenciado pelo primeiro e o credor não saberia que há outra justificativa. Com C, o ciclo seguinte do mesmo par ficaria mudo por até 24 h. A chave por autor é a que isola o assediador sem calar os outros |
| D3 | Tamanho da janela | A) 24 h (igual ao vínculo)<br>B) 1 h<br>C) 15 min | **B** | O vínculo é um fato raro e 24 h cabe; o complemento é uma ação de trabalho (a pessoa manda a foto e logo depois outra). 24 h esconderia do credor uma segunda justificativa legítima no mesmo dia. 1 h corta a rajada e ainda avisa de novo na mesma tarde. **Confirmar com ele** |
| D4 | `throttle` por usuário na rota | A) sim, limiter nomeado `complement` (por usuário)<br>B) não, a janela e o teto de 10 bastam<br>C) sim, mas só `throttle:N,1` por IP | **B** | O item sugere o throttle, mas o dano que ele cobre (ruído) já é coberto pela D1, e o teto de 10 por par e ciclo limita o resto. Um limiter novo traz um 429 que o front mostra como erro genérico e mais um caso a testar (R5, ordem do `jwt.auth`). O custo-benefício é ruim para um achado BAIXA. Se ele preferir fechar o item por inteiro, vale A com o valor decidido por ele |
| D5 | Mensagem do 429 (só se D4-A) | A) `apiErrorMessage` passa a ler `message` em 429<br>B) o limiter devolve `{"error": "…"}`<br>C) deixar o genérico | **B** | O backend já fala com o front por `error` em todas as recusas do complemento, então B não mexe no front. Só relevante se D4-A |
| D6 | O que fazer com o teste `test_a_second_complement_notifies_again` | A) reescrever: dentro da janela o segundo complemento não avisa; após a janela avisa de novo<br>B) apagar | **A** | O teste documenta a regra antiga. Reescrevê-lo mantém a cobertura e prova a janela (com `Carbon::setTestNow`, já usado neste arquivo) |
| D7 | Janela e o "ciclo" no 422/teto | A) a janela só olha o aviso; o teto de 10 continua igual<br>B) a janela também recusa o complemento | **A** | Recusar o complemento seria perder justificativa legítima. A janela é proteção do destinatário, não do histórico |

> **Leitura da recomendação:** D1-A + D2-A + D3-B + D4-B + D6-A + D7-A resolvem o ruído sem tocar rota, front nem contrato HTTP: a mudança fica em `Notifier::settlementComplementAdded` e no teste. Se ele escolher D4-A, entram a rota e o limiter (D5-B).

## 4. Fora de escopo desta feature

- **Ponto 1 do item 155** (o complemento não aponta para a versão do comprovante em `ex_settlement_proof_entries`; pode exigir migration) e **ponto 3** (nota sobre os limites do ADR-005): seguem abertos no item. O item 155 **não** é fechado por esta feature (promoção parcial, como foi o 165).
- Mudar o teto de 10 complementos por par e ciclo.
- Janela ou throttle em outros avisos (`settlement_confirmed`, `settlement_accepted`, `settlement_refused`): não há achado sobre eles.
- Qualquer mudança no texto do aviso ou no front, salvo a D5 se D4-A for escolhida.
- Os itens 166 e os dois informativos de log (decisão dele, outra pasta).
