# Implementation — Notifier não loga SQL com bindings

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261124

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-676 | Concluída | 2026-11-24 | Claude | RED: 2 testes novos em `NotifierTriggersTest` (`test_notifier_guard_logs_the_exception_class_and_not_its_message` e `test_notifier_guard_does_not_leak_sql_bindings_into_the_log`) com o código antigo = 2 erros (`Log::warning` não chamado com o contexto esperado), 18 passam. GREEN: contexto do log de `Notifier::guard` = `['type' => $type, 'exception' => $e::class]`, sem `error`: `./vendor/bin/phpunit` em `NotifierTriggersTest`, `NotifierInvitationTest`, `NotifierPaymentOnCreateTest` e `ExpenseControllerStoreNoticesTest` = 44 testes, 172 asserções, OK; `./vendor/bin/pint --test` nos 2 arquivos = PASS. Mutantes mortos (arquivo restaurado e conferido com `cmp`): voltar a `'error' => $e->getMessage()` (2 erros) e classe mais mensagem juntas (2 erros). | A `QueryException` do teste de vazamento sai de um crédito para usuário inexistente (a FK de `ex_notifications.user_id` recusa o insert); o teste afirma que nem o nome do ator, nem o do grupo, nem `insert into` aparecem no contexto. Desvio meu: a primeira versão do teste de classe esperava `JsonException`, mas o cast `data` lança `Illuminate\Database\Eloquent\JsonEncodingException`; corrigido. Um `sed` que devia trocar isso não aplicou e a cadeia parou antes de rodar: refeito com `Edit`. |
| TASK-677 | Concluída | 2026-11-24 | Claude | `docs/sdd/06-context-backend.md` v1.3 → v1.4 (convenção: log de falha de notificação só com `type` e classe da exceção); `docs/sdd/01-specify.md` v1.12 → v1.13 (bullet em §3.3); item 155 do backlog: ponto 2 riscado e marcado RESOLVIDO com a feature e a TASK-676, item **continua aberto** com os pontos 1, 3 e 4; item novo 163 (`ExpenseController.php:907`) com a linha no `docs/backlog/README.md`. `git status` lista só esses arquivos e o item novo; nenhum specify arquivado foi tocado. | O `06-context-backend.md` não tinha parágrafo sobre o log do Notifier, então a regra entrou em "Convenções fixas". O caminho da feature nos dois docs é `docs/feature/20261124-...`; troca para `concluidas/202611/` no arquivamento (ADR-009). |

## 3. Fechamento

| Item | Resultado |
|---|---|
| Suíte cheia do backend (`./vendor/bin/phpunit`, MySQL de teste 3307) | OK: 1744 testes, 9428 asserções, 5 min 16 s |
| `./vendor/bin/pint --test` | PASS, 275 arquivos |
| `security-reviewer` (delta `origin/dev...HEAD -- backend`) | 0 crítico, 0 alto, 2 informativos. O `Log::warning` do `guard` é o único log do `Notifier.php`; o best-effort foi preservado. |
| Informativo 1 | `WhatsAppNotifier.php:143-147` com o mesmo padrão (`getMessage()` no log): conferido no código e registrado como item 164 do backlog, fora desta feature. |
| Informativo 2 | Sem a mensagem o log perde a causa exata (ex.: qual FK): troca aceita pela D1-A; `getCode()` ficaria como sinal extra, não necessário. |
| Desvio de texto | O critério de aceite da TASK-676 em `tasks.md` cita `JsonException`; o cast `data` lança `JsonEncodingException`, que é o que o teste afirma. |
