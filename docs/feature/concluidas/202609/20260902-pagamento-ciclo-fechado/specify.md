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
`stopRecurrence()`) continuam recusando ciclo fechado — mas "fechado" passa a
significar "depois da janela de carência de 5 dias" (§2.8), não "depois da virada".

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

Isso reverte parcialmente `docs/feature/concluidas/202608/20260821-home-ciclo-corrente-navegacao-futura`
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

### 2.8 Janela de carência: o ciclo fecha 5 dias depois do fim, não na virada

Hoje `BillingCycle::statusOf()` (`backend/app/Support/BillingCycle.php:67`) marca `closed`
assim que `end < agora` — para `closing_day = null` (padrão), o ciclo de um mês trava já
no 1º dia do mês seguinte. Passa a travar só **5 dias corridos depois** do fim do ciclo.

- **F = data de corte** = `end + 5 dias`. Para `closing_day = null`, F cai no dia 5 do mês
  seguinte; para `closing_day = D`, F = dia `D + 5`.
- Entre `end + 1` e `F − 1` o ciclo está **na carência**: `status` continua `open`, edição
  de despesa liberada, tudo como ciclo aberto. Em **F** vira `closed` e as travas de edição
  de §2.1 passam a valer. (É o "R3" do pedido original — edição total na carência, congela
  em F — e sai de graça da mudança em `statusOf()`.)
- Vale para **todos os grupos**, com ou sem `closing_day` personalizado.
- A janela de 5 dias é **constante no código** (`BillingCycle::GRACE_DAYS`) — sem migration,
  sem configuração por grupo.
- A composição do ciclo (quais despesas entram) **não muda** — continua por data. A carência
  muda só *quando* trava.
- `cycleFor(..., 0)` continua sendo o ciclo do mês-calendário e sempre `open` — a mudança é
  só em `statusOf()`, não no cálculo de fronteira (`boundariesFor()`).
- Mudança de contrato: campo **aditivo** `cycle.closes_at` (= F, `Y-m-d`) em `summary()`,
  `close()`, `reopen()`, `grossDebts()` — ao lado de `cycle.settled` (§0.4 do plan).

### 2.9 Home no ciclo em carência

`focusCycle()` (§2.4) passa a focar também um ciclo **na carência** (ainda `open`, mas com
`end` já passado) que tenha pendência. Assim, nos dias de carência a Home abre no ciclo que
está fechando enquanto houver conta em aberto, e **permanece nele sem salto** quando ele
vira `closed` em F (mesmo `cycles_ago`). Quitado, volta ao ciclo corrente (comportamento de
§2.4).

`close()` / `reopen()` continuam agindo só sobre `cycles_ago = 0` — não há
fechamento/reabertura manual de um ciclo em carência pela navegação; a carência conta com o
auto-close por data em F.

### 2.10 Avisos de fechamento na tela (banners)

Na Home do grupo (`GroupSummary.tsx`) e na tela de Despesas (`ExpenseManager.tsx`), um
componente novo lê o `summary` e mostra:

- **Pré-fechamento** — enquanto `cycle.status === 'open'` e hoje ∈ [`cycle.end`,
  `cycle.closes_at`): aviso informativo de que o ciclo fecha em `cycle.closes_at` e que dá
  pra registrar/acertar até lá.
- **Pós-fechamento** — enquanto `cycle.settled === false` e
  `cycle.status ∈ {closed, closed_manually}` e há devedor (`balances` com saldo negativo, ou
  `settlements` sem confirmação): aviso de alerta listando quem falta acertar.

Só um dos dois por vez (as condições de `status` são disjuntas). Fora dessas janelas, nada.
Sem disparo ativo (e-mail/WhatsApp/push) e sem badge de não-lidas — o sino do cabeçalho é o
backlog **ID 020** (`docs/backlog/sistema-notificacoes-frontend.md`).

## 3. Fora de escopo desta feature

- **Redesign visual** das telas de Despesas/Pagamentos — só comportamento e ordenação.
- **App Expo** (`expense/app`) — feature só toca `expense/frontend` e `expense/backend`.
- **`/api/v2` ou depreciação** — todas as mudanças de contrato são aditivas (§4.1 da Constitution).
- **Pagamento por participante individual** — `Quota.paid` continua por-despesa, não por-pagador
  (limitação já documentada em `docs/feature/concluidas/202608/20260822-criacao-tela-pagamentos`).
- **Sistema de notificações / sino no cabeçalho / push / e-mail / novo gatilho de WhatsApp** —
  os avisos de fechamento de §2.10 são **banners na própria tela**, lidos do `summary`;
  nenhum disparo ativo nem badge de não-lidas. O aviso de WhatsApp já existente
  (`WhatsAppNotifier`) não muda. O sino do cabeçalho é o backlog ID 020.
- **Carência configurável** — `BillingCycle::GRACE_DAYS` é constante (5); não vira coluna
  nem tela de configuração por grupo.
- **Paginação / virtualização** das listas ordenadas.
- **Reescrever `close()` para exigir tudo pago antes de fechar** — fechar continua congelando
  o estado que existir; a quitação é o que acontece *depois*.
