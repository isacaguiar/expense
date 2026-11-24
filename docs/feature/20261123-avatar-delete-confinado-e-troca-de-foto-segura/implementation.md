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
| TASK-674 | Concluída | 2026-11-23 | Claude | RED: 2 testes novos (`AvatarStorageTest::test_store_aborta_com_500_quando_o_disco_nao_consegue_gravar` e `UserPhotoTest::test_a_failed_write_keeps_the_previous_photo_and_its_file`) com o código antigo = 2 falhas; o feature mostrou resposta 200 com a gravação falhando, confirmando o achado da 673. GREEN: `AvatarStorage::store` aborta com 500 (`Não foi possível gravar a foto.`) quando `storeAs` devolve `false`, e `uploadPhoto` guarda `$oldPath`, grava, salva e só então apaga a antiga: `./vendor/bin/phpunit tests/Unit/AvatarStorageTest.php tests/Feature/UserPhotoTest.php tests/Feature/UserPhotoHardeningTest.php` = 28 testes, 78 asserções, OK; `./vendor/bin/pint --test` nos 4 arquivos = PASS. Mutantes mortos (arquivos restaurados e conferidos com `cmp`): sem a checagem `=== false` (2 falhas), `delete` da antiga de volta para antes do `store` (1 falha). | Plan e specify subiram para v1.1 e tasks para v1.1 com a D4-A (aprovada por ele em 2026-11-23). Na primeira tentativa o heredoc do Bash comeu as barras invertidas do script de teste e nada foi aplicado; refeito com `Edit`. O teste de falha usa um mock que embrulha o disco que já tem a foto antiga, em vez de `FailsToWriteProofs::failProofWrites()`, que recria o disco e perderia a foto antiga. |
| TASK-675 | Concluída | 2026-11-23 | Claude | `docs/sdd/06-context-backend.md` v1.2 → v1.3 (convenção de arquivos enviados: disco `local` com `throw` falso, `store` aborta com 500, `AvatarStorage::delete` confinado a `avatares/`, gravar antes de apagar); `docs/sdd/01-specify.md` v1.11 → v1.12 (bullet "Foto de perfil: troca e remoção seguras" em §3.3). `git diff --stat -- docs/sdd` lista só esses dois arquivos. | O `06-context-backend.md` não descrevia o armazenamento da foto, então a regra entrou como convenção fixa nova, não como edição de texto existente. O caminho da feature nos dois docs é `docs/feature/20261123-...`; troca para `concluidas/202611/` no arquivamento (ADR-009). |
