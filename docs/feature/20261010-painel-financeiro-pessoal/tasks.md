# Tasks — Painel Financeiro Pessoal

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261010

IDs a partir de TASK-409: o maior em qualquer branch do repositório é TASK-408 (feature `20261010-filtros-credor-devedores-status-despesas`). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge. A feature `20261006-relatorios-acompanhamento-fechamento` ainda não reservou IDs e deve começar depois de TASK-426.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-409 | Extrair `App\Services\CycleSummaryService` (`entries`, `compute`, `withConfirmations`, `isFullySettled`) do `ExpenseController`, sem mudar comportamento | backend | plan.md §0.1, §0.2 | nenhum | Concluída |
| TASK-410 | Mover o laço do `focusCycle` para `CycleSummaryService::focus()` (com `FOCUS_LOOKBACK`) e fazer o controller delegar | backend | plan.md §0.2, §1.2 | nenhum | Concluída |
| TASK-411 | Criar `CycleSummaryService::view()`, a escolha de fonte de `summary()` sem a selagem preguiçosa, com `in_grace` | backend | plan.md §1.2 | nenhum | Concluída |
| TASK-412 | Criar `PersonalOverviewService` com a derivação por usuário (`to_pay`, `to_receive`, `owe`, `receive`, `my_items`, `has_open`, ordenação) | backend | plan.md §1.3 | nenhum | Concluída |
| TASK-413 | Criar `OverviewController` e a rota `GET /api/me/overview` sob `jwt.auth`, com o escopo do usuário na consulta | backend | plan.md §1.1, §1.4 | nenhum | Concluída |
| TASK-414 | Revisar o endpoint com o agent `security-reviewer` e rodar a suíte completa do backend | backend | plan.md §1.4, §6 | nenhum | Concluída |
| TASK-415 | Extrair `ProofUploadDialog` de `Payments.tsx` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-416 | Extrair `useConfirmSettlement` de `Payments.tsx` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-417 | Criar `types/overview.ts` e `useMyOverview` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-418 | Criar `ReceivableList` ("Você recebe") | frontend | plan.md §2 | nenhum | Concluída |
| TASK-419 | Criar `MyItemsList` ("Seus itens") com as ações guiadas pelas flags do servidor | frontend | plan.md §2, §3 | nenhum | Concluída |
| TASK-420 | Criar `GroupPositionCard` com totais, selos, "Você paga" (Pix e comprovante) e as ações de marcar e desfazer | frontend | plan.md §2, §3, §4 | nenhum | Concluída |
| TASK-421 | Criar a página `MyPanel` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-422 | Registrar a rota `/painel` e o item "Painel" nos dois menus | frontend | plan.md §2 | nenhum | Concluída |
| TASK-423 | Validar o painel localmente na stack isolada (API e tela) e registrar o resultado | frontend | plan.md §6 | antes do merge (o usuário valida localmente antes do push e do PR) | Em validação: roteiro executado (`implementation.md` §23); aguardando a confirmação do usuário e o encerramento da stack |
| TASK-424 | Rodar a revisão final (backend e frontend): `pr-readiness-checker`, suítes completas e checklist do `04-implementation.md` §1 item 5 | backend | plan.md §6 | antes do merge | **Bloqueada** pela divergência com a `dev` (`implementation.md` §25): só faz sentido depois de integrar a `dev` e reconciliar a prova de pagamento |
| TASK-425 | Registrar em `docs/backlog/` os achados tangenciais da feature | doc | plan.md §9 | nenhum | Pendente |
| TASK-426 | Atualizar o plan de `20261006-relatorios-acompanhamento-fechamento` para usar `CycleSummaryService` e as linhas atuais | doc | plan.md §0.4 | nenhum | Pendente |
| TASK-427 | Reduzir o custo de `GET /me/overview`: `focus()` sem calcular ciclos anteriores à primeira despesa do grupo e snapshots lidos numa só consulta, sem mudar o resultado | backend | plan.md §1.2, §8 | nenhum | Concluída |
| TASK-428 | Limitar o número de grupos do overview e sinalizar com `truncated` (decisão D9) | backend | plan.md §1.1, §8 | nenhum | Concluída |
| TASK-429 | Aplicar throttle por usuário na rota `GET /me/overview` (decisão D10) | backend | plan.md §1.4 | nenhum | Concluída |
| TASK-430 | Falhar fechado quando o nome de um participante de snapshot legado é ambíguo entre os membros do grupo | backend | plan.md §1.3 | nenhum | Concluída |
| TASK-431 | Responder 401 quando o usuário do token deixa de existir entre o middleware e o controller | backend | plan.md §1.4 | nenhum | Concluída |
| TASK-432 | Fechar as lacunas de teste do overview apontadas pela revisão: ex-membro, credor que saiu do grupo e vazamento de e-mail de grupo alheio | backend | plan.md §1.4, §6 | nenhum | Concluída |
| TASK-433 | Mostrar "Já recebido" no cartão (achado 1 da validação local, `implementation.md` §23) | frontend | plan.md §2 | roda antes da TASK-424 | Concluída |
| TASK-449 | Integrar a `dev` (54 commits) na branch da feature, resolvendo os 3 conflitos e portando `paymentNote`/`confirmedNote` para o `CycleSummaryService` (decisão D12) | backend | `implementation.md` §25 | roda antes da TASK-424; **a stack da validação local é interrompida enquanto o merge está com conflito** | Pendente |
| TASK-450 | O Painel adota o `PaymentProofDialog` e a descrição do pagamento; retirar o `ProofUploadDialog` (decisão D13, que reescreve a D7) | frontend | `implementation.md` §25 | depois da TASK-449 | Pendente |
| TASK-451 | Repetir no navegador, na stack da TASK-423, o roteiro afetado pelo merge (`migrate`, comprovante do devedor, marcar e desfazer, Pagamentos) | frontend | `implementation.md` §23 | depois da TASK-450; antes da TASK-424 | Pendente |

Ordem: TASK-409 → 410 → 411 → 412 → 413 → 414 (backend, plan.md §7 passos 1 a 3). Em seguida TASK-415 → 416 → 417 → 418 → 419 → 420 → 421 → 422 (frontend, passos 4 a 6). As TASK-415 e 416 não dependem do backend e poderiam rodar antes; ficam depois para integrar o contrato real a partir da TASK-417. Fecham TASK-423 (validação local) → 424 (revisão final) → 425 → 426. A revisão da TASK-414 gerou as TASK-427 a 432 (backend), que rodam logo depois dela e antes de a TASK-423 validar tudo: TASK-427 → 428 → 429 → 430 → 431 → 432. As TASK-428 e 429 dependem das decisões D9 e D10 do `specify.md` §5; se forem recusadas, ficam Canceladas. Se a validação ou a revisão apontar defeito, ele vira task nova, não amplia a task em andamento.

Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: a TASK-409 nasce direto na branch da feature `feature/20261010-painel-financeiro-pessoal`, criada a partir da `dev` atualizada (tipo `feature` porque a feature cruza backend e frontend; há precedente em `dev`). As seguintes saem dela como `backend/20261010-painel-financeiro-pessoal-TASK-4xx` ou `frontend/20261010-painel-financeiro-pessoal-TASK-4xx` e voltam com `git merge --no-ff`, sem PR. **Não usar** a branch atual do checkout (`frontend/20261010-filtros-credor-devedores-status-despesas`, com trabalho sem commit de outra feature). Um único PR contra `dev` no fim.

Dependências: TASK-411 usa `withConfirmations` da TASK-409 e `focus()` da TASK-410. TASK-412 usa `view()` e `focus()`. TASK-413 usa o serviço da TASK-412. TASK-420 usa as TASK-415, 416, 418 e 419 e o hook `usePaymentActions` sem alterá-lo. TASK-421 usa as TASK-417 e 420. TASK-423 roda sobre a branch da feature com todas as anteriores integradas.

## Critérios de aceite

Comandos de backend a partir de `backend/`, de frontend a partir de `frontend/`. "Suíte atual" é a contagem de testes verdes que a TASK-409 registra no `implementation.md` **antes** de mexer em código.

- **TASK-409**:
  - Antes de começar, `php artisan test` rodado e a contagem de verdes registrada no `implementation.md`. Depois da task, a mesma suíte passa com **a mesma contagem de testes preexistentes** e `git diff --stat -- backend/tests` não lista nenhum arquivo alterado (só os novos da task).
  - `backend/app/Services/CycleSummaryService.php` existe com `entries`, `compute`, `withConfirmations`, `isFullySettled` e `summaryIsFullySettled`. No `ExpenseController.php`, `grep -n "function collectCycleEntries\|function computeCycleSummary\|function attachSettlementConfirmations" app/Http/Controllers/ExpenseController.php` não devolve nada, e todas as chamadas passam por um helper privado `cycles()` que resolve `app(CycleSummaryService::class)`.
  - `ExpenseController` **não tem `__construct`** (`grep -n "__construct" app/Http/Controllers/ExpenseController.php` vazio) e `cycleIsFullySettled` continua existindo como método privado que delega ao serviço. Provado por `ExpenseControllerCycleIsFullySettledTest` e `ExpenseControllerMaterializeFixedOccurrenceQuotaTest` verdes **sem edição** (ambos usam reflexão com `new ExpenseController`).
  - `git diff -M --color-moved` mostra o corpo dos métodos movido sem alteração de regra: só assinatura, `$this->` virando chamada ao serviço e visibilidade.
  - Testes novos em `tests/Feature/CycleSummaryServiceTest.php` cobrindo `summaryIsFullySettled` (pendente > 0 → falso; pendência zero e acertos sem comprovante → falso; todos confirmados → verdadeiro; sem acertos e sem pendência → verdadeiro) e `compute` para o mesmo cenário de uma despesa dividida (acerto líquido igual ao do `summary` HTTP).
  - Mutação: trocar `> 0` por `< 0` em `summaryIsFullySettled` derruba os testes **novos** de pendência (`summary is not settled while something is pending` e `... for a cent still pending`, que isolam a pendência com `settlements` vazio). O `ExpenseControllerCycleIsFullySettledTest` **não** cai: seu caso "pendente" é mascarado pelo acerto sem comprovante (achado registrado no `implementation.md` §3; corrigido aqui na execução, o critério original dizia que cairia). `./vendor/bin/pint --test` limpo.
- **TASK-410**:
  - `FocusCycleTest` passa **sem edição**; `focusCycle()` no controller só chama o serviço (corpo de poucas linhas), e `FOCUS_LOOKBACK` deixa de ser declarado no controller (`grep -n "FOCUS_LOOKBACK" app/Http/Controllers/ExpenseController.php` vazio).
  - `CycleSummaryServiceTest::focus` cobre: ciclo fechado mais recente com pendência (2 ciclos atrás → 2); ciclo em carência conta; ciclo selado é pulado; nada pendente → 0; pendência exatamente 12 ciclos atrás → 12 e 13 ciclos atrás → 0 (fronteira do `FOCUS_LOOKBACK`); ciclo vigente aberto com despesa não paga **não** conta como pendência (o resultado é 0 pelo fallback, não por pendência).
  - Mutação: trocar `<=` por `<` no laço derruba o teste da fronteira de 12.
  - Suíte atual verde e Pint limpo.
- **TASK-411**:
  - `CycleSummaryServiceTest::view` cobre os ramos: (a) snapshot selado → dados do snapshot, `status` `closed`, `settled` verdadeiro; (b) fechado por data e não selado → cálculo ao vivo, `status` `closed`, `settled` falso; (c) fechamento manual ativo no ciclo vigente → `closed_manually`; (d) ciclo vigente aberto → ao vivo, `status` `open`, `in_grace` falso; (e) ciclo com fronteira passada e ainda `open` → `in_grace` verdadeiro; `closes_at` presente em todos.
  - `settlements` de `view()` trazem `confirmedAt` e `confirmedProofUrl` (vêm de `withConfirmations`).
  - **Sem escrita:** num ciclo fechado, quitado e sem snapshot (o caso "quitado e não selado"), depois de `view()` o `GroupCycleSnapshot::count()` e o `Notification::count()` ficam **iguais** ao de antes; o mesmo cenário chamado por `GET /summary` sela (controle que prova a diferença deliberada).
  - **Reconciliação:** para um ciclo fechado e pendente, `cycle.start/end/status/closes_at/settled`, `totals` e `settlements` de `view()` são iguais aos da resposta de `GET /summary?cycles_ago=N`.
  - Mutação: fazer `view()` devolver `closed` no caso (c) derruba o teste (c). Suíte atual verde e Pint limpo.
- **TASK-412**:
  - `tests/Feature/PersonalOverviewServiceTest.php` passa e fixa, com `Carbon::setTestNow` e `DatabaseTransactions`:
    - **Devedor:** ciclo fechado com despesa de R$ 100 dividida entre credor e o usuário → `to_pay` com 1 acerto de R$ 50,00, `owe` `{total 50, confirmed 0, open 50}`, `can_confirm` verdadeiro, credor com `id`, `name`, `email`, `pix`, `avatar_url`. Com a confirmação gravada → `confirmed 50`, `open 0`, `confirmed_at` e `confirmed_proof_url` preenchidos.
    - **Credor:** o inverso em `to_receive` e `receive`, sem `email` nem `pix` do devedor.
    - **Dois papéis no mesmo grupo:** `owe` e `receive` calculados sobre o acerto **líquido** (pagou 100 e 40 em despesas divididas ao meio entre dois → um único acerto de R$ 30).
    - **`my_items`:** `role` `debtor`/`creditor`; `my_share` igual ao `valuePerPerson`; `installment` `{number,total}` em parcelada e `null` nas demais; `bornPaid` **fora** da lista; `can_pay` só para credor com `paid` falso e `can_unpay` só para credor com `paid` verdadeiro; devedor nunca tem as duas flags.
    - **`can_confirm`:** verdadeiro em `closed` e `closed_manually` não selados; falso em ciclo vigente aberto.
    - **Snapshot antigo** sem `participantDetails`: os itens resolvem o id do participante pelos membros do grupo.
    - **`has_open`** e **`pending_items_count`**; ordenação: grupos com `has_open` primeiro, depois nome; grupo "em dia" aparece com totais zerados; grupo `deleted` e grupo de outro usuário não aparecem; usuário sem grupos → lista vazia.
  - Mutações: somar `confirmed` sem checar `confirmed_at`, e inverter o `role`, derrubam, cada uma, pelo menos um teste. Pint limpo e suíte atual verde.
- **TASK-413**:
  - `tests/Feature/OverviewControllerTest.php` passa: 401 sem token; 200 com a estrutura do `plan.md` §1.1 (`user.id`, `user.name`, `groups[]` com `group`, `cycle` incluindo `cycles_ago`, `owe`, `receive`, `to_pay`, `to_receive`, `my_items`, `pending_items_count`, `has_open`); o grupo de **outro usuário nunca aparece**, nem pelo `id` no JSON; usuário sem grupos → `groups` vazio com 200.
  - **Nenhum efeito colateral:** `GroupCycleSnapshot::count()` e `Notification::count()` iguais antes e depois de chamar o endpoint num cenário em que `GET /summary` selaria o ciclo.
  - **Reconciliação:** para o ciclo escolhido, `cycle` e os valores de `to_pay`/`to_receive` batem com `GET /groups/{id}/expenses/summary?cycles_ago=<cycle.cycles_ago>` do mesmo grupo.
  - `php artisan route:list --path=api/me/overview` mostra o middleware `jwt.auth`.
  - Mutação: tirar o `whereHas('members', …)` da consulta de grupos derruba o teste de grupo alheio. Pint limpo e suíte atual verde.
- **TASK-414**:
  - Agent `security-reviewer` rodado sobre `OverviewController`, `PersonalOverviewService` e a rota; o resultado e cada achado (corrigido, descartado com motivo ou virou task nova) registrados no `implementation.md`. Nenhum achado alto sem tratamento.
  - `php artisan test` da suíte inteira verde, `./vendor/bin/pint --test` limpo, com os comandos e as contagens reais no `implementation.md`.
- **TASK-415**:
  - `src/components/ProofUploadDialog.tsx` existe e `Payments.tsx` o usa nos **dois** diálogos de comprovante (`grep -n "Selecionar foto\|Selecionar comprovante" src/pages/Payments.tsx` vazio).
  - `npx vitest run src/pages/Payments.test.tsx` verde **sem edição** (8 testes).
  - `ProofUploadDialog.test.tsx`: "Confirmar" desabilitado até escolher arquivo; ao confirmar chama `onConfirm` com o `File`; `error` mostrado num `Alert`; `busy` desabilita os dois botões; título, texto, rótulo do botão de seleção e ícone vêm por props.
  - Mutação: habilitar "Confirmar" sem arquivo derruba o teste. `npx tsc --noEmit` sem erro e sem `any`.
  - **Corrigido na execução (§15 do `implementation.md`):** o grep literal acima contradiz o critério de que os rótulos vêm por props (as duas strings passam a ser props em `Payments.tsx`). A verificação vale como `grep -nE '<Dialog|type="file"' src/pages/Payments.tsx` vazio: nenhum diálogo nem campo de arquivo inline sobrou na página.
- **TASK-416**:
  - `src/hooks/useConfirmSettlement.ts` existe e `Payments.tsx` o usa; `Payments.test.tsx` verde **sem edição**, inclusive o teste que fixa `cycles_ago`.
  - `useConfirmSettlement.test.tsx`: o `POST /api/groups/{id}/settlements/confirm` leva `FormData` com `to_user_id`, `comprovante` e `cycles_ago` igual ao argumento (testar com `1` e `2`), cabeçalho `Authorization: Bearer <token>`; sucesso chama `onSuccess`; erro usa `response.data.error` e, sem ele, "Falha ao enviar o comprovante."; `submitting` verdadeiro durante a chamada.
  - Mutação: omitir o `cycles_ago` do `FormData` derruba o teste do hook e o de `Payments`. `tsc` sem erro.
- **TASK-417**:
  - `src/types/overview.ts` tipa o payload do `plan.md` §1.1; uma fixture tipada com esse tipo compila (`tsc --noEmit` sem erro e sem cast).
  - `useMyOverview.test.tsx`: `GET /api/me/overview` com `Authorization: Bearer`; sucesso expõe `data`; 401 chama `navigate('/', {replace:true})`; outro erro expõe "Falha ao carregar o painel."; `reload()` refaz a chamada.
- **TASK-418**:
  - `ReceivableList.test.tsx`: cada linha mostra o devedor, "R$ 120,00" (pt-BR) e o chip "Comprovante enviado" com link para `confirmed_proof_url` (abre em nova aba) quando há `confirmed_at`, ou "Aguardando pagamento" quando não há; lista vazia mostra "Ninguém te deve neste ciclo.". Sem botão de ação.
- **TASK-419**:
  - `MyItemsList.test.tsx`: devedor vê "Sua parte R$ …" e o nome do credor; credor vê "Você pagou R$ …"; "Parcela 2/6" só quando `installment` existe; chip Paga/Pendente; "Marcar como paga" só quando `can_pay` e "Desfazer" só quando `can_unpay`, chamando `onPay(expenseId)` e `onUnpay(expenseId)`.
  - **Regressão da regra no servidor:** item com `role: 'creditor'`, `paid: false` e `can_pay: false` **não mostra botão** (prova que o cliente não recalcula permissão).
- **TASK-420**:
  - `GroupPositionCard.test.tsx` (axios mockado) fixa, usando `cycles_ago: 2` no payload (valor que não coincide com 0 nem 1):
    - exibe nome, chip do ciclo (carência mostra "Fecha em dd/mm"), "A pagar", "A receber", "Já confirmado"; `has_open` falso mostra "Em dia"; verdadeiro mostra o alerta; link "Ver ciclos" aponta para `/groups/{id}/payments`;
    - "Enviar comprovante" abre o diálogo e faz `POST …/settlements/confirm` com `to_user_id`, `comprovante` e `cycles_ago` `'2'`; sucesso chama `onChanged`;
    - "Marcar como paga" exige foto e faz `POST /api/expenses/{id}/pay` com `FormData` contendo `cycles_ago` `'2'`; "Desfazer" faz `POST …/unpay` com `{cycles_ago: 2}`;
    - "Pagar com Pix" abre `PixPaymentDialog` com e-mail, nome e valor do credor; credor sem `pix` não abre o diálogo e mostra "ainda não cadastrou uma chave Pix";
    - sem `can_confirm`, os botões de comprovante e Pix não aparecem e o aviso de `PayableSettlementList` ("Disponível após o fechamento do ciclo.") aparece.
  - `usePaymentActions` **não foi alterado** (`git diff --stat -- src/hooks/usePaymentActions.ts` vazio) e `usePaymentActions.test.tsx` verde.
  - Mutação: trocar o `cycles_ago` por `'0'` fixo derruba os testes de comprovante, de marcar e de desfazer. `tsc` sem erro.
- **TASK-421**:
  - `MyPanel.test.tsx`: loading, erro, vazio ("Você ainda não participa de nenhum grupo."), cartões na **ordem que o servidor mandou**, `reload` do hook passado como `onChanged` dos cartões, e mensagens de sucesso ("Comprovante enviado." e "Pagamento confirmado.") em `Snackbar` (**no `GroupPositionCard`, não na página**: o estado das ações vive no cartão, TASK-420, §20 do `implementation.md`; o teste da página as acha igual). A página está dentro de `DespesasThemeScope` e passa `data.user` como `currentUser` de cada cartão.
  - Com `truncated: true` (TASK-428), um `Alert` informativo acima dos cartões: "Você participa de mais de 50 grupos. Aqui aparecem os 50 mais antigos; os demais estão em Meus Grupos."; com `false`, nenhum aviso.
- **TASK-422**:
  - `App.tsx` tem `<Route path="/painel" …>` dentro do bloco `SimpleShellLayout`; `simpleNavItems.ts` e `groupNavItems` (`GroupSidebar.tsx`) têm "Painel" como **primeiro** item, com `to: '/painel'`.
  - `SimpleShellLayout.test.tsx` e `GroupShellLayout.test.tsx` ganham a asserção do link `/painel`, e as asserções que já existiam passam sem edição. O cabeçalho mostra "Painel" quando a rota é `/painel`. Verificar `Sidebar.test.tsx`, `MobileNavDrawer.test.tsx` e `App.test.tsx`.
  - `npx vitest run --maxWorkers=2` (suíte completa) verde e `npx tsc --noEmit` sem erro.
- **TASK-423**:
  - Roteiro do `plan.md` §6 executado na stack isolada (MySQL descartável na 3307, backend na 8001, Vite na 3001): usuário em grupos com (a) ciclo fechado pendente como devedor, (b) ciclo fechado como credor, (c) só ciclo vigente, (d) um grupo em dia e (e) um ciclo em carência. Conferir:
    - os totais de cada cartão contra o `GET /summary` do mesmo ciclo;
    - devedor envia comprovante e o status do acerto muda;
    - credor marca e desfaz a despesa e o selo do item acompanha (os **totais do cartão não mudam**: são o acerto líquido, decisão D1; corrigido na validação, `implementation.md` §23); Pix abre o diálogo;
    - largura de 375 px sem scroll horizontal; usuário sem grupos; sessão expirada volta ao login.
  - Registrado no `implementation.md` o resultado de cada item, com o que foi observado, o **número de consultas SQL e o tempo da chamada** de `/me/overview` com 5 grupos semeados, e o item "Informar pagamento" de `GroupGrossDebtsPanel` conferido como inalterado.
  - Stack isolada encerrada ao final (processos, banco e `.env.testing` removidos, portas liberadas).
  - O usuário confirma por escrito que validou localmente. Sem essa confirmação, **não há push nem PR**.
- **TASK-424**:
  - Agent `pr-readiness-checker` rodado; checklist do `04-implementation.md` §1 item 3 refeito **na branch da feature já integrada** (item 5). Comandos reais e resultados no `implementation.md`: `php artisan test`, `./vendor/bin/pint --test`, `npx tsc --noEmit`, `npx vitest run --maxWorkers=2`.
  - `git diff origin/dev --stat` revisado: sem segredo, sem arquivo fora do escopo (`backend/tests` só com arquivos novos), nenhuma migration.
  - Descrição do PR lista as tasks 409 a 426 e referencia a feature.
- **TASK-425**:
  - Um arquivo por achado em `docs/backlog/`, no formato do `README.md` (ID sequencial livre a conferir no índice no momento da task, Origem, Criado em, Prioridade, Status, Descrição) e com `## Regras adotadas` e `## Decisões em aberto` em tabela, com recomendação e motivo, quando houver decisão:
    - `grossDebts` usa `$expense->payer` sem checar nulo (`ExpenseController.php:969`) (BAIXA);
    - destino do botão "Informar pagamento" de `GroupGrossDebtsPanel` depois do Painel (decisão);
    - `GET /api/dashboard` é stub com nome enganoso, e `Navbar.tsx`/`InternalLayout.tsx` são código morto (BAIXA);
    - incluir `GET /api/me/overview` no escopo v1 do app Flutter (nota ao item 074);
    - status individual por parte em `my_items[]` quando a 075 chegar (nota aditiva);
    - **(JÁ REGISTRADOS em 2026-10-21, por pedido do usuário: itens 082 e 083 de `docs/backlog/`. Ao executar a TASK-425, conferir que existem e não recriar; o X3 já é o item 060 e também não se duplica.)** **X1 (crítico, urgente), da revisão da TASK-414:** `GET /api/groups/{groupId}/members` (`GroupMemberController::index`) não checa se quem chama é membro. Reproduzido: um usuário de fora recebe 200 com `email`, `pix`, `whatsapp`, `role` e `invited_by` de cada membro. Prioridade ALTA. É defeito de comportamento existente com gatilho de autorização, então a Triagem do `/novo-bug` o escala para feature (`docs/bugfix/README.md`). **Decisão do usuário em 2026-10-21: não corrigir dentro desta feature e tratar depois do Painel**; até lá o dado segue exposto em produção;
    - **X2, da revisão:** `GroupMemberController::destroy` não existe, embora a rota `DELETE /groups/{groupId}/members/{userId}` exista (já listado na Constitution §2.4). Junto: `store` adiciona usuário já existente sem consentimento dele, e quem foi adicionado não consegue sair. Prioridade ALTA, junto do X1;
    - **X3, da revisão:** `Log::info('User authenticated:', ['user' => auth()->user()])` em `GroupController::store` grava e-mail, Pix e WhatsApp no log (BAIXA). **Já existe como item 060** (achado do `security-reviewer` na TASK-353); não criar outro;
    - **S5, da revisão:** `cycles_ago` sem teto em `pay`, `unpay` e `settlements/confirm`, e a alternativa de aceitar `cycle_start` absoluto, que o payload já devolve (BAIXA);
    - **S6, da revisão** (D11 decidida em 2026-10-21: manter o contato como está): minimizar o contato do credor no overview e trocar o lookup de `GET /pix/generate` de e-mail para `user_id` (BAIXA).
    - **Da TASK-429:** o middleware `jwt.auth` (Tymon) não está no `middlewarePriority` do Kernel, então `ThrottleRequests` e `SubstituteBindings` rodam **antes** da autenticação em toda rota protegida, qualquer que seja a ordem declarada. Hoje não há binding implícito de model nas rotas (só `{groupId}` cru), então não vaza nada; mas um `throttle:` futuro chaveado por usuário, ou um binding implícito, herdariam o problema. Avaliar incluir o middleware no `middlewarePriority` (muda a ordem de todas as rotas) (BAIXA).
  - `docs/backlog/README.md` com o índice atualizado.
- **TASK-426**:
  - `docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md` atualizado: as referências de linha do `ExpenseController` conferidas contra o arquivo atual (`grep -n` de cada método citado), o §0.3 passa a usar `CycleSummaryService::summaryIsFullySettled` em vez de extrair dentro do controller, e `ClosingBreakdown` segue como classe pura em `App\Support`.
  - Nenhuma decisão do specify dele muda; o specify é relido e só é editado se algo ficar contraditório.
- **TASK-427** (achado S1; medido na TASK-414: grupo vazio ≈ 54 consultas, 20 grupos vazios ≈ 1.082 consultas e 2,8 s):
  - `CycleSummaryService::focus()` não calcula nem consulta snapshot de ciclo que termina antes da primeira data em que o grupo pode ter entrada (a menor entre `date_payment` das despesas não deletadas e `date_expected` das parcelas); os snapshots dos ciclos candidatos vêm de **uma** consulta (`whereIn`), não uma por iteração.
  - **O resultado não muda:** `FocusCycleTest` e os testes `test_focus_*` de `CycleSummaryServiceTest` passam **sem edição**. Um teste novo de equivalência compara `focus()` com uma implementação de referência por força bruta (o laço antigo, escrito no próprio teste) em: grupo vazio; despesa só em setembro; parcelada que começa meses antes; despesa Fixa; ciclo selado no meio; despesa excluída (`deleted`) que não deve contar.
  - **Orçamento de consultas fixado por teste:** com `DB::getQueryLog()`, `forUser` de um usuário com 1 grupo vazio e com 20 grupos vazios fica abaixo de um teto medido na task (ponto de partida: 56 e 1.082), com folga de 20%. O teto e as contagens antes e depois vão para o `implementation.md`.
  - Mutação: remover o curto-circuito derruba o teste de orçamento; trocar a comparação de datas (`<` por `<=`) derruba o de equivalência. Suíte verde e Pint limpo.
- **TASK-428** (decisão D9, adotada em 2026-10-21 com o desenho corrigido do `specify.md` §5):
  - `forUser` consulta os grupos do usuário ordenados por `ex_groups.id` crescente, lê **51** (`limit(51)`), calcula no máximo os **50 primeiros** e a raiz ganha `truncated` (`true` só quando a 51ª linha existia). O corte acontece **antes** de calcular foco e `view`, para limitar o custo; a ordem `has_open` primeiro, depois nome, vale só dentro dos 50. Um grupo com pendência fora dos 50 mais antigos não aparece: é o preço aceito na D9 e o `truncated` avisa o cliente. Campo aditivo; `specify.md` §2.1 e `plan.md` §1.1 atualizados.
  - Testes: 50 grupos → 50 e `truncated` falso; 51 → 50 e `truncated` verdadeiro, com o grupo de maior id fora; **o orçamento de consultas (TASK-427) com 60 grupos vazios é igual ao de 50** (prova que o corte é antes do cálculo); a ordem por pendência se mantém dentro dos 50. Mutação: trocar o teto por 51 derruba o de 51 grupos; mover o corte para depois do cálculo derruba o de orçamento. `OverviewControllerTest` passa a conferir `truncated` na estrutura.
- **TASK-429** (decisão D10, adotada em 2026-10-21):
  - Limiter nomeado (30/min) por `auth('api')->id()`, aplicado à rota depois do `jwt.auth`. Teste: a 31ª chamada do mesmo usuário em um minuto devolve 429; outro usuário no mesmo IP não é afetado; as demais rotas não mudam.
  - **Corrigido na execução (§11 do `implementation.md`):** "depois do `jwt.auth`" não é possível só declarando a ordem. O Laravel executa o `ThrottleRequests` antes do middleware do Tymon (`middlewarePriority`), qualquer que seja a ordem na rota. O critério passa a ser de comportamento: chamada sem token ou com token inválido nunca consome o limite (31 chamadas seguidas devolvem sempre 401), garantido pelo próprio limiter (`Limit::none()` sem usuário).
  - O `route:list` mostra o `throttle` na rota. Mutação: tirar o middleware derruba o teste do 429.
- **TASK-430** (achado S3):
  - Com dois membros de mesmo nome no grupo, um item de snapshot legado (sem `participantDetails`) **não é atribuído a nenhum dos dois por nome**; com nome único continua resolvendo (teste existente `test_items_of_an_old_snapshot_…` segue verde sem edição).
  - Mutação: voltar ao `pluck('id','name')` com "o último vence" derruba o teste novo.
- **TASK-431** (achado S4):
  - `OverviewController::show` faz `abort_unless($user, 401)` antes de chamar o serviço. Teste: chamar o controller sem usuário autenticado lança `HttpException` 401 (não `TypeError`). Mutação: remover o `abort_unless` derruba o teste.
- **TASK-432** (achado S7):
  - Testes novos em `OverviewControllerTest`/`PersonalOverviewServiceTest`: (a) **ex-membro:** usuário que aparece em `payers` de despesa de um grupo mas não é mais membro não vê esse grupo; (b) **credor que saiu:** `to_user` vem com `name` "Desconhecido" e `email` e `pix` nulos; (c) a asserção de que e-mail alheio não vaza passa a usar um grupo alheio em que o usuário consta em `payers` sem ser membro (hoje ela não consegue falhar); (d) token de usuário removido devolve 401.
  - Cada teste novo falha se a proteção correspondente for removida (mutação por teste, registrada no `implementation.md`).
- **TASK-433** (achado 1 da TASK-423; opção B recomendada, aprovada pelo usuário com "siga" em 2026-10-22):
  - O cartão ganha um quarto total, **"Já recebido"**, com `receive.confirmed` (o payload já traz; **sem mudança de backend nem de contrato**). Os quatro ficam na ordem "A pagar", "A receber", "Já confirmado", "Já recebido"; em 375 px formam duas colunas, e de `sm` para cima ficam numa linha.
  - O "Já recebido" conta os **comprovantes já enviados** pelos devedores (`confirmed_at`), não dinheiro conferido: o sistema não tem confirmação de recebimento pelo credor. Fica anotado no `plan.md` §2.
  - `GroupPositionCard.test.tsx`: "Já recebido" mostra `receive.confirmed` (não `receive.open`) e fica "R$ 0,00" sem comprovantes; os três totais que já existiam seguem iguais, **sem edição das asserções atuais**. Mutação: usar `receive.open` no lugar de `receive.confirmed` derruba o teste.
  - Conferido no navegador na stack da TASK-423 (o Consórcio mostra "Já recebido R$ 250,00") e em 375 px sem scroll horizontal. `tsc` sem erro e suíte do frontend verde.
- **TASK-449** (decisão D12; achado da TASK-424, `implementation.md` §25):
  - `git merge origin/dev` na branch da feature (merge commit, sem rebase), feito a partir de uma branch de task `backend/20261010-painel-financeiro-pessoal-TASK-449` e integrado com `--no-ff`. `git fetch origin dev` imediatamente antes, para pegar o que entrou depois do §25.
  - **`ExpenseController.php`:** a versão da feature (métodos de cálculo removidos) prevalece, e as linhas da `dev` são portadas para o `CycleSummaryService`: `paymentNote` nas **três** entradas de `entries()` e no mapa de `compute()`, `confirmedNote` em `withConfirmations()`. `pay()`, `unpay()` e `confirmSettlement()` ficam como a `dev` os deixou. Nenhum teste da `dev` é editado.
  - **`docs/backlog/README.md`:** as linhas dos dois lados ficam; conferir de novo que a `dev` não usa os IDs 082 e 083 (`git grep -E "^ID: 08[2-9]" origin/dev -- docs/backlog`) e, se usar, renumerar os meus (arquivos e índice).
  - **`Payments.tsx`:** resolvido a favor da `dev` (os diálogos viram `PaymentProofDialog`); o que a TASK-415/416 tinha feito ali deixa de existir. O `ProofUploadDialog` e o `useConfirmSettlement` **continuam no repositório** até a TASK-450 (o Painel ainda os usa), então o `tsc` pode acusar `GroupPositionCard` por causa do `handlePay(id, details)` novo: a correção mínima fica na TASK-450, mas a TASK-449 só fecha com `tsc` e suítes verdes, então ela inclui o ajuste mínimo para compilar.
  - **Provas:** suíte inteira do backend verde (as 525 da feature e as testes novos da `dev`), `./vendor/bin/pint --test` limpo, `php artisan migrate` aplicado no banco de teste e no `painel_validacao` (2 migrações novas), `ExpenseControllerSummaryTest` e `PaymentNotePersistenceTest` verdes **sem edição**, e um teste novo que confirma `paymentNote` e `confirmedNote` pelo `CycleSummaryService` (mutação: tirar uma das quatro linhas portadas derruba o teste dessa entrada).
  - Nenhum arquivo da `dev` fica com diferença além da resolução dos 3 conflitos (`git diff origin/dev --stat` depois do merge só mostra o trabalho da feature).
- **TASK-450** (decisão D13; **D14: a descrição não é exibida no Painel**, só enviada):
  - O `GroupPositionCard` troca o `ProofUploadDialog` pelo `PaymentProofDialog` nos dois fluxos: **acerto do devedor** com `requirePhoto: true` e **marcar como paga** do credor com `requirePhoto: false` (foto ou descrição). `handlePay(id, details)` recebe `{ proof?, note? }`; o `useConfirmSettlement` passa a mandar `descricao` quando houver nota. O `cycles_ago` do payload continua nos dois.
  - `ProofUploadDialog.tsx` e o teste dele são **removidos** (nenhum importador sobra: `grep` vazio), e o `useConfirmSettlement.test.tsx` ganha o caso da descrição. Os testes de `GroupPositionCard` e `MyPanel` são adaptados ao diálogo novo, e **mutação**: fixar `cycles_ago` em `0` continua derrubando os testes de comprovante, de marcar e de desfazer.
  - `specify.md` §5: a D7 já está registrada como "foto ou descrição". `plan.md` §3 e `tasks.md` (critérios das TASK-415 e 420) ganham nota de que o `ProofUploadDialog` foi substituído.
  - `tsc` sem erro, suíte do frontend verde.
- **TASK-451**:
  - Com o merge e o `migrate` feitos, no navegador: devedor envia comprovante **com descrição** no Painel (e a nota chega ao banco em `note`), credor marca como paga **só com descrição** e **só com foto** (o botão só habilita com um dos dois) e desfaz (a nota é limpa), e o mesmo acerto pela tela de Pagamentos. Reconciliação com o `/summary` de novo (6 de 6) e conferência de que `paymentNote`/`confirmedNote` continuam saindo do `summary`.
  - Resultado de cada item no `implementation.md`. O usuário segue com o gate de confirmar por escrito a validação local.
