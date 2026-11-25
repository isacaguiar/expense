# Implementation — Log sem SQL nos pontos de `QueryException`

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261124

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio até aqui.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-680 | Concluída | 2026-11-24 | Claude | Teste novo `test_a_query_failure_at_the_commit_logs_only_the_exception_class_and_not_the_sql` em RED (contexto trazia a exceção inteira), depois `Log::error('Erro ao criar despesa', ['exception' => $e::class])` e comentário reescrito. `./vendor/bin/phpunit tests/Feature/ExpenseControllerStorePaymentTest.php`: OK (50 tests, 245 assertions); `./vendor/bin/pint --test`: PASS (275 files). Mutantes: A (volta a `['exception' => $e]`) e B (classe somada a `getMessage()`) derrubados pelo teste novo (1 falha cada), arquivo restaurado e conferido com `cmp`. | `QueryException` montada à mão com `Joana Sigilosa` nos bindings, lançada por listener `eloquent.creating: Quota`; o teste existente do 500 segue verde. |
