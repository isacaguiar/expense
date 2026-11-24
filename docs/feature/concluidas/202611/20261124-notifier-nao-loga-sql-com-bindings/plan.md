# Plan — Notifier não loga SQL com bindings

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261124

---

## 1. O log de `Notifier::guard` deixa de carregar a mensagem (specify §2.1, D1-A, D2-A)

Só `backend/app/Support/Notifier.php`, método `guard` (linhas 476-492); nenhum chamador muda.

- Trocar o contexto do `Log::warning('Notifier: falha ao gravar notificação', [...])` de `['type' => $type, 'error' => $e->getMessage()]` para `['type' => $type, 'exception' => $e::class]`. A mensagem do log (primeiro argumento) continua igual.
- **Por que `$e::class` e não `get_class($e)`:** mesma coisa, forma curta do PHP 8 que o projeto já usa (PHP 8.1); evita uma chamada a função.
- **Por que sem a mensagem e sem mascarar (D1-A):** mascarar bindings exigiria reconhecer o formato do SQL de cada driver; descartar a mensagem é à prova de vazamento. A classe (`Illuminate\Database\QueryException`, `JsonException`...) basta para saber o tipo de falha; o `type` já diz qual aviso falhou.
- Atualizar o docblock do método para registrar que o log não leva a mensagem da exceção, por causa de SQL com bindings.
- `guard` continua devolvendo `false` ao capturar e `true` quando não há exceção; a exceção continua sem subir. Sem mudança de API, rota, contrato, migration ou front.
- O `ExpenseController.php:907` fica como está (D2-A) e vira item de backlog próprio.

## 2. Testes (specify §2.1)

Em `backend/tests/Feature/NotifierTriggersTest.php`, ao lado de `test_notifier_guard_swallows_a_write_failure` (linha 185), que já força uma falha de gravação (nome de ator com byte inválido em UTF-8 faz o `json_encode` do cast `data` lançar) e usa `Log::spy()`.

- **Teste novo** `test_notifier_guard_logs_the_exception_class_and_not_its_message`: força a mesma falha e afirma, com `Log::shouldHaveReceived('warning')->withArgs(...)`, que o contexto tem `type` igual ao do aviso e `exception` igual à classe da exceção (`JsonException`), que **não** tem a chave `error` e que nenhum valor do contexto contém a mensagem da exceção.
- **Teste de vazamento de SQL com bindings:** forçar uma `QueryException` real (por exemplo um aviso cujo `type` estoura o tamanho da coluna, ou um grupo inexistente que quebre a chave estrangeira) com um nome de usuário reconhecível nos dados, e afirmar que esse nome **não** aparece no contexto do log, que `exception` é `Illuminate\Database\QueryException` e que `Notification::count()` continua 0. Se nenhuma `QueryException` for simples de provocar sem tocar no código, o teste anterior com a mensagem da exceção cobre o contrato e o plano registra o desvio no `implementation.md`.
- Os testes existentes (`test_notifier_guard_swallows_a_write_failure`, `test_group_member_removed_swallows_a_write_failure` e os demais com `Log::shouldHaveReceived('warning')` sem `withArgs`) continuam verdes.
- **Mutantes:** voltar o contexto para `'error' => $e->getMessage()` e acrescentar a mensagem de volta junto com `exception` derrubam o teste novo.

## 3. Documentação

- `docs/sdd/06-context-backend.md`: na convenção sobre best-effort e log de notificações (conferir o texto real antes de editar; se não houver parágrafo próprio, acrescentar uma linha em "Convenções fixas"), registrar que o log de `Notifier::guard` leva só o `type` e a classe da exceção, nunca a mensagem, porque `QueryException` traz o SQL com bindings (versão sobe).
- `docs/sdd/01-specify.md` §3.3: bullet curto, versão sobe. Sem mudança de Constitution.
- Item 155 do backlog: marcar o ponto 2 como resolvido por esta feature (com o PR) e manter o item **aberto** com os pontos 1, 3 e 4; novo item de backlog para o `ExpenseController.php:907`.

## 4. Ordem de execução

Há dependência: o §2 (teste, RED) vem antes do §1 (código, GREEN) dentro de uma só task; o §3 por último. Um PR único contra `dev` no fim, depois de `security-reviewer` (toca log de dado pessoal) e `pr-readiness-checker`. Sem front e sem migration; a validação local dele é opcional (não há tela envolvida), e a conferência é por teste.
