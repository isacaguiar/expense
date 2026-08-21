# Implementation — ExpenseManager: mês e data corretos

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-132 | Implementada na branch da feature | 2026-08-21 | IA (Claude Code) | `cd backend && ./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php tests/Feature/ExpenseControllerIndexByGroupTest.php` — PASS; `php artisan test --filter=ExpenseController` — 46 passed (126 assertions), incluindo as 3 novas de parcelamento (aparece no mês de criação com valor da 1ª parcela, aparece nos meses seguintes com o valor de cada parcela, não aparece após a última parcela); `php artisan test` (suíte completa) — 102 passed (250 assertions) | `value` do novo conjunto (`$installmentQuotas`) mantido como string decimal (`"300.00"`, sem cast para `float`), para ficar consistente com `$direct`/`$projectedFixed` (que também retornam `total_value` sem cast, efeito do `decimal:2` do model) — descoberto ao rodar os testes na primeira tentativa (falhavam comparando `300` com `"300.00"`) |
