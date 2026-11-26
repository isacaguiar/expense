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
| TASK-689 | Concluída | 2026-11-25 | Claude | Edição de `docs/backlog/achados-informativos-…md` (Status; ponto 4 riscado com a Resolução) e da linha 146 do `docs/backlog/README.md`; `grep -c Concluída tasks.md` → 3; `grep -n 155 docs/backlog/README.md` → linha 146 presente; `grep Aberto` no item → `Status: Aberto (pontos 2 e 4 resolvidos …; pontos 1 e 3 seguem abertos)`; `ls` confirma o arquivo ainda em `docs/backlog/` | Item 155 permanece em `docs/backlog/` (pontos 1 e 3 abertos). O PR será registrado na Resolução quando existir, e o caminho `docs/feature/concluidas/202611/…` citado no ponto 4 só passa a valer quando a pasta for arquivada (ADR-009, depois do merge) |

## 3. Fechamento

- **Suíte cheia do backend (2026-11-25):** `./vendor/bin/phpunit` → OK (1760 testes, 9496 asserções); `./vendor/bin/pint --test` → PASS 275 arquivos.
- **`security-reviewer`:** 0 crítico, 0 alto, 0 médio, 0 baixo; 3 informativos (aceitos: `fanOut` parcial herdado do vínculo, janela depende de cache compartilhado e produção usa `file`, nenhum outro chamador da assinatura antiga).
- **`pr-readiness-checker`:** pronto para abrir PR (duas ressalvas de documentação, tratadas: linha da TASK-689 com os comandos reais; caminho `concluidos/` do ponto 4 só vale após o arquivamento).
