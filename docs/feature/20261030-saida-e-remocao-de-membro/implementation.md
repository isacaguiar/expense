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
| TASK-550 | Concluída | 2026-10-30 | Claude (IA) | **TDD.** `php artisan test --filter group_member_removed` primeiro **vermelho** (2 falhas, `Call to undefined method App\Support\Notifier::groupMemberRemoved()`) e depois **verde**; `--filter 'NotifierTriggersTest|NotificationModelTest'` → 22 passed (85 assertions), com os casos existentes de `group_member_added` e do `guard` intactos. **Mutation check** (4 mutações aplicadas uma a uma no `Notifier.php` e revertidas por script): gravar `group_id` do grupo (falha), incluir `groupId` no `data` (falha), notificar o id errado (falha), tirar o `guard` (falha); em todas 1 failed, 1 passed, e o arquivo voltou idêntico. `./vendor/bin/pint --test` → PASS (200 arquivos). `php artisan test` (suíte inteira) → **888 passed (5178 assertions)**, 127 s; antes eram 886 (5172): +2 testes e +6 asserções, nenhum teste existente editado. `git diff --stat -- backend/routes backend/app/Http` vazio (o método ainda não é chamado por ninguém). | Sub-branch `feature/20261030-saida-e-remocao-de-membro-TASK-550`, integrada na branch da feature por `merge --no-ff` (`04-implementation.md` §1). Conferido antes: `ex_notifications.type` é `string` livre e `group_id` é `nullable` (migration `2026_09_03_000000`), então não há migration; `NotificationController` filtra só por `user_id`, então a linha sem `group_id` aparece no sino. A linha vai com `group_id` nulo e sem `groupId` no `data` porque `NotificationsMenu.tsx` navega para `/groups/{id}/summary` quando há grupo, e o removido já não pode abri-lo. O método é consumido pela TASK-551 (`destroy`, só quando não é a própria saída); o texto da notificação no frontend é a TASK-557. Nada foi publicado: a branch só existe localmente. |
