# Implementation — Dashboard: resumo Credor→devedores por grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260827

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-212 | Concluída | 2026-08-27 | IA (Claude Code) | `./vendor/bin/pint --test app/Http/Controllers/ExpenseController.php routes/api.php tests/Feature/ExpenseControllerGrossDebtsTest.php` — PASS, 3 files. `php artisan test --filter=ExpenseControllerGrossDebtsTest` — 5/5 verde (16 assertions). `php artisan test` (suíte completa) — 223/223 verde (682 assertions; base do `dev` sem a feature de Relatórios, que está em outra branch ainda não mergeada — 218 pré-existentes + 5 novos). | Durante a implementação, corrigi um bug próprio antes de commitar: a fórmula inicial dividia o valor da despesa só pelo número de devedores (excluindo o credor), divergindo de `valuePerPerson`/`computeCycleSummary` (que divide por **todos** os participantes, inclusive o credor quando ele também participa da divisão). Corrigido para usar `$expense->payers->count()` como divisor, mesma fórmula do resto do sistema — pego pelo teste `a creditor with multiple debtors shows each gross share` antes mesmo de rodar (revisão do código antes do commit). |
