# Parcela retroativa nasce `paid`/`paid_by` em nome do credor, sem consentimento nem aviso

ID: 038
Origem: docs/feature/20260903-despesa-parcelada-retroativa/specify.md §2.2/§2.5 (achado do `security-reviewer` no PR #144)
Criado em: 2026-09-04
Prioridade: MEDIA
Status: Aberto

## Descrição

Na feature "despesa parcelada retroativa", `ExpenseController::store()`
([:427-441](../../backend/app/Http/Controllers/ExpenseController.php#L427)) grava
`paid = true`, `paid_at = now()`, `paid_by = $request->user_payer_id` nas parcelas
de ciclo já fechado — a partir do payload de **qualquer membro** que cadastre a
despesa. Hoje, marcar quota como paga só acontece em `pay()`, que exige
`auth()->id() === $expense->user_payer_id` ("só o credor pode marcar como paga",
[:891](../../backend/app/Http/Controllers/ExpenseController.php#L891)). Aqui o
credor não é `auth()->id()` e não consente.

Duas lacunas de defesa em profundidade que o `security-reviewer` pediu para
registrar (não bloqueiam o PR #144 — são decisão explícita do `specify.md`):

1. **Credor não é avisado.** `specify.md` §2.5 suprime `Notifier::expensePaid` de
   propósito. Se o credor não estiver entre `payers`, ele não recebe **nenhuma**
   notificação (`Notifier::expenseCreated` só faz fan-out para `payers` menos o
   criador, [Notifier.php:42-44](../../backend/app/Support/Notifier.php#L42)) —
   pode existir despesa com ele como `user_payer_id` e parcelas passadas
   `paid_by = <ele>` sem aviso nenhum.
2. **Ciclo `closed` não selado.** `computeCycleSummary()` não filtra por `paid` ao
   montar `balances`/`settlements`
   ([:1164-1186](../../backend/app/Http/Controllers/ExpenseController.php#L1164)) —
   a parcela retroativa injeta um par de `settlement` novo (não confirmado) num
   mês passado já reconciliado.

## Por que importa

Fura a invariante "só o credor marca pago" fora do fluxo `pay()`. Sem exposição de
dado de terceiro e o credor pode reverter (`unpay` com `cycles_ago`), mas o risco
cresce conforme a feature for usada: registros financeiros aparecem quitados em
nome de alguém que nunca agiu nem foi avisado.

Remédio possível (uma das duas, vira Tech Plan em `/promover-backlog 038`):
(a) notificar o credor quando parcelas nascem `paid` em seu nome; ou
(b) só permitir born-paid retroativo quando `auth()->id() === $request->user_payer_id`
(quem cadastra a despesa retroativa é o próprio credor).

Tipo sugerido: backend
