# Specify — Notificar credor quando parcela retroativa nasce paga em seu nome

> Feature: fecha o item de backlog 038 — quando um membro do grupo registra uma despesa parcelada retroativa em nome de **outro** credor, as parcelas que nascem em ciclo já fechado são marcadas `paid`/`paid_by` no nome do credor sem que ele seja avisado (se não estiver entre os `payers`, fica sem qualquer notificação). Origem: achado do `security-reviewer` no PR #144, registrado como débito técnico não-bloqueante na feature `despesa-parcelada-retroativa`.

Versão: 1.0 · Criado em: 20260922

---

## 1. Problema

A feature "despesa parcelada retroativa" (`docs/feature/concluidas/202609/20260903-despesa-parcelada-retroativa/`) decidiu, em `specify.md` §2.5, que parcelas nascidas `paid` na criação **não** disparam `Notifier::expensePaid` — o raciocínio era "é registro retroativo de algo já acontecido, não um evento de pagamento agora", o que faz sentido quando quem cria a despesa é o próprio credor (ele já sabe).

Só que `store()` não exige isso: qualquer membro do grupo pode registrar uma despesa em nome de outro credor (`user_payer_id` só precisa ser membro do grupo, `ExpenseController.php:339`). Quando isso acontece com parcela retroativa, o credor pode nunca ficar sabendo: `Notifier::expenseCreated` só notifica quem está em `payers`, menos o criador — se o credor não for um dos `payers` (comum: ele só recebe, não deve), ele fica de fora dessa notificação também. Resultado: uma despesa nasce com parcelas já quitadas em nome de alguém que não recebeu nenhum aviso.

Decisão confirmada com o usuário: manter o comportamento atual de permitir que qualquer membro registre despesa em nome de outro credor (não restringir quem pode criar) — o reparo é garantir que o credor seja sempre avisado quando isso gera parcela já paga em seu nome.

## 2. Achados confirmados e requisitos

### 2.1 Quotas nascem `born_paid` sem qualquer notificação ao credor

- `ExpenseController::store()` (`backend/app/Http/Controllers/ExpenseController.php:427-441`): quota cujo `date_expected` cai em ciclo `closed` nasce com `paid=true`, `paid_at=now()`, `paid_by=$request->user_payer_id`, `born_paid=true`.
- `Notifier::expenseCreated()` (`backend/app/Support/Notifier.php:37-60`): fan-out só para `$expense->payers` menos `user_creator_id` — não inclui o credor quando ele não é um dos `payers`.
- `Notifier::expensePaid()` (`backend/app/Support/Notifier.php:67-90`) nunca é chamado no caminho de `store()` — só em `pay()` (`ExpenseController.php:929`) — logo não cobre esse caso mesmo quando o credor está entre os `payers`.

### 2.2 Requisito: notificação dedicada ao credor, só quando ele não é quem registrou

- Quando `store()` cria ao menos uma quota `born_paid` **e** `auth()->id() !== $request->user_payer_id`, disparar uma notificação dedicada só para o credor, avisando quantas parcelas nasceram já pagas em seu nome nesta despesa.
- Quando o próprio credor é quem registra a despesa (`auth()->id() === user_payer_id`), nenhuma notificação nova — ele já sabe, mesmo raciocínio do `specify.md` §2.5 original da feature de origem.
- A notificação atinge só o credor — não é um evento coletivo do grupo como `expense_created`.

## 3. Fora de escopo desta feature

- Restringir quem pode registrar despesa retroativa em nome de outro credor (a alternativa (b) do item de backlog, descartada com o usuário — mantém o comportamento permissivo atual de `store()`).
- Alterar `Notifier::expenseCreated()` ou `Notifier::expensePaid()` para outros fluxos (ex.: incluir o credor em `expenseCreated` sempre, mesmo fora de parcela retroativa) — fora do que o item de backlog 038 aponta.
- Preferência de usuário para silenciar esse tipo específico de notificação — segue o padrão já existente do sistema de notificações in-app (nenhum tipo hoje é silenciável individualmente).
- Notificar por WhatsApp (`WhatsAppNotifier`) — o item de backlog só fala de notificação in-app; WhatsApp não foi cogitado nem pela feature de origem para este evento.
