# Tasks — Foto de perfil: delete confinado e troca de foto segura

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261123

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-673 | Confinar `AvatarStorage::delete` a `avatares/` e recusar `..` | backend | plan.md §1 | nenhum | Concluída |
| TASK-674 | Gravar a nova foto e salvar o `photo_path` antes de apagar a antiga em `uploadPhoto` | backend | plan.md §2 | nenhum | Pendente |
| TASK-675 | Documentar a regra em `06-context-backend.md` e `01-specify.md` | doc | plan.md §3 | nenhum | Pendente |

Ordem: 673 primeiro (arquivo de teste novo, sem tocar nos testes existentes); 674 depois; 675 por último. As duas primeiras mexem em rota que apaga arquivo: rodar o `security-reviewer` no fechamento da feature (rota de upload e remoção de foto), como nas features de foto anteriores.

## Critérios de aceite

- **TASK-673**: `AvatarStorage::delete` não apaga nada, sem lançar exceção, para caminho que não comece por `avatares/` ou que tenha um segmento `..` (separado por `/` ou `\`); caminho `null` ou vazio continua sem efeito; `avatares/1/foto..png` e um caminho normal `avatares/<id>/<uuid>.<ext>` continuam sendo apagados. `backend/tests/Unit/AvatarStorageTest.php` (novo, `Storage::fake('local')`) cobre: dentro do prefixo apaga; `uploads/outro.png` continua no disco; `avatares/../outro.txt` e `avatares/1/..\x` não apagam o alvo; `null` e `''` não lançam; `avatares/1/foto..png` é apagado. Mutantes (tirar a checagem de prefixo; tirar a de `..`) derrubados por ao menos um teste cada. `./vendor/bin/phpunit` dos testes de foto (`UserPhotoTest`, `UserPhotoHardeningTest`, `AvatarStorageTest`) verde e `./vendor/bin/pint --test` limpo. Docblock do método atualizado.
- **TASK-674**: `uploadPhoto` guarda `$user->photo_path`, grava a nova (`store`), salva e só então chama `AvatarStorage::delete` na antiga; a resposta e a rota não mudam. Teste novo em `UserPhotoTest`: com a foto antiga gravada e a gravação da nova falhando, a resposta é de erro, `photo_path` no banco continua o antigo e o arquivo antigo ainda existe. Os testes `uploading a second photo removes the first from disk` e `a refused svg does not replace the photo...` continuam verdes. Mutante (voltar o `delete` da antiga para antes do `store`) derrubado pelo teste novo. `./vendor/bin/phpunit` dos testes de foto verde e `pint --test` limpo.
- **TASK-675**: `docs/sdd/06-context-backend.md` descreve que `AvatarStorage::delete` só apaga dentro de `avatares/` e que a troca grava antes de apagar, com a versão subida; `docs/sdd/01-specify.md` §3.3 ganha o bullet com a versão subida; `git diff` não lista specify de feature arquivada.
