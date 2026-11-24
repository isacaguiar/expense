# Specify — Foto de perfil: delete confinado e troca de foto segura

> Feature: endurecer a remoção e a troca da foto de perfil, dois achados informativos da revisão de segurança de `20261116-endurecer-upload-de-foto-de-perfil`. Origem: item 159 do backlog (`docs/backlog/avatar-storage-delete-sem-confinar-e-troca-de-foto-apaga-antes-de-gravar.md`).

Versão: 1.1 · Criado em: 20261123 · v1.1: D4-A (falha de gravação do `store` aborta com 500)

---

## 1. Problema

Nenhum dos dois achados é explorável hoje; são robustez de uma rota que apaga arquivos. O primeiro impede que um dado ruim em `users.photo_path` apague arquivo fora da área de avatares; o segundo evita que o usuário perca a foto antiga se a gravação da nova falhar.

## 2. Requisitos

Decisões dele (2026-11-23): D1-A, D2-A, D3-A e, achada na TASK-673, D4-A.

### 2.1 `AvatarStorage::delete` não confina o path (D1-A)

Conferido em `backend/app/Support/AvatarStorage.php:53-60`: `delete(?string $path)` só ignora vazio e chama `Storage::disk('local')->delete($path)`, sem checar o prefixo. Os chamadores são `UserController.php:89` (troca) e `:104` (remoção), ambos com `$user->photo_path`, que hoje só o servidor grava (`avatares/<userId>/<uuid>.<ext>`).

- `delete` passa a ser **no-op silencioso** para caminho que não comece por `avatares/` ou que contenha `..` (segmento de volta de diretório). Caminho vazio continua no-op.
- Só `AvatarStorage`. `ProofStorage::delete` fica como está (D1-A).

### 2.2 A troca de foto apaga a antiga antes de gravar a nova (D2-A)

Conferido em `UserController.php:89-91` (`uploadPhoto`): `AvatarStorage::delete($user->photo_path)`, depois `store`, depois `save`. Se o `store` lançar exceção (disco cheio, erro de I/O), a foto antiga já foi apagada e `photo_path` aponta para um arquivo que não existe.

- A ordem passa a ser: guardar o `photo_path` antigo, **gravar a nova** (`store`), **salvar** o novo `photo_path`, e só então **apagar a antiga**.
- Se o `store` falhar, nada foi apagado e `photo_path` segue intacto. Se o `delete` da antiga falhar depois do `save`, sobra um arquivo órfão no disco, inofensivo e varrível (custo aceito na D2-A).
- **Falha de gravação (D4-A):** o disco `local` tem `'throw' => false` (`config/filesystems.php`): numa falha (disco cheio, permissão) `storeAs` devolve `false`, e `AvatarStorage::store`, com tipo de retorno `string` e sem `strict_types`, o converte em `''` em vez de lançar. Sem tratar isso, a troca gravaria `photo_path = ''` e apagaria a foto antiga mesmo na ordem nova. `AvatarStorage::store` passa a abortar com 500 (`Não foi possível gravar a foto.`) quando `storeAs` devolve `false`, como já faz `ProofStorage::store`; nada é salvo nem apagado.
- A remoção de foto (`deletePhoto`, linha 104) não muda de ordem; ela passa a herdar só o confinamento do 2.1.
- Sem mudança de contrato da API (rota, corpo e resposta iguais) e sem migration.

## 3. Fora de escopo desta feature

- Confinar `ProofStorage::delete` (D1-A); vira item próprio se a revisão achar o mesmo.
- Varrer ou limpar arquivos órfãos de avatar no disco.
- Qualquer mudança de contrato, de front ou de migration.
- Outros itens do backlog (D3-A: só o 159).
