# Implementation — `ExpenseController::store` não loga a mensagem da exceção pós-commit

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261124

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-678 | Concluída | 2026-11-24 | Claude | RED: 2 testes novos em `ExpenseControllerStorePaymentTest` (`test_after_commit_logs_the_exception_class_and_not_its_message` e `test_after_commit_does_not_leak_sql_bindings_into_the_log`, chamando `afterCommit` por `ReflectionMethod`, D4-A) falham com "warning called 0 times" (o contexto ainda tinha a mensagem). GREEN: `afterCommit` (`ExpenseController.php`) passa a logar `['exception' => $e::class]` e o docblock explica a razão; os 2 testes passam (2 testes, 2 asserções). Mutantes (backup `n678.bak` e `cmp`): A (`'exception' => $e->getMessage()`) aplicado, 2 erros; B (`$e::class` somado a `'error' => $e->getMessage()`) aplicado, 2 erros (derrubado pelo `array_keys($context) === ['exception']`); arquivo restaurado, `cmp` igual. `./vendor/bin/phpunit tests/Feature/ExpenseControllerStorePaymentTest.php`: OK (49 testes, 237 asserções). `./vendor/bin/pint --test`: PASS, 275 arquivos. | Teste 2 provoca a `QueryException` com `Notification::create` de `user_id` inexistente (FK). |
