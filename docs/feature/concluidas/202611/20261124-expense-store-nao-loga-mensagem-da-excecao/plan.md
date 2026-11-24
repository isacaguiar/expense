# Plan — `ExpenseController::store` não loga a mensagem da exceção pós-commit

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261124

---

## 1. O log de `afterCommit` sem a mensagem da exceção (specify §2.1, D1-A)

- **Onde:** `backend/app/Http/Controllers/ExpenseController.php:902-909`, método privado `afterCommit(string $step, callable $fn): void`. Troca o contexto `['exception' => $e->getMessage()]` por `['exception' => $e::class]`. A mensagem do log (`"store: falha no passo pós-commit ({$step})"`), o `try/catch (\Throwable)` e o fato de a exceção não subir ficam como estão. É o mesmo formato do `Notifier::guard` (TASK-676).
- **Docblock:** uma linha dizendo que a mensagem fica de fora porque, numa `QueryException`, traz o SQL com os bindings.
- **Por que assim e não outra:** registrar só a classe é o critério já adotado no `Notifier::guard`; o passo já está na mensagem do log, então o diagnóstico não perde o essencial. Mascarar bindings (C da feature anterior) é frágil e não foi pedido.

## 2. Teste (specify §2.1)

- **Achado que molda o teste:** os 5 passos pós-commit chamam métodos do `Notifier`, que já têm o próprio `guard` e engolem a falha antes de chegar ao `afterCommit`; o `dispatchProofNotice` só agenda um fechamento para depois da resposta. Logo, nenhuma falha forçada por uma requisição HTTP chega a esse `catch` (o teste existente `test_a_failure_in_a_step_after_the_commit_keeps_the_expense_and_the_payment` exercita o `guard` do `Notifier`, não o `afterCommit`). O `catch` do `afterCommit` é uma segunda camada defensiva.
- **Decisão técnica (D4 abaixo):** testar `afterCommit` direto por reflexão, num teste novo em `backend/tests/Feature/ExpenseControllerStorePaymentTest.php` (onde já vive o teste do passo pós-commit): instanciar `ExpenseController`, tornar o método acessível com `ReflectionMethod` e passar um fechamento que lança.
  - Teste 1: o fechamento lança `\RuntimeException('mensagem sigilosa com Joana')`; `Log::spy()`; afirma com `Log::shouldHaveReceived('warning')->withArgs(...)` que a mensagem do log é a de sempre com o passo, que o contexto tem exatamente a chave `exception` igual a `\RuntimeException::class`, e que `json_encode` do contexto não contém o texto sigiloso; a exceção não sobe.
  - Teste 2 (`QueryException` real): o fechamento faz um `INSERT` com violação de FK (como no teste da 677) com um nome reconhecível nos bindings; afirma `exception === QueryException::class` e que o contexto não contém o nome nem `insert into`.
- **Mutantes (como na 676):** (A) voltar a `'exception' => $e->getMessage()`; (B) `$e::class` somado à mensagem. Cada um precisa derrubar ao menos um teste; aplicar com backup e `cmp`, conferindo "mutante aplicado".
- **Verificação:** os testes novos, `ExpenseControllerStorePaymentTest` inteiro, a suíte cheia e `./vendor/bin/pint --test`.

## 3. Item 165 do backlog e README (specify §2.2 D2-A, §3)

- **Onde:** arquivo novo `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` (ID 165, prioridade BAIXA, decisões em tabela) e a linha no `docs/backlog/README.md`. Lista os 7 pontos que a varredura achou e ainda não têm item: `GroupInvitationService.php:254`, `InvitationController.php:97`, `PreRegistrationService.php:288` e `GoogleAuthController.php:116, 132, 180, 228`; aponta o 164 (`WhatsAppNotifier`) como parte do mesmo tema. Registra que `InvitationController:97` e `GroupInvitationService:254` também logam e-mail/ids e que o `GoogleAuthController` usa `get_class($e).': '.$e->getMessage()` em dois pontos.
- O item 163 passa a "Promovido para TASK-0xx" na aprovação das tasks e vai para `concluidos/` no fechamento, como manda o skill.
- **Sem edição em `docs/sdd/`** (D3-A): a convenção da v1.4 do `06-context-backend.md` já cobre; nada em `01-specify.md` além do que já existe.

## 4. Decisão técnica a aprovar

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D4 | Como testar o `catch` do `afterCommit` | A) chamar o método privado por reflexão, com fechamento que lança<br>B) tornar o método `protected`/público só para o teste<br>C) forçar a falha por HTTP (não chega: o `Notifier` engole antes) | **A** | Não muda a visibilidade nem o código de produção por causa do teste; a reflexão é uma linha e o contrato testado é o contexto do log. C não é possível, como mostra o §2. |

## 5. Ordem de execução

Seção 1 e 2 juntas, na mesma task (teste em RED primeiro, depois o código), pois o teste só existe para esta mudança; a seção 3 (backlog e README) é doc e vem por último. Sem dependência entre código e doc; o critério é "código antes de doc", como na feature anterior.
