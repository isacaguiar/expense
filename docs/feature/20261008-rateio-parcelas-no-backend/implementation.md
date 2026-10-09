# Implementation — Rateio de parcelas no backend

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261008

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio. A feature segue `04-implementation.md`: branch da feature `backend/20261008-rateio-parcelas-no-backend` a partir de `dev`, primeira task direto nela e as seguintes em branches de task com merge `--no-ff`.

Uma particularidade de organização: a promoção do item 070 do backlog para "Promovido para TASK-397" e a nota no item 071 entraram num commit de documentação (`f906bf15b6`) antes da primeira task de código.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-397 | Concluída | 2026-10-08 | Claude (sessão com o mantenedor) | **RED:** `cd backend && php artisan test --filter=InstallmentScheduleTest` com o teste escrito e a classe ainda inexistente → `Tests: 19 failed (1 assertions)`, todos com `Class "App\Support\InstallmentSchedule" not found`. **GREEN:** o mesmo comando depois de criar `backend/app/Support/InstallmentSchedule.php` → `Tests: 19 passed (1461 assertions)`. **Mutação (a)**, resto na primeira parcela em vez da última → `6 failed, 13 passed`. **Mutação (b)**, meses acumulados sobre a data anterior em vez de a partir da inicial → `2 failed, 17 passed` (os testes de clamp de 31/01 e da 13ª parcela). Arquivo restaurado e conferido com `cmp`; `19 passed` de novo. `./vendor/bin/pint --test` → `PASS 165 files`. `php artisan test --testsuite=Unit` → `Tests: 48 passed (1535 assertions)`. | Vetores do critério de aceite cobertos (100/3, 10/3, 0,10/3, 1,00/3, 1000,01/7, total 0, N=1, divisão exata, total como string) mais: todo N de 1 a 120 fecha o total para 6 totais, `value_quota` sempre `float` (o `/` do PHP devolveria `int` em divisão exata, e o teste `assertSame` pega isso), datas com clamp, bissexto e virada de ano. Os vetores de teste são os que a TASK-398 vai conferir contra `frontend/src/utils/installments.ts`. A classe não foi ligada a nenhum controller ainda (TASK-399 em diante). Suíte de feature (banco) não rodada nesta task: nada existente foi alterado. |
