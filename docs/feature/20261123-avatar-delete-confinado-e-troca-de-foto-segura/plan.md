# Plan — Foto de perfil: delete confinado e troca de foto segura

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20261123 · v1.1: D4-A, `AvatarStorage::store` aborta quando a gravação falha

---

## 1. Confinar `AvatarStorage::delete` (specify §2.1, D1-A)

Só `backend/app/Support/AvatarStorage.php`; nenhum chamador muda.

- Depois do `if (! $path) return;`, acrescentar uma guarda: se `! str_starts_with($path, 'avatares/')` ou o caminho tem um segmento `..` (separado por `/` ou `\`), retorna sem apagar. Sem exceção e sem log: é no-op silencioso, como o caminho vazio.
- Detecção de `..` por segmento (`in_array('..', preg_split('#[/\\\\]#', $path), true)`), não por `str_contains($path, '..')`: um nome legítimo como `avatares/1/foto..png` não deve ser recusado, e `avatares/../x` e `avatares/1/..\x` devem.
- Um prefixo literal `avatares/` basta porque o caminho que o servidor grava é sempre `avatares/<userId>/<uuid>.<ext>` (`store`, linha 45). Não confinar também por `<userId>`: o `delete` não recebe o id e os dois chamadores só passam o `photo_path` do próprio usuário.
- Atualizar o docblock do método para dizer que só apaga dentro de `avatares/`.
- **Por que no-op e não exceção:** o item e a D1-A pedem "recusar (no-op)"; uma exceção em `deletePhoto` viraria 500 para o usuário por um dado ruim que ele não controla.
- **Testes** em `backend/tests/Unit/AvatarStorageTest.php` (novo, espelha `ProofStorageTest.php`), com `Storage::fake('local')`: caminho dentro de `avatares/` é apagado; `uploads/outro.png` (fora do prefixo) continua no disco; `avatares/../outro.txt` e `avatares/1/..\x` não apagam o arquivo alvo; `null` e `''` não lançam; `avatares/1/foto..png` ainda é apagado. Mutantes: tirar a checagem de prefixo e tirar a de `..` derrubam ao menos um teste cada.

## 2. Trocar a foto: gravar, salvar e só então apagar a antiga (specify §2.2, D2-A)

Só `UserController::uploadPhoto` (`backend/app/Http/Controllers/UserController.php`, linhas 89-91).

- Reordenar: `$oldPath = $user->photo_path;` → `$user->photo_path = AvatarStorage::store(...)` → `$user->save()` → `AvatarStorage::delete($oldPath)`. A resposta continua `['avatar_url' => $user->avatar_url]`.
- **`AvatarStorage::store` aborta quando a gravação falha (D4-A):** guardar o retorno de `storeAs`; se for `=== false`, `abort(500, 'Não foi possível gravar a foto.')`, como `ProofStorage::store` (linhas 63-66). Motivo: o disco `local` tem `'throw' => false`, `storeAs` devolve `false` e o tipo de retorno `string` sem `strict_types` o converteria em `''`, gravando `photo_path = ''` e apagando a antiga. Sem `try/catch` no controller: o `abort` sobe como 500, antes do `save` e do `delete`.
- Se o `delete` da antiga falhar depois do `save`, o `Storage::delete` do Laravel devolve `false` sem lançar, então sobra um órfão silencioso (custo aceito na D2-A).
- `deletePhoto` (linha 104) não muda: ele já apaga o arquivo e limpa `photo_path` de uma vez e herda o confinamento do §1.
- **Teste de falha de gravação** em `backend/tests/Feature/UserPhotoTest.php`: com a foto antiga gravada, fazer a gravação falhar (envolver o disco `local` já com a foto antiga num mock que devolve `false` em `putFileAs`, como `Tests\Concerns\FailsToWriteProofs`, sem recriar o disco) e afirmar que a resposta é 500, que `photo_path` no banco continua o antigo e que o arquivo antigo ainda existe. Os testes existentes (`uploading a second photo removes the first from disk`, `a refused svg does not replace the photo`) seguem como estão e cobrem o caminho feliz.
- **Teste unitário** em `tests/Unit/AvatarStorageTest.php`: `store` aborta com 500 quando o disco devolve `false`.
- **Mutantes:** voltar o `delete` da antiga para antes do `store` e tirar a checagem `=== false` derrubam o teste de falha de gravação.

## 3. Documentação

- `docs/sdd/06-context-backend.md`: onde descreve o armazenamento da foto de perfil, registrar que `AvatarStorage::delete` só apaga dentro de `avatares/` e que a troca grava antes de apagar (versão sobe). Conferir o texto real antes de editar.
- `docs/sdd/01-specify.md` §3.3: um bullet curto, versão sobe. Sem mudança de Constitution.

## 4. Ordem de execução

Há dependência só entre §1 e §2 pela suíte: ambos tocam `UserPhotoTest`/`AvatarStorage` sem conflito, então cada um vai numa task, §1 antes (arquivo novo de teste, sem risco para os testes de tela existentes), §2 depois, e §3 por último. Um PR único contra `dev` no fim. Sem front, sem migration, sem conferência de tela; a validação local dele, se quiser, é fazer upload e troca de foto na stack de teste.
