# Implementation — Pagamento no cadastro de despesa

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.1 · Criado em: 20261027

---

## 1. Desvios do fluxo padrão

- **Banco de teste isolado.** O `backend/.env` aponta para a porta 3306, que é de outro projeto; os testes rodam num MySQL próprio na **3307** (container `mysql-expense-test`, `mysql:8.0.17`), com `backend/.env.testing` (ignorado pelo git, `backend/.gitignore:13`). Receita: memória do projeto `validacao-local-stack-isolada`. O alvo foi conferido antes de qualquer migration: `php artisan tinker --env=testing --execute="echo config('database.connections.mysql.port');"` devolveu `3307 ex-db testing`. Ao fim da feature o container e o `.env.testing` são removidos (TASK-544).
- **Testes novos em arquivos novos.** TASK-514 e TASK-519 usam arquivo de teste novo em vez de editar os existentes, para manter NR-10 (nenhum teste anterior à feature editado). Já registrado em `tasks.md`.

## 2. Linha de base

Antes de qualquer mudança de código, na branch `feature/20261027-pagamento-no-cadastro-despesa` (a partir de `dev` = `03addf7904`):

| Comando | Resultado |
|---|---|
| `cd backend && php artisan migrate:fresh --env=testing --force` | exit 0, todas as migrations até `2026_10_21_000001_add_note_to_ex_settlement_confirmations_table` |
| `cd backend && php artisan test --filter CycleSummaryServiceTest` | 42 passed (175 assertions) |
| `cd backend && php artisan test` (suíte completa) | **670 passed (4019 assertions)**, 166,19 s |

## 3. Log de implementação

Uma linha por task. Comando real executado e resultado obtido.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-512 | Concluída | 2026-10-27 | Claude (IA) | `php artisan test --filter PurchasePaymentNonRegressionTest` → **21 passed (300 assertions)** no código atual. `./vendor/bin/pint --test` nos 2 arquivos novos → PASS. `git diff --stat -- backend/app` → vazio. Mutação 1 (`compute()` passa a pular a quota quando `paid`, `CycleSummaryService.php:246`) → 10 falhas (as 9 formas de NR-01 e o netting). Mutação 2 (`totals.paid` soma também o pendente, `:218`) → 9 falhas (NR-02 por forma). Mutação 3 (o valor da entrada da Parcelada passa a ser o total da compra, `:90`) → 7 falhas, entre elas os dois testes da soma. Cada mutação foi restaurada com `git checkout --` e `git diff --stat -- backend/app` voltou a vazio; o teste voltou a 21 passed. | Arquivos novos: `backend/tests/Concerns/BuildsPurchaseScenarios.php` (`makeGroup`, `makePurchase`, `markPaid`, `cyclesFrom`) e `backend/tests/Feature/PurchasePaymentNonRegressionTest.php`. O trait ganhou `cyclesFrom`, além dos três métodos do plan §1, para calcular os ciclos de cada mês da compra. Formas cobertas: À Vista (credor participa, não participa, 2 membros), Parcelada 2x, 3x e 12x com resto de centavo, Parcelada com credor fora da divisão, Fixa (mês inicial e seguinte) e Parcelada retroativa com `born_paid`; mais o netting de dívidas em sentido contrário e a soma de `totals.paid` ao longo dos N ciclos (100,00 → 33,33 / 33,33 / 33,34; 1000,00 em 12x). O oráculo de `totals.paid` vem da tabela de quotas, não de `compute()`. |
