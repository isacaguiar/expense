# Specify — Grid de Pagamentos com Pix (QR Code + Copia e Cola)

> Feature: reorganiza `Payments.tsx` em duas regiões (despesas do ciclo | valores a pagar por pessoa) e torna cada valor a pagar clicável — se o credor tiver chave Pix cadastrada, abre um diálogo com QR Code e copia-e-cola gerados pela API já existente (`GET /pix/generate`). Origem: pedido novo do usuário nesta conversa ("A tela de pagamento pode ser dividida em grid..."), sem task/épico prévio em `03-tasks.md`.

Versão: 1.0 · Criado em: 20260825

---

## 1. Problema

`Payments.tsx` (`docs/feature/20260822-criacao-tela-pagamentos/`) hoje é uma lista única (`Stack`) de despesas do ciclo — cada card mostra Despesa/Credor/Valor Total/Valor por pessoa/Pagadores e, só para o credor, o botão de confirmar pagamento (com foto). Não há nenhuma visão organizada "por pessoa" (quanto cada um deve pagar, a quem) nem qualquer integração com Pix nessa tela — a única forma de pagar hoje é fora do app. O usuário quer: (1) layout em grid com uma região por despesa e outra região por pessoa, e (2) ao clicar num valor a pagar, se o credor tiver Pix cadastrado, abrir QR Code + copia-e-cola pra pagar direto.

## 2. Achados confirmados

### 2.1 Liquidação par-a-par já existe e já é exposta pelo summary — é o candidato natural para "valores a pagar por pessoa"

`GET /groups/{groupId}/expenses/summary` já devolve `settlements: [{from_user_id, to_user_id, amount}]` (`useGroupCycle.ts:36-40`), a liquidação líquida já calculada pelo backend (um valor por par devedor→credor, já compensando os dois sentidos). Já existe um componente pronto que renderiza exatamente isso — `components/SettlementList.tsx` — usado hoje em `SummarySidePanel` (`GroupSummary.tsx`/`ExpenseManager.tsx`, aba "À pagar"). "Valores a pagar por pessoa" nesta feature usa a mesma fonte de dado — sem cálculo novo no backend.

### 2.2 `GET /pix/generate` já existe, autenticado, com checagem de grupo compartilhado — reaproveitado sem mudança

`backend/app/Http/Controllers/PixController.php:15-48`, rota `Route::get('/pix/generate', ...)` dentro do grupo `jwt.auth` (`routes/api.php:28`). Recebe `email` + `valor` (query string), devolve `{qrcode: "data:image/png;base64,...", copiacola: "<payload>"}` se: o usuário autenticado é o próprio dono do e-mail OU compartilha um grupo com ele (`compartilhaGrupo()`); e o alvo tem `pix` preenchido (senão 400 `"Usuário não tem chave Pix."`) — testado em `PixControllerTest.php` (4 casos: 401 sem auth, 200 pra si mesmo, 200 pra membro do mesmo grupo, 403 pra estranho). Nenhuma mudança de backend necessária pra gerar o QR/copia-e-cola.

### 2.3 `GET /groups/{groupId}/members` já expõe `email` e `pix` de cada membro — dado que falta pro clique funcionar

`GroupMemberController@index` (`backend/app/Http/Controllers/GroupMemberController.php:16-25`) devolve `$group->members` serializado direto — `App\Models\User::$hidden` (`app/Models/User.php:36-40`) só esconde `password`/`remember_token`/`google_id`; `email` e `pix` (coluna nullable, migration `2025_06_13_012846_add_pix_to_users_table.php`) trafegam no JSON hoje, só que o frontend nunca declarou esses campos no tipo usado (`ExpenseForm.tsx`/`ExpenseView.tsx` tipam só `{id, name}`). `Payments.tsx` precisa buscar esse endpoint (mesmo padrão já usado em `ExpenseForm.tsx:79-85`) e tipar `email`/`pix` pra saber, por `user_id`, se o credor de um settlement tem Pix cadastrado e qual o e-mail pra chamar `/pix/generate`.

### 2.4 Cadastro de chave Pix já existe em outro lugar — fora de escopo

`frontend/src/pages/Profile.tsx` já tem o formulário de cadastro/edição da chave Pix (`POST /user/pix`). Esta feature só consome `pix`/`email`, não cria nem edita.

### 2.5 `Payments.tsx` hoje não usa o tema visual escopado de Despesas

Diferente de `ExpenseManager.tsx`/`ExpenseView.tsx`/`ExpenseForm.tsx`/`ExpensesEntry.tsx` (`docs/feature/20260825-redesign-visual-despesas/`, já em `dev`), `Payments.tsx` não está envolvido por `DespesasThemeScope` — hoje usa cor azul padrão do MUI enquanto o resto do fluxo de Despesas já é verde/pill. Pedido explícito do usuário ("para este layout use o impeccable") e a proximidade temática com as telas já redesenhadas tornam natural reaproveitar o mesmo tema aqui.

### 2.6 `usePaymentActions` já expõe `currentUserId` e a lógica de quem pode confirmar pagamento — não muda

`Payments.tsx:38-64` já resolve `currentUserId` (via `/api/me`) e usa `usePaymentActions` pra `canPay`/`canUnpay`/`handlePay`/`handleUnpay` — mantidos exatamente como estão; a região de despesas (2.1 do layout) é a mesma lista de hoje, só reorganizada visualmente dentro do grid.

### 2.7 Ampliação pedida pelo usuário após a primeira entrega: confirmação de pagamento do devedor é um conceito **novo e distinto** da confirmação de despesa do credor

Depois da primeira versão desta feature (região "Valores a pagar" só com o botão de Pix), o usuário pediu explicitamente um botão de "enviar comprovante para confirmar o pagamento" **nessa mesma região**, e esclareceu que são dois fluxos diferentes: "São pagamentos distintos, a despesa o credor é o responsável, no pagamento do devedor ele que deve enviar o comprovante após o pagamento do pix." Ou seja:

- Confirmação de **despesa** (já existente, inalterada): só o credor (`user_payer_id`) confirma, marcando `Quota.paid`.
- Confirmação de **settlement** (nova): o **devedor** (`from_user_id` do settlement) confirma que ele mesmo pagou via Pix, anexando o comprovante — ação dele, sobre o pagamento dele, sem relação com `Quota.paid` de nenhuma despesa específica.

Um settlement não corresponde a uma única despesa (é o líquido de todas as despesas em aberto entre duas pessoas no ciclo — achado 2.1), então essa confirmação não tem onde morar no modelo hoje: precisa de uma entidade nova.

### 2.8 Bug encontrado: comprovante de despesa paga não aparece nas telas de Despesas

`paymentProofUrl` (já calculado pelo backend, já no tipo `SummaryExpense` de `useGroupCycle.ts`) só é lido em `Payments.tsx` — `ExpenseManager.tsx` (listagem/modal de detalhe) e `ExpenseView.tsx` (detalhe de uma despesa) nunca renderizam esse campo, mesmo quando a despesa está paga e tem comprovante. Achado tangencial já registrado (não corrigido) em `docs/feature/20260822-criacao-tela-pagamentos/implementation.md` TASK-008: em ambiente local o `payment_proof_url` resolvia pra porta errada (`APP_URL` sem porta) — mas isso é sobre a URL estar errada quando o link existe; aqui o problema é mais simples, o link nem é renderizado nessas 2 telas.

## 3. Requisitos

- **R1**: `Payments.tsx` vira um grid de duas regiões: (A) lista de despesas do ciclo — o mesmo conteúdo/comportamento de hoje (Despesa, Credor, Valor Total, Valor por pessoa, Pagadores, botões confirmar/desfazer pagamento com foto); (B) lista de "valores a pagar por pessoa" — os `settlements` do ciclo (achado 2.1), um item por par devedor→credor com o valor líquido.
- **R2**: Cada item da região B é clicável. Ao clicar, se o credor (`to_user_id`) tiver `pix` cadastrado (achado 2.3), abre um diálogo chamando `GET /pix/generate?email=<email do credor>&valor=<amount do settlement>` (achado 2.2) e mostra o QR Code (`qrcode`, imagem base64) e o copia-e-cola (`copiacola`, texto com botão de copiar pro clipboard).
- **R3**: Se o credor não tiver `pix` cadastrado, o clique não chama a API (evita a resposta 400 previsível) — mostra uma mensagem informando que esse credor ainda não cadastrou uma chave Pix, sem abrir o diálogo de QR.
- **R4**: Erros da chamada a `/pix/generate` (403, 400, rede) mostram mensagem amigável no próprio diálogo, sem quebrar a tela.
- **R5**: Layout (grid, cards, diálogo do QR, botão de copiar) usa o tema visual escopado já existente (`DespesasThemeScope`, achado 2.5) e segue a orientação de design da skill `impeccable`, por pedido explícito do usuário.
- **R6**: Nenhum comportamento hoje existente na região de despesas (R1-A) muda — mesmas condições `canPay`/`canUnpay`, mesmo fluxo de confirmação com foto.

- **R7**: Cada settlement onde o usuário autenticado é o **devedor** (`from_user_id === currentUserId`) ganha um botão "Enviar comprovante" — abre um diálogo de upload de imagem (mesmo padrão do diálogo de confirmar despesa já existente) e chama um endpoint novo que grava o comprovante associado a (`grupo`, `competência vigente`, `from_user_id`, `to_user_id`). Não altera `Quota.paid` de nenhuma despesa (achado 2.7).
- **R8**: O `settlement` exposto por `GET .../expenses/summary` (e pelos outros 2 pontos que devolvem `settlements` — `close`/`reopen`) passa a incluir se já tem comprovante enviado (URL) e quando — visível a qualquer membro do grupo (devedor, credor ou terceiro), não só a quem enviou.
- **R9**: Corrige o achado 2.8 — `ExpenseManager.tsx` (listagem e modal de detalhe) e `ExpenseView.tsx` passam a exibir o link "Ver comprovante" quando a despesa está paga e tem `paymentProofUrl`, no mesmo padrão já usado em `Payments.tsx`.

## 4. Fora de escopo desta feature

- Cadastro/edição de chave Pix — já existe em `Profile.tsx` (achado 2.4).
- Pagamento por participante individual dentro de uma despesa (`Quota.paid` continua no nível da despesa, não por pagador) — mesma limitação já documentada em `docs/feature/20260822-criacao-tela-pagamentos/specify.md`, não resolvida aqui.
- Qualquer mudança no cálculo de `settlements`/`balances` no backend — reaproveitado exatamente como está (achado 2.1).
- Confirmar automaticamente uma despesa como paga a partir do pagamento via Pix — o Pix aqui é só uma forma de o usuário efetuar o pagamento fora do app; marcar como pago continua sendo uma ação manual do credor (R6), sem integração de webhook/confirmação automática.
- Alterar a rota `/pix/generate` ou `PixPayload` — reaproveitados sem mudança (achado 2.2).
- Desfazer/apagar uma confirmação de settlement já enviada ("unconfirm") — fica pra um pedido futuro se precisar; o `updateOrCreate` do endpoint novo já permite reenviar (substitui o comprovante anterior), o que cobre o caso de engano mais comum sem precisar de uma ação de exclusão dedicada.
- Qualquer ação do credor sobre a confirmação de settlement do devedor (aprovar/rejeitar o comprovante) — o credor só visualiza (R8); a confirmação da despesa em si continua 100% com o credor, sem relação com isso (achado 2.7).
- Vincular a confirmação de settlement a uma despesa específica ou mudar `Quota.paid` a partir dela — são conceitos deliberadamente distintos (achado 2.7).
