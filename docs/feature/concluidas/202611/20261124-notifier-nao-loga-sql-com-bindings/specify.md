# Specify — Notifier não loga SQL com bindings

> Feature: `Notifier::guard` deixa de gravar `$e->getMessage()` no log, porque uma `QueryException` traz o SQL com os bindings (nomes de usuário e de grupo). Origem: ponto 2 do item 155 do backlog (`docs/backlog/achados-informativos-da-revisao-de-seguranca-do-historico-de-comprovantes.md`), achado informativo do `security-reviewer` na feature `20261114-historico-de-comprovantes-e-justificativa`. Os pontos 1, 3 e 4 do item ficam abertos no backlog.

Versão: 1.0 · Criado em: 20261124

---

## 1. Problema

O aviso (notificação) é best-effort: `Notifier::guard` captura qualquer exceção e só a registra, para não quebrar a ação que a disparou. O registro grava `$e->getMessage()`. Numa `QueryException` essa mensagem inclui o SQL com os valores ligados, que podem ser nomes de usuário e do grupo. É dado pessoal indo parar no `laravel.log` sem necessidade: para diagnosticar basta saber que tipo de aviso falhou e qual foi a exceção. Nada disso é exploração hoje; é higiene de log.

## 2. Requisitos

Decisões dele (2026-11-24): só o ponto 2 do item 155; o item 155 continua aberto com os demais pontos.

### 2.1 O log de `Notifier::guard` não carrega a mensagem da exceção

Conferido em `backend/app/Support/Notifier.php:476-492`: `guard(string $type, callable $fn): bool` captura `\Throwable $e` e chama `Log::warning('Notifier: falha ao gravar notificação', ['type' => $type, 'error' => $e->getMessage()])`. Há 21 chamadas a `guard` em `app`, e nenhum teste hoje afirma o conteúdo desse log.

- O contexto do log passa a ter `type` (o tipo do aviso, como hoje) e `exception` com **a classe da exceção** (`get_class($e)`), e **não** a mensagem nem o SQL.
- A chave `error` (com `getMessage()`) deixa de existir no contexto.
- O comportamento fora do log não muda: `guard` continua devolvendo `false` ao capturar, `true` quando não há exceção, e a exceção continua sem subir.
- Sem mudança de API, de rota, de contrato, de migration ou de front.

### 2.2 Decisões a aprovar

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar da exceção | A) só a classe (`get_class($e)`)<br>B) a classe e o código (`$e->getCode()`, que numa `QueryException` é o SQLSTATE)<br>C) manter a mensagem mascarando os bindings | **A** | Basta para saber o tipo de falha (por exemplo `Illuminate\Database\QueryException`) sem arriscar vazamento. B acrescenta pouco; C depende de reconhecer e mascarar bindings, que é frágil. |
| D2 | Outros pontos que registram `getMessage()` | A) só `Notifier::guard` (escopo do item)<br>B) também `ExpenseController.php:907` (`Log::warning("store: falha no passo pós-commit ...", ['exception' => $e->getMessage()])`) | **A** | O item pede só o `Notifier`. O de `ExpenseController` tem o mesmo padrão e vira item de backlog próprio, para decidir à parte. |

## 3. Fora de escopo desta feature

- Os pontos 1 (complemento sem referência ao comprovante), 3 (limites do ADR-005) e 4 (janela anti-assédio e `throttle` do aviso do complemento) do item 155: o item continua aberto com eles.
- Mascarar bindings ou reescrever o log de outros pontos (D2-A); o `ExpenseController.php:907` vira item de backlog.
- Qualquer mudança de contrato, de front ou de migration.
