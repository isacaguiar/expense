# Tasks — Notifier não loga SQL com bindings

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261124

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-676 | `Notifier::guard` registra só o tipo do aviso e a classe da exceção, sem a mensagem | backend | plan.md §1 e §2 | nenhum | Concluída |
| TASK-677 | Documentar a regra e atualizar o item 155 e o backlog do `ExpenseController.php:907` | doc | plan.md §3 | nenhum | Pendente |

Ordem: 676 primeiro (teste em RED, depois o código); 677 por último. Como a 676 mexe em log de dado pessoal, rodar o `security-reviewer` no fechamento da feature.

## Critérios de aceite

- **TASK-676**: o `Log::warning('Notifier: falha ao gravar notificação', ...)` de `Notifier::guard` passa a ter no contexto `type` (o tipo do aviso) e `exception` (a classe da exceção, `$e::class`) e **não** tem a chave `error` nem a mensagem da exceção; a mensagem do log, o retorno de `guard` (`false` ao capturar, `true` sem exceção) e o fato de a exceção não subir não mudam; o docblock do método diz que a mensagem fica de fora por causa de SQL com bindings. Em `backend/tests/Feature/NotifierTriggersTest.php`: teste novo que força a falha já usada por `test_notifier_guard_swallows_a_write_failure` e afirma, com `Log::shouldHaveReceived('warning')->withArgs(...)`, `type` e `exception` (`JsonException`) presentes, `error` ausente e nenhum valor do contexto contendo a mensagem da exceção; teste de `QueryException` com nome reconhecível nos dados afirmando que o nome não vai ao log (ou o desvio registrado no `implementation.md` se não for simples provocá-la). Os testes existentes com `Log::shouldHaveReceived('warning')` continuam verdes. Mutantes (voltar a `'error' => $e->getMessage()`; somar a mensagem junto com `exception`) derrubados por ao menos um teste cada. `./vendor/bin/phpunit` dos testes de Notifier verde e `./vendor/bin/pint --test` limpo.
- **TASK-677**: `docs/sdd/06-context-backend.md` registra que o log de `Notifier::guard` leva só o `type` e a classe da exceção, com a versão subida; `docs/sdd/01-specify.md` §3.3 ganha o bullet com a versão subida; o item 155 do backlog marca o ponto 2 como resolvido por esta feature e continua **aberto** com os pontos 1, 3 e 4; item novo no backlog para `ExpenseController.php:907` (`Log::warning` com `getMessage()` do passo pós-commit do `store`), com a linha no `docs/backlog/README.md`; `git diff` não lista specify de feature arquivada.
