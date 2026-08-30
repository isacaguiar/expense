# Implementation — Notificação por WhatsApp ao enviar comprovante de pagamento

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260829

---

## 1. Desvios do fluxo padrão (se houver)

- **Classes estáticas em vez de injeção por container** (plan §1 previa autowiring). `PhoneNumber`, `MetaCloudClient` e `WhatsAppNotifier` são classes de métodos estáticos, espelhando `App\Support\ProofStorage` (padrão já em uso no projeto). Testabilidade vem de `Http::fake()` / `Log::spy()`, não de DI. Sem binding em `AppServiceProvider`.
- **Relações novas em `SettlementConfirmation`**: `fromUser()` / `toUser()` (`belongsTo` User). Necessárias para o `WhatsAppNotifier` ler devedor/credor via relação (testável in-memory com `setRelation`) em vez de `User::find()`. O call site da TASK-003 deve carregar `->load('fromUser', 'toUser')` ou `with(...)`.

## Prerequisitos operacionais Meta (fora das tasks — gate humano)

Antes de `WHATSAPP_ENABLED=true` em produção:

1. App na Meta com o produto WhatsApp, número de telefone verificado, token permanente de System User com escopo `whatsapp_business_messaging`.
2. Dois templates aprovados (categoria UTILITY, idioma `pt_BR`), nomes batendo com `WHATSAPP_TEMPLATE_EXPENSE_PROOF` / `WHATSAPP_TEMPLATE_SETTLEMENT_PROOF`. Rascunho de texto — a detalhar na TASK-005:
   - **`comprovante_despesa_pago`** — corpo: `{{1}} anexou o comprovante do pagamento de "{{2}}" ({{3}}) no valor de {{4}}, competência {{5}}.` · botão URL "Ver no app" com base `https://expense.novemax.com.br/app/` + `{{1}}` (path).
   - **`comprovante_acerto_confirmado`** — corpo: `{{1}} confirmou o pagamento do acerto de {{2}}, competência {{3}}.` · mesmo botão.
3. Secrets `ENV_WHATSAPP_*` no repositório + rodar `deploy-backend.yml`.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-08-29 | isacaguiar | `php artisan test --testsuite=Unit --filter=WhatsApp` → 12 passed (21 assertions); `./vendor/bin/pint --test app/Support/WhatsApp app/Models/SettlementConfirmation.php config/services.php tests/Unit/WhatsApp` → PASS 8 files; `php artisan test` → 251 passed (743 assertions) | `App\Support\WhatsApp\{PhoneNumber,MetaCloudClient,WhatsAppNotifier}`, bloco `services.whatsapp` em `config/services.php`, relações `fromUser`/`toUser` em `SettlementConfirmation`, mapa pt_BR de `expense_type`. Sem wiring nos controllers (TASK-002/003). |
| TASK-002 | Concluída | 2026-08-29 | isacaguiar | `php artisan test tests/Feature/ExpenseControllerPayTest.php` → 22 passed (74 assertions), inclui 3 casos novos; `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerPayTest.php` → PASS; `php artisan test` → 254 passed (754 assertions) | `ExpenseController@pay`: `dispatch(Closure)->afterResponse()` só quando `payment_proof_path` entrou no `$update`; closure re-consulta `Quota::with('expense.payers','expense.payer')` e chama `WhatsAppNotifier::expenseProofPaid`. Confirmado que o closure `afterResponse` roda nos testes HTTP (kernel `terminate`). |
| TASK-003 | Concluída | 2026-08-29 | isacaguiar | `php artisan test tests/Feature/SettlementConfirmationControllerTest.php` → 12 passed (49 assertions), inclui 4 casos novos; `./vendor/bin/pint --test` (ExpenseController + teste) → PASS; `php artisan test` → 258 passed (776 assertions) | `ExpenseController@confirmSettlement`: `dispatch(Closure)->afterResponse()` sempre (comprovante é obrigatório na rota); closure re-consulta `SettlementConfirmation::with('fromUser','toUser')` e chama `WhatsAppNotifier::settlementProofConfirmed`. Nota registrada no teste `reenvio`: nos testes HTTP com 2+ requests no mesmo método os callbacks `afterResponse` da request anterior re-executam na seguinte (artefato do harness — `refreshApplication` é por teste, não por request); em produção cada request tem ciclo próprio. Por isso o teste de reenvio assere `>= 2` envios, não contagem exata. |
| TASK-004 | Concluída | 2026-08-29 | isacaguiar | `php artisan config:clear && php -r "... var_export(config('services.whatsapp'))"` → array com defaults corretos (`enabled=false`, `v21.0`, `pt_BR`, nomes de template); `deploy-backend.yml` sem tabs, mesmas 10 colunas de indentação das linhas `echo` vizinhas; `php artisan test` → 258 passed (776 assertions) | `.github/workflows/deploy-backend.yml`: 5 linhas `echo "WHATSAPP_...=${{ secrets.ENV_WHATSAPP_... }}"` no passo de geração do `.env` (ENABLED, TOKEN, PHONE_NUMBER_ID, TEMPLATE_EXPENSE_PROOF, TEMPLATE_SETTLEMENT_PROOF). **`.env.example` é gitignored neste repo** (`.gitignore:13`) — editado localmente para DX, mas não versiona; a lista canônica das novas vars vive em `config/services.php` + `deploy-backend.yml` + este doc (mesmo padrão das chaves Google em `20260824-ajuste-deploy-backend-google`). `API_VERSION`/`LOCALE` ficam no default do config. Ativação real (criar secrets `ENV_WHATSAPP_*`, `WHATSAPP_ENABLED=true`, deploy) = gate humano, fora da task. |
| TASK-005 | Concluída | 2026-08-29 | isacaguiar | Arquivo `docs/sdd/decisions/ADR-006-whatsapp-meta-cloud-api.md` criado no formato do `decisions/README.md`; linha adicionada ao índice em `decisions/README.md`. Rascunho dos textos dos 2 templates + checklist operacional Meta já constavam na seção "Prerequisitos operacionais Meta" acima (desde a TASK-001). | ADR Status `Aceita`. Alternativas descartadas: Twilio, gateways BR não-oficiais, Zenvia/BSP, camada de abstração de provider, canal `Notification` do Laravel, fila durável nesta feature. |
