# A foto de perfil repete o padrão do comprovante antes do item 154: aceita SVG, usa a extensão do cliente e é servida sem cabeçalhos

ID: 156
Origem: docs/feature/concluidas/202611/20261116-endurecer-upload-de-comprovante/ (TASK-645, revisão do `security-reviewer`, achado médio fora do delta; decisão D5-A do specify: o avatar ficou de fora)
Criado em: 2026-11-16
Prioridade: MEDIA
Status: Promovido para TASK-647 (feature 20261116-endurecer-upload-de-foto-de-perfil)

## Descrição
O item 154 (feature `20261116-endurecer-upload-de-comprovante`) fechou o upload e o download do **comprovante**. A foto de perfil usa o mesmo padrão de antes e ficou como estava:

- **`UserController.php:80`** valida `'foto' => 'required|image|max:5120'`; a regra `image` do Laravel 10.48 aceita SVG.
- **`AvatarStorage::store`** (`backend/app/Support/AvatarStorage.php:26-27`) ainda usa `getClientOriginalExtension()` e só cai em `guessExtension()` se vier vazia: o cliente escolhe a extensão (`.svg`, `.html`) e a string longa de extensão também pode estourar `ex_users.photo_path`.
- **`UserPhotoController::show`** (`backend/app/Http/Controllers/UserPhotoController.php`) responde com `Storage::disk('local')->response(...)` sem `X-Content-Type-Options` nem `Content-Security-Policy`. A rota `GET /user/{userId}/photo` é por **URL assinada** (`routes/api.php:123`, `signed`), o que exige a assinatura, mas a foto é aberta por `<img>` e também por link direto.

Impacto: o mesmo do comprovante antes do item 154, com um agravante: **qualquer usuário autenticado** grava o próprio arquivo, então o conteúdo ativo (SVG com script) fica armazenado e é servido na origem da API. Hoje o token vive em outra origem (o site web), então é phishing e não roubo de token, mas a segurança depende dessa arquitetura.

Sugestão (task própria, espelha o que o item 154 fez): usar `ProofStorage::UPLOAD_RULE` ou uma regra equivalente (`mimes:jpg,jpeg,png,gif,webp`) em `UserController`; `AvatarStorage::store` com `guessExtension() ?: 'dat'`; `nosniff` e `Content-Security-Policy: default-src 'none'; sandbox` no `UserPhotoController`. Antes, conferir se o front de Perfil (`Profile.tsx:212`, `accept="image/*"`) mostra a mensagem do 422 (o `apiErrorMessage` do item 154 resolve) e se existe algum SVG já gravado em `avatares/`.

## Por que importa
É o único upload de usuário que ainda aceita SVG e escolhe a extensão pelo cliente. O conserto é pequeno, já tem receita pronta no item 154, e fecha a classe de problema em vez de deixar um caminho irmão aberto.

Tipo sugerido: backend (e uma conferência de mensagem no front)

## Resolução

Concluído em: 2026-11-17
Feature: docs/feature/concluidas/202611/20261116-endurecer-upload-de-foto-de-perfil/
Tasks: TASK-647 a TASK-652
PRs: https://github.com/isacaguiar/expense/pull/290

Nota: `uploadPhoto` passou a usar `AvatarStorage::UPLOAD_RULE` (`mimes:jpg,jpeg,png,gif,webp|max:5120`): SVG dá 422 com mensagem em português, sem gravar arquivo nem trocar a foto atual. A extensão sai de `guessExtension()` e a rota assinada `user.photo` manda `nosniff` e `Content-Security-Policy: default-src 'none'; sandbox`, o que também contém SVG já gravado. O Perfil mostra o motivo que o servidor deu. Validado por ele localmente em 2026-11-17 (SVG recusado, foto normal aparece com o CSP).
