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
| TASK-681 | Concluída | 2026-11-24 | Claude | Dois testes novos em `GoogleAuthControllerTest` (`test_link_callback_logs_only_the_exception_class_when_the_save_fails`, `test_login_callback_logs_only_the_exception_class_when_the_save_fails`) em RED (o contexto trazia `falha forçada (... SQL: update ex_users set name = Joana Sigilosa ...)`), depois `'exception' => $e::class` nas linhas 132 e 228. `./vendor/bin/phpunit tests/Feature/GoogleAuthControllerTest.php`: OK (23 tests, 79 assertions); `./vendor/bin/pint --test`: PASS (275 files). Mutantes por ponto (link e login, cada um com A `getMessage()` e B classe somada à mensagem): 4 derrubados, 1 falha cada, no teste do próprio ponto; arquivo restaurado e conferido com `cmp`. | `QueryException` montada à mão lançada por listener `eloquent.saving: User`; vínculo afirma também `user_id` no contexto. Linhas 116 e 180 não tocadas. |
| TASK-682 | Concluída | 2026-11-24 | Claude | Números de linha dos 5 pontos conferidos com `grep -n getMessage()` (`GroupInvitationService.php:254`, `InvitationController.php:97`, `PreRegistrationService.php:288`, `GoogleAuthController.php:116` e `:180`). Item 165 reduzido aos 5 pontos, com a seção "Já resolvido" (780, 132, 228), D2 e D4 ajustadas e prioridade BAIXA; linha 165 do README atualizada. `git diff --stat` sem edição em `docs/sdd/` nem em feature arquivada. | O item segue aberto em `docs/backlog/` (Parcialmente promovido para TASK-680). |

## 3. Fechamento

Executado em 2026-11-24 na branch da feature, depois das TASK-680 a 682.

| Verificação | Resultado |
|---|---|
| `./vendor/bin/phpunit` (suíte cheia do backend) | OK (1749 tests, 9451 assertions), 4 min 34 s |
| `./vendor/bin/pint --test` | PASS (275 files) |
| `security-reviewer` | 0 crítico, 0 alto, 0 médio, 0 baixo. Os três pontos sem vazamento de SQL nem de dado pessoal; resposta e fluxo inalterados; todo outro log com exceção já está no backlog 164 ou 165. 3 informativos: (1) `InvitationController.php:97` grava o e-mail em claro (já na D3 do item 165); (2) as exceções relançadas pelos `catch (\Throwable)` que apagam arquivo órfão (`ExpenseController.php` 1698 e 1850, `SettlementDecisionController.php:198`) caem no reporter padrão do Laravel e podem levar SQL com bindings, fora do escopo e sem item; (3) conferido: `docs/sdd/` não afirma mais que "a mensagem fica no log". |
| `pr-readiness-checker` | Pronto para PR: escopo só backend e docs, sem migration, Pint e os dois arquivos de teste (73 tests, 324 assertions) verdes, rastreabilidade das 3 tasks, backlog 165 coerente, sem edição em `docs/sdd/`, sem segredo no diff. |

## 4. Validação local (delegada a mim, "valide por mim", 2026-11-24)

Sem tela: a mudança é só de log. Validei com uma falha **real** do MySQL, não montada à mão: `GET /api/auth/google/callback` (login) com `Socialite::fake` e um nome de 400 caracteres, que estoura a coluna `name` (`SQLSTATE[22001] ... 1406 Data too long`), com o log num arquivo temporário.

| Código | Redirect | Log |
|---|---|---|
| Atual | 302 para `?google_error=1` | `[google-login] save falhou (QueryException) -> login error {"exception":"Illuminate\Database\QueryException"}`; sem o e-mail, sem `insert into` |
| Linha 228 com o mutante `getMessage()` (comparação) | igual | traz `SQLSTATE[22001] ... SQL: insert into ex_users (...)`, com o e-mail do usuário |

Nenhum usuário foi criado. Mutante revertido e conferido com `cmp`. O ponto do `store` (linha 782) e o do vínculo (132) ficam cobertos pelos testes da TASK-680 e 681, com `QueryException` montada à mão; não repeti a falha real neles.
