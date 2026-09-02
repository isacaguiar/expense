# Specify — Pagamento em ciclo fechado

> Feature: reorganiza o fluxo de pagamento em torno do fechamento de ciclo — fechar a competência passa a **abrir** a fase de acerto (não encerrá-la), o app leva o usuário para o ciclo fechado que ainda tem pendência, e as listas destacam o que falta pagar. Pedido novo do usuário (conversa de 2026-09-02), sem épico correspondente em `docs/sdd/03-tasks.md`.

Versão: 1.0 · Criado em: 20260902

---

## 1. Problema

Hoje o fechamento de ciclo ("competência", `App\Support\BillingCycle`) funciona ao
contrário do que o usuário espera para a rotina de acerto de um grupo:

- **Pagar é bloqueado depois de fechar.** `pay()`/`unpay()` (`backend/app/Http/Controllers/ExpenseController.php:726` e `:792`) e `confirmSettlement()` (`:839`) chamam `rejectIfCompetenceClosed()` (`:292`) e devolvem 422 assim que a competência fecha — automática (por data) ou manualmente (`GroupCycleSnapshot::closed_manually_at`). Na prática, o grupo fecha o mês e ninguém mais consegue registrar quem pagou.
- **O acerto do devedor não espera o fechamento.** `confirmSettlement()` opera só sobre a competência corrente (`Carbon::now()`, `:849`) e o front (`frontend/src/components/PayableSettlementList.tsx:53`) só checa `isDebtor` — um devedor pode "confirmar" um valor antes de todas as despesas do ciclo entrarem, sobre um número que ainda vai mudar.
- **O app sempre abre no ciclo corrente.** `frontend/src/hooks/useGroupCycle.ts:85` inicia `cyclesAgo=0` (ciclo em andamento, sempre `open`). Um ciclo fechado com conta em aberto não é destacado em lugar nenhum — o usuário precisa saber que existe e navegar para trás com as setas.
- **As listas não separam pago de não pago.** `computeCycleSummary()` ordena despesas só por data (`:990`, `->sortBy('date')`) e os settlements saem na ordem de iteração do `$owed` (`:1053`). O que falta pagar se mistura com o que já foi quitado.
- **Dois itens do menu "sem grupo" nascem mortos.** `frontend/src/layouts/simpleNavItems.ts:17` e `:19` — `Participantes` e `Relatórios` não têm `to`, então `NavList.tsx:97` os renderiza cinza, sem ação. No menu de grupo (`GroupSidebar.tsx:27`) `Participantes` vem antes de `Pagamentos`.

Nenhum cálculo de "todas as contas pagas / todos os devedores quitados" existe no backend
hoje (confirmado: grep vazio por `allPaid|fullyPaid|isSettled|...`).

Não contradiz `00-constitution.md`: as mudanças de API são **aditivas** (§4.1 — novo
parâmetro `cycles_ago`, novo campo `cycle.settled`, rota nova `focus-cycle`); a migration
é **aditiva** (§4.2 — coluna nullable). A regra de negócio nova ganha teste PHPUnit (§2.2).

## 2. Requisitos

### 2.1 Marcar despesa como paga funciona em ciclo fechado

O credor (`user_payer_id`) pode marcar/desmarcar uma despesa como paga **a qualquer
momento**, independente do estado do ciclo — `open`, `closed_manually`, `closed` ou já
quitado. Única exceção: competência **futura** (a `Quota` ainda não existe) → 422.

`pay()`/`unpay()` passam a aceitar o parâmetro `cycles_ago` (inteiro ≥ 0, default 0)
para saber sobre qual competência agir, hoje fixo em `Carbon::now()` (`:739`, `:801`).
A trava `rejectIfCompetenceClosed()` deixa de ser aplicada a esses dois endpoints.
As travas de **edição** de despesa (`update()`, `destroy()`, `store()`,
`stopRecurrence()`) continuam recusando ciclo fechado — sem mudança.

### 2.2 Acerto do devedor só depois do ciclo fechado

`confirmSettlement()` inverte a regra: só aceita o comprovante do devedor quando a
competência-alvo está **fechada** — `status === 'closed'` (por data) ou `closed_manually`
ativo. Com a competência `open` (sem fechamento manual) ou `future` → 422 com mensagem
explicando que o acerto abre após o fechamento. Também aceita `cycles_ago` (≥ 0, default 0).

No front, os botões "Pagar com Pix" / "Enviar comprovante" de
`PayableSettlementList.tsx` só aparecem para o devedor quando o ciclo está fechado;
enquanto aberto, um aviso curto ocupa o lugar ("disponível após o fechamento do ciclo").

### 2.3 Ciclo fechado permanece acionável até a quitação total

Um ciclo fechado (manual ou por data) **continua recalculando ao vivo** e aceitando
`pay`/`unpay`/`confirmSettlement` até estar **totalmente quitado**, inclusive depois da
virada do mês. "Totalmente quitado" = toda entrada da competência com `paid = true`
(`totals.pending == 0`) **e** todo par de `settlements` com uma `SettlementConfirmation`
correspondente.

Ao atingir esse estado, o ciclo é **selado**: nova coluna
`ex_group_cycle_snapshots.settled_at` marca o momento; a partir daí `summary()` serve o
snapshot congelado, o ciclo entra no histórico de Relatórios e sai da rotação do
`focus-cycle` (§2.4). A selagem **não** bloqueia `pay`/`unpay` do credor (§2.1): um
`unpay` que quebra a quitação total **limpa `settled_at`** (dessela) e o ciclo volta a
ser servido ao vivo. `reopen()` (toggle do fechamento manual) recusa 422 se `settled_at`
estiver setado.

Mudança de comportamento consciente: hoje `summary()` congela um ciclo `closed` na 1ª
leitura (`cycleSnapshotFor()`, `:910`) e serve o JSON do `close()` para `closed_manually`
(`:492`). Passa a servir **ao vivo** nesses dois casos enquanto não selado — é o que faz
"pagar depois de fechar" aparecer na tela.

### 2.4 Abrir no ciclo fechado que ainda tem pendência

Ao abrir um grupo, em vez de cair sempre no ciclo corrente, o app cai no **ciclo fechado
mais recente que ainda não está totalmente quitado** (§2.3). Se todos os ciclos fechados
recentes estão quitados, cai no ciclo corrente (`cycles_ago = 0`, comportamento de hoje).

Isso reverte parcialmente `docs/feature/20260821-home-ciclo-corrente-navegacao-futura`
(que fixou o default no ciclo corrente) — apenas quando há pendência num ciclo fechado.
Ponteiro de supersessão a adicionar no `specify.md` daquela feature.

### 2.5 Menu: Pagamentos antes de Participantes

Nos dois arrays de navegação — `groupNavItems` (`GroupSidebar.tsx:27`) e `simpleNavItems`
(`simpleNavItems.ts:13`) — `Pagamentos` passa a vir antes de `Participantes`:
Home → Despesas → **Pagamentos → Participantes** → Relatórios → Configurações → Sair.

### 2.6 Habilitar Participantes e Relatórios no menu sem grupo

No `simpleNavItems` (menu exibido nas rotas sem grupo selecionado), `Participantes` e
`Relatórios` ganham rota e passam a funcionar como os demais itens — redirecionando para
o grupo mais ativo, exatamente como `Pagamentos` já faz hoje (`pages/PaymentsEntry.tsx` +
`pages/mostActiveGroup.ts`). Precisa de duas telas de entrada novas (`MembersEntry`,
`ReportsEntry`) e duas rotas novas (`/members`, `/reports`) no bloco `SimpleShellLayout`
de `App.tsx`.

### 2.7 Listas com o não pago em cima, o pago embaixo

Nas listas de um ciclo, o que falta pagar aparece primeiro; o que já foi quitado desce
para a base — para os **pagamentos abertos ficarem em alerta**. Vale para:

- **Despesas do ciclo**: ordenar por `paid ASC, date ASC` (não pagas primeiro, cronológico
  dentro de cada grupo) em `computeCycleSummary()` (`:967-992`), no lugar do `->sortBy('date')` atual.
- **Acertos / devedores** (`settlements`): não confirmados primeiro, depois por `amount DESC`.
  A informação de confirmação (`confirmedAt`) entra em `attachSettlementConfirmations()`
  (`:1090`), então a ordenação por confirmação é aplicada ali (ou logo depois), nos 3
  pontos que devolvem `settlements` (`summary()`, `close()`, `reopen()`).

A ordenação vive no backend (fonte única; congela junto no snapshot). O front consome a
ordem como vem — conferir que `ExpenseManager.tsx` e `Payments.tsx` não reordenam por cima.

## 3. Fora de escopo desta feature

- **Redesign visual** das telas de Despesas/Pagamentos — só comportamento e ordenação.
- **App Expo** (`expense/app`) — feature só toca `expense/frontend` e `expense/backend`.
- **`/api/v2` ou depreciação** — todas as mudanças de contrato são aditivas (§4.1 da Constitution).
- **Pagamento por participante individual** — `Quota.paid` continua por-despesa, não por-pagador
  (limitação já documentada em `docs/feature/20260822-criacao-tela-pagamentos`).
- **Lembretes / notificações de pendência** — o aviso de WhatsApp já existente (`WhatsAppNotifier`)
  não muda; nenhum novo gatilho de notificação.
- **Paginação / virtualização** das listas ordenadas.
- **Reescrever `close()` para exigir tudo pago antes de fechar** — fechar continua congelando
  o estado que existir; a quitação é o que acontece *depois*.
