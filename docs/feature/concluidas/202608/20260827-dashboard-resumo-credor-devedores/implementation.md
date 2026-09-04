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
| TASK-213 | Concluída | 2026-08-27 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/components/GroupGrossDebtsPanel.test.tsx` — 4/4 verde. `npx vitest run` (suíte completa) — 167/167 na melhor rodada (1 falha de timeout em `ChangePassword.test.tsx` numa rodada sob carga — arquivo não tocado, confirmado 2/2 verde isolado). | Durante a implementação, adicionei o campo `pix` ao objeto `creditor` da resposta de `GET .../gross-debts` (fix pontual em cima da TASK-212, ainda na mesma feature branch, não mergeada) — faltava para o painel replicar o padrão já usado em `Payments.tsx` (só oferecer o Pix se o credor tem chave cadastrada) sem uma segunda chamada a `/members`. Também corrigi os `aria-label` dos botões de Pix/"Informar pagamento" para incluir credor+devedor — a primeira versão gerava nomes acessíveis duplicados quando um credor tinha mais de um devedor (pego pelo próprio teste, que falhou com "multiple elements found" antes do ajuste). |
| TASK-214 | Concluída | 2026-08-27 | IA (Claude Code) | `npx tsc --noEmit` — sem erro. `npx vitest run src/pages/Dashboard.test.tsx` — 16/16 verde (14 pré-existentes sem alteração de asserções + 2 novos: expandir/colapsar, duas linhas independentes). `npx vitest run` (suíte completa) — 169/169 verde, sem flakiness nesta rodada. | Confirmado que o mock genérico de `axios.get` já existente em `Dashboard.test.tsx` (`mockGroupsAndMe`) não interferia com o endpoint novo por coincidência (nenhum teste pré-existente expande uma linha) — ainda assim, tornei o mock explícito por `groupId` para os testes novos, em vez de depender desse acaso. |
