# Tasks — Comprovante e descrição ao marcar como paga

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261021

IDs a partir de TASK-433: o maior em qualquer branch do repositório é TASK-432 (feature `20261010-painel-financeiro-pessoal`, ainda em andamento). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-433 | Criar as migrations `payment_note` (em `ex_quotas`) e `note` (em `ex_settlement_confirmations`) e liberá-las no `$fillable` dos dois modelos | backend | plan.md §1 | antes do deploy/migration em produção | Concluída |
| TASK-434 | Aceitar `descricao` em `pay()` e apagá-la em `unpay()`, com testes PHPUnit | backend | plan.md §2 e §11 | nenhum | Concluída |
| TASK-435 | Aceitar `descricao` em `confirmSettlement()` e gravá-la em `note`, com testes PHPUnit | backend | plan.md §3 e §11 | nenhum | Concluída |
| TASK-436 | Expor `paymentNote` nas despesas e `confirmedNote` nos acertos do summary, com testes PHPUnit | backend | plan.md §4 e §11 | nenhum | Concluída |
| TASK-437 | Revisar o diff de backend com o agent `security-reviewer` e registrar os achados | backend | plan.md §12 | nenhum | Concluída |
| TASK-438 | Criar o componente `PaymentProofDialog` (foto + descrição, regra por `requirePhoto`) com testes | frontend | plan.md §6 e §11 | nenhum | Concluída |
| TASK-439 | Exigir foto ou texto no tipo de `handlePay` e fazer o ✓ de Despesas abrir o popup | frontend | plan.md §5, §7 e §11 | nenhum | Pendente |
| TASK-440 | Trocar o popup do credor em Pagamentos pelo `PaymentProofDialog` e exibir `paymentNote` no cartão | frontend | plan.md §8, §9 e §11 | nenhum | Pendente |
| TASK-441 | Trocar o popup do devedor em Pagamentos pelo `PaymentProofDialog`, enviar `descricao` e exibir `confirmedNote` | frontend | plan.md §8, §9 e §11 | nenhum | Pendente |
| TASK-442 | Exibir a descrição do pagamento no modal de detalhes de Despesas | frontend | plan.md §9 e §11 | nenhum | Pendente |
| TASK-443 | Exibir a descrição do pagamento em `ExpenseView` | frontend | plan.md §9 e §11 | nenhum | Pendente |
| TASK-444 | Atualizar `MANUAL.md` com o popup e o campo de descrição | doc | plan.md §10 | nenhum | Pendente |
| TASK-445 | Registrar em `docs/backlog/` o item "anexar ou editar comprovante e descrição depois de paga" | doc | plan.md §10 | nenhum | Pendente |
| TASK-446 | Rodar o checklist pré-PR completo na branch da feature integrada | backend | plan.md §11 e §13 | nenhum | Pendente |
| TASK-447 | Validar o fluxo localmente na stack isolada e registrar o resultado | frontend | plan.md §13 | antes do merge (o usuário valida localmente antes do push e do PR) | Pendente |
| TASK-448 | Gravar a `descricao` "0" em `pay()` (achado do `security-reviewer` na TASK-437) e cobrir `descricao` em formato de array | backend | plan.md §2, §11 e §12 | nenhum | Concluída |

Notas de composição:

- **TASK-439 não se divide.** Trocar a assinatura de `handlePay` para exigir `PaymentDetails` quebra o `tsc` em todos os chamadores. O único chamador sem detalhes é o ✓ de `ExpenseManager`, que precisa do popup para ter o que passar; o chamador em `Payments.tsx` (`handlePay(confirmExpenseId, selectedFile)`) recebe só o ajuste mecânico de assinatura (`{ proof: selectedFile }`), sem mudar comportamento nem trocar o diálogo (isso é TASK-440). Separar essas partes deixaria `tsc` vermelho entre as tasks.
- **TASK-440 e TASK-441 mexem no mesmo arquivo** (`Payments.tsx`); rodam em sequência na mesma ordem da tabela, nunca em paralelo.
- **TASK-437 e TASK-446 são tasks de verificação**, sem escopo de funcionalidade: o que elas acham e exige correção vira task nova abaixo de TASK-447, não expande a task que a originou (`docs/sdd/04-implementation.md` §1, item 2).

## Dependências e ordem

```
433 ─┬─ 434 ─┐
     └─ 435 ─┴─ 436 ── 437
438 ── 439 ── 440 ── 441 (também depende de 435 e 436)
439 ── 442 (também depende de 436)
433 ── 443
439, 440, 441 ── 444
445 (independente)
todas ── 446 ── 447
```

- TASK-436 depende de 434 e 435: os testes de summary precisam que `pay` e `confirmSettlement` gravem a nota.
- TASK-448 nasceu na revisão da TASK-437 e executa logo depois dela, antes de TASK-439; depende de 434 e 435.
- Backend (433 a 437) e `PaymentProofDialog` (438) são independentes entre si, mas executo na ordem da tabela para manter um branch por vez. Os testes de frontend usam mocks, então não esperam o backend; a conferência ponta a ponta é TASK-447.
- Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: TASK-433 direto em `feature/20261021-comprovante-ao-marcar-paga` (já criada); as demais em `<tipo>/20261021-comprovante-ao-marcar-paga-TASK-0xx` a partir da branch da feature, integradas com `git merge --no-ff`, sem PR. Um único PR da branch da feature contra `dev` no fim.

## Critérios de aceite

- **TASK-433**:
  - Em banco descartável (MySQL na 3307, nunca a 3306 do projeto rota): `php artisan migrate --env=testing --force` cria `ex_quotas.payment_note` e `ex_settlement_confirmations.note`, ambos `varchar(500)` nullable, depois de `payment_proof_path` e `proof_path`; `php artisan migrate:rollback --step=2 --env=testing --force` remove as duas colunas e nada mais. Conferido com `Schema::hasColumn` e `SHOW COLUMNS`.
  - Teste PHPUnit novo que grava `Quota` com `payment_note` e `SettlementConfirmation` com `note` por atribuição em massa e lê de volta os mesmos valores.
  - Migration só aditiva (Constitution §4.2): o `down()` remove apenas a coluna acrescentada; nenhuma coluna existente é alterada. `./vendor/bin/pint --test` limpo.
  - O gate humano é respeitado: nenhuma migration roda em banco compartilhado ou de produção por esta task.
- **TASK-434**:
  - `cd backend && php artisan test --filter=ExpenseControllerPayTest` verde, com testes novos que provam: (a) pagar só com `descricao` marca a quota como paga e grava `payment_note`, com `payment_proof_path` nulo; (b) foto + `descricao` gravam os dois; (c) `descricao` de 501 caracteres responde 422 e a quota não muda; (d) `descricao` só com espaços grava `payment_note` nulo; (e) `unpay` zera `payment_note` e `payment_proof_path` e apaga o arquivo; (f) novo `pay` sem `descricao` numa quota que já tinha nota não a apaga; (g) **`pay` sem corpo continua respondendo 200** (teste nomeado e comentado com D1, `specify.md` §3.3).
  - Os testes que já existiam no arquivo (`test_pay_without_file_leaves_payment_proof_path_null`, `test_creditor_can_pay_in_cash_expense`, `test_pay_com_comprovante_notifica_pagadores_opt_in`, `test_pay_sem_comprovante_nao_notifica`) passam sem edição.
  - O docblock de `pay()` deixa de dizer que o `ExpenseManager` chama sem corpo e passa a registrar a API permissiva (Constitution §4.1) e a regra no cliente; o de `unpay()` cita a descrição.
  - `pay` com descrição não dispara WhatsApp quando não há foto (o gatilho continua em `payment_proof_path`). `./vendor/bin/pint --test` limpo.
- **TASK-435**:
  - `cd backend && php artisan test --filter=SettlementConfirmationControllerTest` verde, com testes novos que provam: (a) `descricao` válida é gravada em `ex_settlement_confirmations.note`; (b) reenviar o comprovante sem `descricao` limpa a nota anterior; (c) reenviar com outra `descricao` a substitui; (d) 501 caracteres responde 422 e nada é gravado; (e) sem `comprovante` continua 422 mesmo com `descricao` (foto segue obrigatória, R2).
  - Os testes que já existiam no arquivo passam sem edição. `./vendor/bin/pint --test` limpo.
- **TASK-436**:
  - `cd backend && php artisan test --filter="ExpenseControllerSummaryTest|SettlementConfirmationControllerTest|ExpenseControllerPayTest"` verde, com testes novos que provam: (a) `GET /groups/{id}/expenses/summary` devolve `paymentNote` com o texto para despesa à vista paga e para parcela de `IN_INSTALLMENTS` paga, e `null` para não paga; (b) devolve `paymentNote` para ocorrência `FIXED` materializada; (c) cada item de `settlements` devolve `confirmedNote` com a nota, ou `null` quando não há confirmação ou não há nota; (d) ciclo selado, servido do snapshot, não quebra e `confirmedNote` continua ao vivo.
  - O `@return` do docblock de `collectCycleEntries()` cita `paymentNote`. Nenhum campo existente do summary muda de nome ou de tipo (diff revisado). `./vendor/bin/pint --test` limpo.
- **TASK-437**:
  - O agent `security-reviewer` roda sobre o diff de `backend/` desta feature e o resultado é registrado no `implementation.md`, achado por achado, com o veredito (corrigir, aceitar ou backlog) e a justificativa.
  - Itens conferidos explicitamente: autorização de `pay`/`unpay`/`confirmSettlement` inalterada; `max:500` no servidor; a descrição não entra em e-mail, WhatsApp nem notificação in-app; nenhum log novo registra a descrição.
  - Achado que exige correção vira task nova (TASK-448 em diante) e não é resolvido dentro desta task.
- **TASK-438**:
  - `cd frontend && npx vitest run src/components/PaymentProofDialog.test.tsx` verde, com casos que provam: (a) `Confirmar` desabilitado sem foto e sem texto; (b) com `requirePhoto=false`, só texto habilita e só foto habilita; (c) texto só com espaços não habilita; (d) com `requirePhoto=true`, texto sozinho **não** habilita e foto habilita; (e) `busy` desabilita o botão; (f) `onConfirm` recebe `{ file, note }` com `note` já aparado; (g) o campo tem `maxLength` 500 e o contador mostra `n/500`; (h) foto e texto voltam a vazio ao reabrir; (i) o `error` recebido aparece num `Alert`.
  - O helper puro `canConfirmPayment` é exportado e coberto por teste unitário próprio. O componente não usa `dangerouslySetInnerHTML` nem `any`.
  - `npx tsc --noEmit` sem erro. Nenhuma tela foi alterada nesta task.
- **TASK-439**:
  - `cd frontend && npx tsc --noEmit` sem erro; `handlePay(exp.id)` (sem detalhes) **não compila**, verificado ao reintroduzir a chamada temporariamente e ver o erro de tipo (registrado no `implementation.md`). `PaymentDetails` exige `proof` ou `note`.
  - `npx vitest run src/hooks/usePaymentActions.test.tsx` verde, provando: `FormData` com `comprovante` + `descricao` + `cycles_ago`; JSON `{ cycles_ago, descricao }` quando só há texto; a chave `descricao` ausente quando o texto é vazio ou só espaços.
  - `npx vitest run src/pages/ExpenseManager.test.tsx` verde, provando: (a) **clicar no ✓ não dispara `POST` e abre o popup**, na tabela e nos cartões; (b) confirmar só com texto envia `descricao`; (c) confirmar com foto envia `FormData`; (d) cancelar não envia nada. Os testes que clicavam em "Marcar como paga" e esperavam o `POST` direto atravessam o popup.
  - `Payments.tsx` muda só no ajuste mecânico de assinatura (`{ proof: selectedFile }`); `npx vitest run src/pages/Payments.test.tsx` continua verde sem edição de asserção.
  - O JSDoc de `usePaymentActions.ts` não diz mais "um clique, sem comprovante". `SummaryExpense.paymentNote?` e `SummarySettlement.confirmedNote?` existem em `useGroupCycle.ts`.
- **TASK-440**:
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx` verde, provando: (a) o popup do credor habilita `Confirmar` só com texto (D2) e continua habilitando com foto; (b) o `POST /pay` leva `descricao` quando há texto; (c) o cartão da despesa paga mostra `paymentNote`, com ou sem foto, e não mostra o link "Ver comprovante" quando não há foto.
  - No `git diff`, os estados `selectedFile` e `fileInputRef` e o `<Dialog>` do credor saíram de `Payments.tsx`. `npx tsc --noEmit` sem erro.
- **TASK-441**:
  - `cd frontend && npx vitest run src/pages/Payments.test.tsx src/components/PayableSettlementList.test.tsx` verde, provando: (a) o popup do devedor exige foto (texto sozinho não habilita); (b) o `FormData` de `settlements/confirm` leva `descricao` quando há texto e leva `cycles_ago`; (c) o erro do servidor aparece dentro do popup e o popup continua aberto; (d) o acerto com `confirmedNote` mostra o texto abaixo do chip "Comprovante enviado".
  - No `git diff`, `selectedSettlementFile` e o `<Dialog>` do devedor saíram de `Payments.tsx`. `npx tsc --noEmit` sem erro.
- **TASK-442**:
  - `cd frontend && npx vitest run src/pages/ExpenseManager.test.tsx` verde, provando: o modal de detalhes de uma despesa paga com `paymentNote` mostra "Descrição do pagamento" e o texto; uma despesa paga **só com descrição** não mostra o ícone "Ver comprovante" na lista; uma despesa não paga ou sem nota não mostra o bloco.
  - O texto aparece como texto simples: um teste com `<b>x</b>` na nota exibe a marcação literal. `npx tsc --noEmit` sem erro.
- **TASK-443**:
  - `cd frontend && npx vitest run src/pages/ExpenseView.test.tsx` verde, provando: (a) a nota da quota paga mais recente aparece ao lado do link "Ver comprovante"; (b) só com nota, sem foto, aparece a nota e não aparece o link; (c) sem nota, nada novo aparece.
  - `npx tsc --noEmit` sem erro.
- **TASK-444**:
  - `MANUAL.md` descreve, na seção Despesas, que "marcar como paga" abre um popup que pede foto ou descrição, e, na seção Pagamentos, o campo de descrição nos dois popups (credor e devedor). Texto em português, no mesmo tom das seções vizinhas.
  - `grep -n "marcar como paga" MANUAL.md` não deixa nenhuma frase que sugira pagamento sem popup. O `implementation.md` registra que `MANUAL.pdf` não foi regenerado (sem rotina no repositório) como pendência manual.
- **TASK-445**:
  - Existe um arquivo novo em `docs/backlog/` no formato de `docs/backlog/README.md`, com contexto, risco, regras adotadas e decisões em aberto em tabela (opções, recomendação e por quê), para "anexar ou editar comprovante e descrição depois de paga" (specify §4, item 6 da auditoria), e a linha correspondente no índice `docs/backlog/README.md`, com o próximo ID livre.
  - O item cita a lacuna concreta: `canPay` exige `!exp.paid` (`usePaymentActions.ts`), então hoje só se consegue desfazendo o pagamento.
- **TASK-446**:
  - Na branch da feature integrada, os comandos abaixo rodam e o resultado de cada um é registrado no `implementation.md`: `cd backend && ./vendor/bin/pint --test`; `php artisan test` (suíte completa, contra o banco descartável da 3307); `cd frontend && npx tsc --noEmit`; `npx vitest run` (suíte completa); `grep -rn dangerouslySetInnerHTML frontend/src` vazio.
  - `git diff dev...HEAD` revisado: nenhum segredo, nenhum `.env.testing` e nenhum arquivo fora do escopo da feature.
  - O agent `pr-readiness-checker` roda e seu relatório é anexado ao `implementation.md`. Qualquer falha que não seja trivial vira task nova (TASK-448 em diante).
- **TASK-447**:
  - Roteiro executado na stack isolada (MySQL 3307, backend 8001, Vite 3001; nunca a 3000/8000 do usuário), com o resultado de cada passo registrado no `implementation.md` com o que foi observado: Despesas → ✓ abre o popup, `Confirmar` desabilitado sem foto e sem texto, só texto habilita, só foto habilita; marcar com só descrição → "Paga", texto no modal de detalhes e sem ícone de comprovante; marcar com foto + texto → ícone "Ver comprovante" e texto visíveis; desfazer → foto e texto somem; Pagamentos (credor) → mesmo popup e mesma regra; Pagamentos (devedor, ciclo fechado) → foto obrigatória + descrição, "Reenviar" substitui a descrição; `ExpenseView` mostra a nota; largura de 375px sem scroll horizontal; modo cartões e modo tabela em Despesas.
  - `curl -X POST .../api/expenses/{id}/pay` sem corpo continua respondendo 200 (compatibilidade, D1), registrado.
  - Stack isolada encerrada ao final: processos do backend e do Vite derrubados, `docker rm -f mysql-expense-test`, `backend/.env.testing` apagado (não é ignorado pelo git), portas 3307/8001/3001 liberadas.
  - O usuário confirma por escrito que validou localmente. Sem essa confirmação, **não há push nem PR**.
- **TASK-448** (nasceu na TASK-437; é a única task fora da ordem numérica: executa logo depois dela e antes de TASK-439):
  - Teste novo em `ExpenseControllerPayTest` que falha antes da correção: `pay` com `descricao` igual a `"0"` responde 200 **e** grava `payment_note = "0"`. Hoje o `if (! empty($data['descricao']))` (`ExpenseController.php:1077`) descarta o valor porque `empty("0")` é verdadeiro em PHP, e o credor quitaria sem nenhuma prova; `confirmSettlement` (`?? null`) já grava `"0"`, então os dois endpoints ficam consistentes depois da correção.
  - Teste novo em `SettlementConfirmationControllerTest` que grava `descricao` igual a `"0"` em `note` (guarda de regressão; já passa antes).
  - Testes novos de `descricao` em formato de array (`descricao[]=x`, em multipart e em JSON) → 422 com erro em `descricao` e nada gravado, em `pay` e em `confirmSettlement`.
  - `cd backend && php artisan test --filter="ExpenseControllerPayTest|SettlementConfirmationControllerTest"` verde e `./vendor/bin/pint --test` limpo. A correção usa `filled($data['descricao'] ?? null)` (ou equivalente) e não altera a validação.
