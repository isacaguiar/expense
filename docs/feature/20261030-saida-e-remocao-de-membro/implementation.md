# Implementation — Saída e Remoção de Membro do Grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261030

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-549 | Concluída | 2026-10-30 | Claude (IA) | **TDD.** `php artisan test --filter MemberExitBlockersTest` primeiro **vermelho** (12 falhas, `Target class [App\Services\MemberExitBlockers] does not exist`) e depois **verde** (12 passed, 13 assertions). **Mutation check** (5 mutações aplicadas uma a uma no código e revertidas por script): `>` virando `>=` na fronteira da fixa (2 testes falham), sem o `whereNotIn` que evita item duplicado (1), sem `deleted = false` (1), credor sozinho passando a bloquear (2), parcela paga passando a bloquear (2); `git status` depois mostrou só os 2 arquivos novos. `./vendor/bin/pint --test` → PASS (200 arquivos). `php artisan test` (suíte inteira) → **886 passed (5172 assertions)**, 101 s; antes eram 874 (5159): +12 testes e +13 asserções, nenhum teste existente editado. `git diff --stat -- backend/routes backend/app/Http` vazio (a classe ainda não é chamada por ninguém). | Primeira task da feature: feita direto na branch da feature, sem sub-branch (`04-implementation.md` §1). Ambiente: MySQL de teste `127.0.0.1:3307/ex-db` (alvo conferido com `tinker` antes de rodar) e `.env.testing` recriado, ignorado pelo git. A classe fica em `backend/app/Services/` e é consumida pela TASK-551. A branch só existe localmente: nada foi publicado. |
