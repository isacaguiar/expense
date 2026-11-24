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
