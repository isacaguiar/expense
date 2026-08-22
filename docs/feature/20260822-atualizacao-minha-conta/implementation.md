# Implementation — Atualização da Página Minha Conta

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-194 | Concluída | 20260822 | IA | `php artisan make:migration add_whatsapp_to_users_table` + `php artisan migrate` — `2026_08_22_193236_add_whatsapp_to_users_table DONE (373ms)`, sem erro. `php artisan tinker --execute="print_r(Schema::getColumns('ex_users'));"` confirma colunas `whatsapp` (`varchar`) e `notify_whatsapp` (`tinyint`). `./vendor/bin/pint database/migrations/2026_08_22_193236_add_whatsapp_to_users_table.php` — 1 issue corrigido (line ending/braces), depois `--test` limpo. `php artisan test` (suíte completa) — 186 passed (575 assertions). | Migration só (schema), sem teste PHPUnit novo — não é regra de negócio (Constitution §2.2), o comportamento que precisa de teste é da TASK-195. **Desvio da task planejada em `tasks.md`**: não adicionei `whatsapp`/`notify_whatsapp` a `User::$fillable`, porque `pix` e `invited_by` — colunas reais já em uso — nunca foram adicionadas lá; `UserController` sempre atribui via propriedade direta (`$user->pix = ...`), não mass assignment. Adicionar ao `$fillable` seria código morto, inconsistente com o padrão já estabelecido no model. |
| TASK-195 | Concluída | 20260822 | IA | `./vendor/bin/pint --test app/Http/Controllers/UserController.php tests/Feature/UserControllerTest.php` — PASS, 2 files. `php artisan test --filter=UserControllerTest` — 8 passed (19 assertions), 2 testes novos. `php artisan test` (suíte completa) — 188 passed (581 assertions). | `UserController::updateProfile` valida `whatsapp` (`regex:/^\(\d{2}\) 9\d{4}-\d{4}$/`, nullable) e `notify_whatsapp` (`boolean`, via `$request->boolean()`), persiste os 2 e inclui no JSON de resposta. `User::$casts` ganhou `'notify_whatsapp' => 'boolean'` (sem isso o tinyint sairia como `0`/`1` no JSON, não `true`/`false`) — único ponto tocado em `User.php`; os 2 issues de Pint pré-existentes no arquivo (`class_attributes_separation`, `single_line_after_imports`) continuam os mesmos de antes desta task, não são desta mudança (fora de escopo, "não corrigir de passagem"). |
| TASK-196 | Concluída | 20260822 | IA | `npx tsc --noEmit` — limpo. `npx vitest run src/pages/Profile.test.tsx` — 6 passed (1 file), 3 testes novos (exibição dos campos, máscara progressiva, toggle do checkbox) + payload do PUT atualizado. `npx vitest run` (suíte completa) — 133 passed (20 files). | `Profile.tsx`: campo `whatsapp` com máscara própria (`formatWhatsapp`, sem lib nova) e `FormControlLabel`/`Checkbox` "Receber notificações pelo WhatsApp"; estado e submit (`PUT /api/user/profile`) incluem os 2 campos. 1ª rodada da suíte completa mostrou 1 falha em `AcceptInvitePage.test.tsx` (arquivo não tocado por esta task); rodado isolado passou (3/3) e a suíte completa rodou de novo 100% verde (133/133) — flakiness/interferência de ordem pré-existente entre arquivos de teste, não causada por esta mudança. |
