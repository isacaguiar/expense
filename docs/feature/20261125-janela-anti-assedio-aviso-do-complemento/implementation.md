# Implementation — Janela anti-assédio no aviso do complemento

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261125

---

## 1. Desvios do fluxo padrão

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-687 | Concluída | 2026-11-25 | Claude | RED: `phpunit --filter test_a_second_complement_within_the_window` falhou com "4 is identical to 2". Depois do código: `phpunit SettlementComplementTest SettlementHistoryAuthorizationMatrixTest NotifierTriggersTest` → OK (180 testes, 734 asserções); `pint --test` → PASS 275 arquivos | `Notifier::settlementComplementAdded` usa `oncePer` (1 h) com `complementNoticeKey`; controller passa `$debtorId`, `$creditorId`, `$callerId`. Mutantes e testes de borda ficam na TASK-688 |
| TASK-688 | Concluída | 2026-11-25 | Claude | `phpunit tests/Feature/SettlementComplementTest.php` → OK (47 testes, 226 asserções). Mutantes (backup + `cmp`, "mutante aplicado", restaurado idêntico): (a) sem o autor na chave → derrubado por `test_another_author_is_not_silenced_by_the_first`; (b) sem o ciclo → `test_another_cycle_is_not_silenced`; (c) 24 h no lugar de 60 min → `test_after_the_window_the_next_complement_notifies_again`; (d) chave única por chamada (equivale a sem `oncePer`) → 3 testes. `pint --test` → PASS 275 arquivos | Cinco testes novos. A chave por ciclo é provada chamando `Notifier` direto (o cenário só tem um ciclo com comprovante). No teste de falha do cache, o token JWT é emitido antes do `Cache::partialMock()`, porque o JWT resolve o cache na primeira emissão |
