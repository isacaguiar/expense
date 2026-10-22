# Implementation — Editar comprovante e descrição depois de paga

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261022

---

## 1. Desvios do fluxo padrão

Nenhum desvio de fluxo. Notas de ambiente:

- **Banco dos testes:** MySQL descartável `mysql-expense-test` (mysql:8.0.17) em `127.0.0.1:3307`, com `backend/.env.testing` apontando para ele (recriado em 2026-10-22; o da feature anterior foi desmontado no fechamento). Antes de qualquer `migrate`, o alvo é conferido com `php artisan tinker --env=testing --execute="echo config('database.connections.mysql.port');"` → `3307`. O `APP_KEY` do `.env.testing` é gerado com `php -r 'echo "base64:".base64_encode(random_bytes(32));'` (a saída colorida de `key:generate --show` quebra o `ExampleTest`). Os containers `mysql` (3306) e `mysql-painel-test` (3308), de outros contextos, não são tocados.
- **Limpeza obrigatória no fim (TASK-461):** `docker rm -f mysql-expense-test`, apagar `backend/.env.testing` (não é ignorado pelo git; nunca commitar) e apagar do disco só os comprovantes que a própria validação criou (os referenciados no banco descartável).
- **Nunca `git add -A`** nesta árvore: há pastas de outras features e `bash.exe.stackdump` untracked; adicionar sempre caminhos explícitos.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-449 | Concluída | 20261022 | Claude (IA) | Linha de base: `cd backend && php artisan test --filter=ExpenseControllerPayTest` → 39 passed (134 assertions). RED: 4 testes novos em `ExpenseControllerPayTest`; mesmo comando → 2 failed, 41 passed: `repaying with a new photo deletes the previous photo` e `…also deletes a legacy photo from the public disk` falham com `Failed asserting that true is false` (a foto antiga continuava no disco); os outros dois (`first pay with a photo stores exactly one file`, `repaying without a photo keeps the existing photo`) já passavam e valem como guarda. GREEN após guardar `$previousProofPath` e chamar `ProofStorage::delete($previousProofPath)` depois do `$quota->update($update)`, só quando entrou foto nova e o caminho mudou: `--filter=ExpenseControllerPayTest` → 43 passed (152 assertions). `./vendor/bin/pint --test` → PASS, 168 files. `php artisan test` (suíte completa, alvo `3307`) → **494 passed (3035 assertions)**, 103s (490 anteriores + 4 novos). | Os 39 testes que já existiam no arquivo passaram sem edição, incluindo `pay` sem corpo → 200 e as notificações por WhatsApp. O caso de comprovante legado (path plano `comprovantes/legado.jpg`, disco `public`, resolvido por `ProofStorage::resolveDisk`) é apagado do disco certo. A ordem é a de R3 (grava, atualiza o banco, só então apaga o antigo). `unpay()` não foi alterado (continua apagando antes do `update`, comportamento existente fora do escopo). Item 084 do backlog marcado `Promovido para TASK-449` no índice e no arquivo do item (commit `1bf53cd440`). |
