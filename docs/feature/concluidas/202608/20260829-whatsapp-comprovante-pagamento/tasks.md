# Tasks — Notificação por WhatsApp ao enviar comprovante de pagamento

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260829

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Criar `PhoneNumber`, `MetaCloudClient` e `WhatsAppNotifier` com o bloco de config `services.whatsapp` | backend | plan.md §1, §2, §3, §5.1 | nenhum | Concluída |
| TASK-002 | Disparar `WhatsAppNotifier::expenseProofPaid` após `ExpenseController@pay` com comprovante | backend | plan.md §4 | nenhum | Concluída |
| TASK-003 | Disparar `WhatsAppNotifier::settlementProofConfirmed` após `ExpenseController@confirmSettlement` | backend | plan.md §4 | nenhum | Concluída |
| TASK-004 | Adicionar as chaves `WHATSAPP_*` ao `.env.example` e à geração de `.env` do `deploy-backend.yml` | infra | plan.md §5.2, §5.3 | antes de rotacionar segredo | Concluída |
| TASK-005 | Registrar ADR-006 (Meta Cloud API) no índice e rascunhar o texto dos templates em `implementation.md` | doc | plan.md §6 | nenhum | Concluída |

## Critérios de aceite

- **TASK-001**:
  - `App\Support\WhatsApp\PhoneNumber::toApiFormat()` retorna `5511912345678` para `(11) 91234-5678`, e `null` para entrada vazia, `null`, com letras, ou com contagem de dígitos fora de 10–11 após limpar.
  - `App\Support\WhatsApp\MetaCloudClient::sendTemplate()` faz `POST https://graph.facebook.com/{api_version}/{phone_number_id}/messages` com `Authorization: Bearer {token}`, corpo `messaging_product=whatsapp`, `type=template`, e lança em resposta não-2xx. Verificável com `Http::fake` + `Http::assertSent`.
  - `App\Support\WhatsApp\WhatsAppNotifier`: com `config('services.whatsapp.enabled')` `false`, `expenseProofPaid()` e `settlementProofConfirmed()` retornam sem nenhuma chamada HTTP (`Http::assertNothingSent()`); com a flag `true` e um `MetaCloudClient` fake, aplicam o filtro `whatsapp != null && notify_whatsapp === true`, excluem o credor no caso `pay`, e um destinatário com telefone inválido gera `Log::warning` sem impedir os demais.
  - Bloco `whatsapp` presente em `backend/config/services.php` com as 7 chaves lendo de `env()` e defaults de `enabled=false`, `api_version=v21.0`, `locale=pt_BR`, nomes de template.
  - Mapa pt_BR de `expense_type` (`IN_CASH`, `IN_INSTALLMENTS`, `FIXED`) implementado e coberto por teste.
  - `./vendor/bin/pint --test` e `php artisan test` limpos.

- **TASK-002**:
  - Teste feature em `ExpenseControllerPayTest`: `pay` com `comprovante`, grupo com um pagador opt-in (`whatsapp` + `notify_whatsapp=true`), um opt-out e um sem `whatsapp` → com `Http::fake('graph.facebook.com/*')`, exatamente 1 `POST`, `to` = telefone do opt-in em formato API, `template.name` = `config('services.whatsapp.templates.expense_proof')`, `body.parameters` na ordem: credor, descrição, rótulo do tipo, valor `R$ x.xxx,xx`, competência `set/2026`; `button` param = `groups/{groupId}/expenses/{expenseId}`.
  - `pay` sem `comprovante` → `Http::assertNothingSent()`.
  - `pay` com Meta respondendo 500 → resposta do endpoint continua 2xx, quota `paid=true`, `Log::warning` emitido.
  - A resposta HTTP do `pay` não muda de shape (testes existentes continuam verdes).
  - `pint --test` e `php artisan test` limpos.

- **TASK-003**:
  - Teste feature em `SettlementConfirmationControllerTest`: `confirmSettlement` bem-sucedido → 1 `POST` para o telefone do credor (`to_user_id`) se ele é opt-in; `template.name` = `settlement_proof`; `body.parameters`: devedor, valor, competência `set/2026`; `button` param = `groups/{groupId}/payments`.
  - Credor opt-out ou sem `whatsapp` → `Http::assertNothingSent()`.
  - Reenvio do comprovante (segundo `confirmSettlement` no mesmo par/ciclo) → novo `POST`.
  - Meta 500 → endpoint continua 2xx, `SettlementConfirmation` persistida, `Log::warning`.
  - `pint --test` e `php artisan test` limpos.

- **TASK-004**:
  - `backend/.env.example` contém `WHATSAPP_ENABLED=false` e as 6 demais chaves `WHATSAPP_*` vazias/placeholder.
  - `.github/workflows/deploy-backend.yml`, no passo de geração do `.env`, tem as linhas `echo "WHATSAPP_...=${{ secrets.ENV_WHATSAPP_... }}"` para `ENABLED`, `TOKEN`, `PHONE_NUMBER_ID`, `TEMPLATE_EXPENSE_PROOF`, `TEMPLATE_SETTLEMENT_PROOF`.
  - `deploy-backend.yml` continua válido (YAML lint / o passo não quebra); nenhuma chave com valor hardcoded.
  - A ativação real (criar os secrets no repositório, `WHATSAPP_ENABLED=true`, rodar o deploy) NÃO faz parte desta task — é o gate humano.

- **TASK-005**:
  - `docs/sdd/decisions/ADR-006-whatsapp-meta-cloud-api.md` existe no formato de `decisions/README.md` (Contexto, Decisão, Consequências — incl. entrega best-effort até haver worker —, Alternativas: Twilio, gateways BR não-oficiais, camada de abstração de provider), Status `Aceita`.
  - Linha do ADR-006 adicionada ao índice em `docs/sdd/decisions/README.md`.
  - `implementation.md` §1 traz o texto pt_BR proposto para os dois templates (corpo com `{{n}}` + label do botão + categoria UTILITY) e o checklist operacional Meta (app, número verificado, token de System User, submissão e aprovação dos templates) marcado como pré-requisito de ativação.
