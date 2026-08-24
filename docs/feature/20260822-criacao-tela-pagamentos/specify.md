# Specify — Tela de Pagamentos

> Feature: cria uma tela dedicada de "Pagamentos" (item de menu já existente, hoje sem rota) que lista as despesas de uma competência selecionável, mostrando credor/valor total/valor por pessoa/pagadores, e permite confirmar o pagamento anexando uma foto de comprovante. Origem: pedido novo do usuário, conectado à TASK-018 (`docs/sdd/03-tasks.md` Épico D — "decidir e, se aprovado, implementar persistência real de `Participation`/status pago-pendente"), cuja decisão de produto é o próprio pedido descrito aqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Problema

O item de menu "Pagamentos" já existe na navegação (`frontend/src/layouts/simpleNavItems.ts:18`, `frontend/src/layouts/group/GroupSidebar.tsx:32`), mas sem `to` — não leva a lugar nenhum hoje. Não existe nenhuma tela dedicada a listar, por competência, as despesas com os dados necessários para quem precisa pagar sua parte (credor, valor total, valor por pessoa, quem participa) e confirmar que pagou anexando uma foto do comprovante.

O mecanismo de "marcar como paga" já existe no backend e tem UI própria, mas só embutido dentro da tela de Despesas (`ExpenseManager.tsx`) — sem seletor de mês dedicado e sem qualquer captura de comprovante. A tela pedida aqui é uma superfície nova, não uma substituição dessas.

## 2. Achados confirmados

### 2.1 Backend já expõe "marcar pago"/"desfazer pagamento" por despesa, sem foto

`POST /expenses/{expenseId}/pay` e `POST /expenses/{expenseId}/unpay` (`backend/app/Http/Controllers/ExpenseController.php:514-582`; rotas em `backend/routes/api.php:40-41`) já marcam `Quota.paid = true/false` (+ `paid_at`, `paid_by`) na competência vigente do grupo. Regras já implementadas: só quem é `user_payer_id` (o credor da despesa) pode confirmar/desfazer (`abort(403, ...)` senão); só funciona com a competência aberta (`rejectIfCompetenceClosed`); despesa `FIXED` é materializada em `Quota` real antes de marcar. Nenhum dos dois endpoints recebe corpo/arquivo hoje — `pay($expenseId)` não tem `Request` de dados, só o parâmetro de rota.

### 2.2 `GET /groups/{groupId}/expenses/summary` já devolve quase todos os campos pedidos, por despesa

`ExpenseController::summary`/`computeCycleSummary` (`ExpenseController.php:373-415`, `645-755`) devolve, por competência, `expenses[]` com `description`, `value` (valor total), `paid`, `payerName` (credor), `participants` (nomes de quem divide a despesa), `userPayerId`/`userCreatorId`. Falta pronto apenas "valor por pessoa" — hoje só dá pra derivar no cliente (`value / participants.length`), replicando a mesma divisão igualitária já usada em `computeCycleSummary` (`$valuePerPerson = $entry['value'] / $participantsCount`, linha 691). Tipos já espelhados no frontend em `frontend/src/hooks/useGroupCycle.ts:15-26` (`SummaryExpense`).

### 2.3 Seleção de "mês" já existe como conceito de ciclo (`BillingCycle`), não mês calendário solto

`useGroupCycle.ts` (`frontend/src/hooks/useGroupCycle.ts`) já implementa navegação por `cycles_ago` (ciclo anterior/próximo) sobre `GET .../expenses/summary`, hoje consumido por `GroupSummary.tsx` e `ExpenseManager.tsx`. `cycles_ago = 0` é sempre a competência vigente (a que contém "hoje"), que pode estar com status `open` (ainda não fechada) — não é necessariamente "o fechamento mais recente" pedido pelo usuário. **Decisão de produto pendente**: "sempre trazendo primeiro o fechamento mais recente" significa (a) a competência vigente mesmo se `open`, igual ao padrão já usado nas outras telas, ou (b) pular a aberta e mostrar a última com status `closed`/`closed_manually`? Isso muda o valor inicial de `cycles_ago` calculado pela tela nova.

### 2.4 "Marcar como paga" já tem UI de referência, só que dentro da tela de Despesas

`ExpenseManager.tsx:97-138,493-514` já tem os botões "Marcar como paga"/"Desfazer pagamento" chamando os endpoints de 2.1, com toast de sucesso/erro (`ExpenseManager.tsx:707-712`) e cobertura de teste (`ExpenseManager.test.tsx:680-876`, inclusive o cenário `canUnpay`/`isCreditor`). É o padrão de referência para a ação "confirmar pagamento" da tela nova — mas sem nenhuma captura de foto.

### 2.5 Não existe hoje nenhum mecanismo de upload de arquivo/foto no backend

Nenhum controller trata `multipart/form-data` ou grava arquivo em disco/Storage hoje (busca em `backend/app` não encontrou nenhum uso de `Storage::`, `UploadedFile` ou disco de arquivos). O avatar de usuário (feature `docs/feature/20260822-atualizacao-minha-conta/`) vem pronto da conta Google como URL — não é upload local, não serve de precedente de implementação. "Foto do comprovante, conforme fechamento" é capacidade nova de ponta a ponta: precisa decidir onde persistir a referência (coluna nova em `ex_quotas`? tabela própria de comprovantes, 1:N se permitir mais de uma foto?), disco de armazenamento (`local`/`public`/S3) e limites (tamanho máximo, formatos aceitos, o que acontece se `unpay()` for chamado depois — a foto é apagada ou mantida como histórico?).

### 2.6 Item de menu "Pagamentos" já existe, sem rota — padrão de navegação a seguir

`simpleNavItems.ts:18` e `GroupSidebar.tsx:32` já têm a entrada "Pagamentos" (ícone `PaymentsOutlinedIcon`) nos dois menus (com grupo selecionado e sem), mas nenhum dos dois tem `to`. Padrão de rota já usado por telas equivalentes (`App.tsx:22-47`): dentro de `GroupShellLayout`, rota `/groups/:id/<algo>` (ex.: `/groups/:id/summary`); fora dele (`SimpleShellLayout`, sem grupo selecionado ainda), uma tela "Entry" (`SummaryEntry`, `ExpensesEntry`) que resolve o grupo e redireciona. A tela de Pagamentos precisa dos dois: rota `/groups/:id/payments` (ou nome equivalente) + entrada sem grupo selecionado, seguindo o mesmo padrão.

### 2.7 Modelo de pagamento hoje é por despesa/competência inteira, não por pagador individual

`Quota.paid` (`backend/app/Models/Quota.php`) é um único booleano por parcela/competência — representa "esta despesa foi paga" como um todo, confirmado só pelo credor (`user_payer_id`), não "cada pagador confirmou sua parte". `Participation` (`ex_participations`) existe na migration/model com `state` (`PAID`/`PENDING`) mas só se relaciona a `group_id` + `quota_id`, **sem `user_id`** — não tem como representar "fulano, especificamente, pagou sua cota" mesmo se fosse populada. Isso é consistente com `docs/sdd/01-specify.md` §6 (Divergências), que já registra `Participation` como não populada por nenhum endpoint. A lista de "Pagadores" pedida pelo usuário para a tela nova é, portanto, informativa (quem divide a despesa — igual a `participants` de 2.2), não uma lista de checkboxes individuais — a menos que o usuário decida abrir escopo para granularidade por pessoa (ver §3).

## 3. Fora de escopo desta feature

- Confirmação de pagamento por participante individual (per-pagador). O modelo de dados atual (`Quota.paid`) é por despesa/competência inteira, não por pessoa, e `Participation` não tem `user_id` para suportar isso (ver §2.7). Granularidade por pagador é decisão de produto separada, não coberta aqui — a tela mostra os "Pagadores" apenas como informação (quem divide a despesa), reaproveitando `participants` já existente.
- Mudar quem pode confirmar pagamento. Hoje só o credor (`user_payer_id`) pode chamar `pay()`/`unpay()` — a tela nova reaproveita essa regra tal como está, não a revê.
- Alterar `GroupSummary.tsx` (bloco "Despesas do ciclo") ou o fluxo de pagar/desfazer já existente em `ExpenseManager.tsx` — continuam existindo como estão; a tela de Pagamentos é uma superfície nova, não substitui nem remove as outras.
- Liquidação/simplificação par-a-par (`settlements`, "quem paga a quem") — já resolvido pela feature `docs/feature/20260822-acerto-de-contas-ciclo/`, fora do escopo desta tela.
- Notificação (push, e-mail, WhatsApp) avisando que um pagamento foi confirmado — já registrado como fora de escopo geral em `01-specify.md` §5.
