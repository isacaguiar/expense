# Plan — Notificação por WhatsApp ao enviar comprovante de pagamento

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260829

---

## 1. Componentes novos (specify §2.1–2.4)

Namespace `App\Support\WhatsApp\` (mesmo padrão de `App\Support\ProofStorage`). Quatro peças, cada uma testável isolada:

| Classe | Responsabilidade | Depende de |
|---|---|---|
| `PhoneNumber` | `toApiFormat(?string $br): ?string` — tira tudo que não é dígito de `(11) 91234-5678` → `11912345678`, prefixa `55` → `5511912345678`. Retorna `null` se não bater o formato esperado (menos de 10 ou mais de 11 dígitos após DDD). | — |
| `MetaCloudClient` | `sendTemplate(string $toPhone, string $templateName, string $languageCode, array $components): void`. Monta `Http::withToken(config token)->timeout(5)->acceptJson()->post("https://graph.facebook.com/{version}/{phone_number_id}/messages", [...])->throw()`. Lança em não-2xx. **Não** tem lógica de domínio. | `config('services.whatsapp')` |
| `WhatsAppNotifier` | Orquestra. `expenseProofPaid(Quota $quota): void` e `settlementProofConfirmed(SettlementConfirmation $c): void`. Resolve destinatários (§2), formata os parâmetros do template (§3), chama `MetaCloudClient` **um por destinatário**, `try/catch` por destinatário com `Log::warning` (§4). No-op imediato se `config('services.whatsapp.enabled')` é `false`. | `MetaCloudClient` |
| — binding — | `WhatsAppNotifier` e `MetaCloudClient` resolvem por autowiring do container; nada em `AppServiceProvider` a menos que precise. | — |

**Por que classe própria e não Laravel Notifications:** o canal `Notification` do Laravel não tem driver de WhatsApp/Meta oficial; usar `Notifiable` + canal custom traria mais cerimônia (classe `Notification`, método `via`, `toWhatsApp`) do que um serviço direto com dois métodos. YAGNI — se surgir e-mail/SMS no mesmo evento, aí sim vale migrar para `Notification`.

**Por que não `twilio/sdk` ou pacote de terceiros:** a Cloud API é uma única chamada HTTP com `Http::` (já temos `guzzlehttp/guzzle`); um SDK seria dependência a mais para um `POST` com JSON.

## 2. Resolução de destinatários (specify §2.3)

Dentro do `WhatsAppNotifier`, um método privado `recipients(Collection $users): Collection` aplica o filtro único: `whatsapp` não nulo **e** `notify_whatsapp === true`. `notify_whatsapp` já é `cast` para `boolean` no `User`.

- `expenseProofPaid`: parte de `$quota->expense->payers` (carregar `expense.payers`), remove `user_payer_id`, aplica `recipients()`.
- `settlementProofConfirmed`: parte de `[User::find($c->to_user_id)]`, aplica `recipients()`.

Coleção vazia após o filtro → o método retorna sem chamar o client. Um `whatsapp` que o `PhoneNumber::toApiFormat` rejeita (retorna `null`) é tratado como destinatário inválido: `Log::warning` e segue para o próximo.

## 3. Conteúdo e payload do template (specify §2.4–2.5)

Mensagem `type: template`, `language.code` = `config('services.whatsapp.locale')` (`pt_BR`). `components`:

- `body` com `parameters` (`{type: text, text: ...}`) na ordem abaixo.
- `button` `sub_type: url`, `index: "0"`, um `parameters` `{type: text, text: <sufixo do path>}` — o template na Meta é definido com URL base `https://expense.novemax.com.br/app/` + `{{1}}`, e o parâmetro preenche o resto do caminho.

### 3.1 `expenseProofPaid` → template `services.whatsapp.templates.expense_proof`

| # | Parâmetro | Origem |
|---|---|---|
| 1 | nome do credor | `$quota->expense->payer->name` (`user_payer_id`) |
| 2 | nome da despesa | `$quota->expense->description` |
| 3 | rótulo do tipo da despesa | mapa pt_BR dos 3 valores de `expense_type` (`IN_CASH`, `IN_INSTALLMENTS`, `FIXED`). O backend hoje não tem esse mapa (só compara strings); criar um pequeno `match`/const no `WhatsAppNotifier` ou num helper, espelhando os rótulos do frontend |
| 4 | valor | `'R$ '.number_format($quota->value_quota, 2, ',', '.')` |
| 5 | competência | `Carbon::parse($quota->date_expected)` → `translatedFormat('M/Y')` com locale `pt_BR` → `set/2026` |
| botão | path | `"groups/{$quota->expense->group_id}/expenses/{$quota->expense_id}"` |

### 3.2 `settlementProofConfirmed` → template `services.whatsapp.templates.settlement_proof`

| # | Parâmetro | Origem |
|---|---|---|
| 1 | nome do devedor | `User::find($c->from_user_id)->name` |
| 2 | valor | `'R$ '.number_format($c->amount, 2, ',', '.')` |
| 3 | competência | `Carbon::parse($c->cycle_start)->translatedFormat('M/Y')` → `set/2026` |
| botão | path | `"groups/{$c->group_id}/payments"` |

Texto exato dos corpos dos templates fica em `implementation.md` (é conteúdo submetido à Meta, não código) — rascunho em §2.4 do `specify.md`.

## 4. Modelo de entrega (specify §2.6)

Nos dois call sites, **depois** da escrita no banco:

```php
// pay(), logo após $quota->update($update); só quando houve comprovante:
if (array_key_exists('payment_proof_path', $update)) {
    $quotaId = $quota->id;
    dispatch(function () use ($quotaId) {
        app(WhatsAppNotifier::class)->expenseProofPaid(
            Quota::with('expense.payers', 'expense.payer')->find($quotaId)
        );
    })->afterResponse();
}
```

```php
// confirmSettlement(), logo após o updateOrCreate:
$confirmationId = $confirmation->id;
dispatch(function () use ($confirmationId) {
    app(WhatsAppNotifier::class)->settlementProofConfirmed(
        SettlementConfirmation::find($confirmationId)
    );
})->afterResponse();
```

- `dispatch(Closure)->afterResponse()` roda o callback via `terminating` do kernel — depois da resposta ir ao cliente, no mesmo processo (não precisa de worker). É o mecanismo suportado pelo Laravel para "faz isso, mas não segura a resposta".
- Passa só o **id** para o closure e re-consulta dentro — evita serializar model e estado velho.
- O closure não tem `try/catch` próprio: quem engole exceção é o `WhatsAppNotifier` (por destinatário). Se algo escapar mesmo assim, é depois da resposta — não afeta o cliente; cai no log de erro padrão.
- `config('services.whatsapp.enabled') === false` → `WhatsAppNotifier` retorna na primeira linha, nenhuma chamada a `graph.facebook.com`.

**Por que `afterResponse` e não fila (`database` + `queue:work`):** o host de produção (plano M, sem SSH) não roda worker persistente nem `php artisan schedule:run` confiável. `QUEUE_CONNECTION=sync` executaria o envio dentro da requisição, adicionando latência e risco. `afterResponse` é o melhor disponível sem infra nova. Migrar para fila durável com retry é decisão futura (registrada como consequência na ADR-006).

## 5. Configuração e segredo (specify §2.7)

### 5.1 Bloco em `backend/config/services.php`

```php
'whatsapp' => [
    'enabled' => env('WHATSAPP_ENABLED', false),
    'token' => env('WHATSAPP_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'locale' => env('WHATSAPP_LOCALE', 'pt_BR'),
    'templates' => [
        'expense_proof' => env('WHATSAPP_TEMPLATE_EXPENSE_PROOF', 'comprovante_despesa_pago'),
        'settlement_proof' => env('WHATSAPP_TEMPLATE_SETTLEMENT_PROOF', 'comprovante_acerto_confirmado'),
    ],
],
```

### 5.2 `backend/.env.example`

Acrescenta as chaves `WHATSAPP_*` com valores vazios/placeholder e `WHATSAPP_ENABLED=false`. **Nota (execução):** `.env.example` está no `.gitignore` deste repo — a edição fica local (DX), não versiona. A referência versionada das vars é `config/services.php` + `deploy-backend.yml` + `implementation.md`.

### 5.3 `.github/workflows/deploy-backend.yml`

Acrescenta na geração do `.env` (bloco "🔐 Gerar arquivo .env", junto das linhas Google/Pix):

```
echo "WHATSAPP_ENABLED=${{ secrets.ENV_WHATSAPP_ENABLED }}" >> .env
echo "WHATSAPP_TOKEN=${{ secrets.ENV_WHATSAPP_TOKEN }}" >> .env
echo "WHATSAPP_PHONE_NUMBER_ID=${{ secrets.ENV_WHATSAPP_PHONE_NUMBER_ID }}" >> .env
echo "WHATSAPP_TEMPLATE_EXPENSE_PROOF=${{ secrets.ENV_WHATSAPP_TEMPLATE_EXPENSE_PROOF }}" >> .env
echo "WHATSAPP_TEMPLATE_SETTLEMENT_PROOF=${{ secrets.ENV_WHATSAPP_TEMPLATE_SETTLEMENT_PROOF }}" >> .env
```

(`WHATSAPP_API_VERSION` e `WHATSAPP_LOCALE` ficam no default do `config` — só entram como secret se precisarem divergir.)

Criar os secrets no repositório e rodar o deploy é **gate humano** (Constitution §5.2 — expor segredo; deploy). O código nunca referencia o token fora de `config('services.whatsapp.token')`.

## 6. ADR (decisão de stack)

Adotar a Meta WhatsApp Cloud API como canal de mensageria do projeto é decisão de arquitetura → `docs/sdd/decisions/ADR-006-whatsapp-meta-cloud-api.md` (Status: Aceita), com as alternativas descartadas (Twilio, gateways BR não-oficiais, camada de abstração de provider) e a consequência de a entrega ser best-effort até haver worker. Índice em `decisions/README.md` atualizado.

## 7. Testes

- **Feature `ExpenseControllerPayTest`** (novo caso): `pay` com `comprovante`, grupo com um pagador opt-in, um opt-out e um sem `whatsapp` → `Http::fake` de `graph.facebook.com/*`; assere exatamente 1 `POST`, com `to` do opt-in, `template.name` = config, ordem dos `body.parameters` e o `button` param. `pay` sem `comprovante` → `Http::assertNothingSent()`.
- **Feature `SettlementConfirmationControllerTest`** (novo caso): `confirmSettlement` → 1 `POST` para o credor; reenvio → novo `POST`.
- **Flag desligada:** `config(['services.whatsapp.enabled' => false])` → `Http::assertNothingSent()`, ação principal segue 2xx.
- **Meta fora do ar:** `Http::fake([... => Http::response('', 500)])` → a asserção principal do teste (quota paga / confirmation criada, resposta 2xx) continua valendo; `Log::spy()` + assere `warning`.
  - `dispatch()->afterResponse()` roda no `terminate` do kernel, que os testes HTTP do Laravel executam após a requisição — os casos acima cobrem o caminho ponta-a-ponta. Se algum ambiente não disparar o `terminate`, fallback: testar `WhatsAppNotifier` direto e assertar que o call site registrou o callback.
- **Unit `PhoneNumberTest`:** `(11) 91234-5678` → `5511912345678`; fixo 8 dígitos; string com letras; vazio/`null` → `null`.
- **Unit `WhatsAppNotifierTest`:** com `MetaCloudClient` fake — filtro de destinatário (opt-out, sem whatsapp, remetente/credor excluído no caso `pay`), no-op com flag off, um destinatário inválido não impede os outros.
- Gate: `./vendor/bin/pint --test` + `php artisan test` limpos antes do PR.

## N. Ordem de execução

Dependência só de 1 → (2,3):

1. **§1 + §5.1** — `PhoneNumber`, `MetaCloudClient`, `WhatsAppNotifier`, bloco `config/services.php`, com os testes unitários (§7). Sem wiring nos controllers ainda.
2. **§4 (pay)** — call site em `ExpenseController@pay` + caso de teste feature.
3. **§4 (confirmSettlement)** — call site em `ExpenseController@confirmSettlement` + caso de teste feature.
4. **§5.2 + §5.3 + §6** — `.env.example`, linhas no `deploy-backend.yml`, ADR-006 + índice, rascunho do texto dos templates em `implementation.md`. Independente de 1–3; pode ir em paralelo.

Sem dependência técnica entre 2 e 3; ordenados por serem o mesmo padrão aplicado duas vezes (fazer 2, revisar, repetir em 3).
