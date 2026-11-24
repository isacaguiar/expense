# Implementation — Foto de perfil: delete confinado e troca de foto segura

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261123

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-673 | Concluída | 2026-11-23 | Claude | RED: `./vendor/bin/phpunit tests/Unit/AvatarStorageTest.php` (7 testes novos) com o código antigo = 4 falhas, 3 passam. GREEN: `AvatarStorage::delete` com `isInsideAvatars` (`str_starts_with($path, 'avatares/')` e nenhum segmento `..` separado por `/` ou `\`) = 7 testes passam; `./vendor/bin/pint --test` nos 2 arquivos = PASS. Com o MySQL de teste (3307, `migrate:fresh --env=testing`): `phpunit tests/Unit/AvatarStorageTest.php tests/Feature/UserPhotoTest.php tests/Feature/UserPhotoHardeningTest.php` = 25 testes, 70 asserções, OK. Mutantes mortos: sem a checagem de prefixo (2 falhas), sem a de `..` (2 falhas); arquivo restaurado e conferido com `cmp`. | Primeira tentativa do mutante de `..` não aplicou (aspas do `python -c`) e o teste passou sem mutação: descartada e refeita com script em arquivo. Os testes de Feature falharam de início por banco sem schema (`ex_users` inexistente) até o `migrate:fresh`. **Achado para a TASK-674:** `AvatarStorage::store` devolve `$file->storeAs(...)` com tipo de retorno `string` e sem `strict_types`; se o disco falhar, `storeAs` devolve `false` e o PHP o converte em `''` em vez de lançar, então `uploadPhoto` gravaria `photo_path = ''`. Ver o ajuste proposto na 674. |
