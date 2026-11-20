# Plan — Endurecer o upload da foto de perfil (SVG e extensão do cliente)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261116

Decisões do specify aprovadas por ele em 2026-11-16: D1-A, D2-A, D3-A, D4-A, D5-A, D6-A. Espelha o plan da feature `docs/feature/concluidas/202611/20261116-endurecer-upload-de-comprovante/` (item 154), com o avatar no lugar do comprovante.

---

## 1. Cabeçalhos na foto servida (specify §2.3, D4-A)

**Arquivo:** `backend/app/Http/Controllers/UserPhotoController.php`, método `show`.

Trocar o `return Storage::disk('local')->response(...)` por uma variável `$response` e, antes de devolvê-la, `$response->headers->set('X-Content-Type-Options', 'nosniff')` e `$response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox")`, mantendo `inline` (é o que o `Storage::response` já faz). Só nesta rota (R4).

**Por que primeiro e por que assim:** aditivo, sem mudar contrato, e protege também as fotos já gravadas (R5). `Content-Security-Policy` numa resposta carregada por `<img>` não afeta a imagem (o CSP só se aplica ao documento aberto direto), então a foto no Perfil e nos avatares segue aparecendo; a confirmação é feita na validação local (abrir o Perfil com uma foto enviada). `attachment` (D4-B) descartado: quebraria o link direto sem necessidade.

**Testes** (arquivo novo `backend/tests/Feature/UserPhotoHardeningTest.php`, sem editar o `UserPhotoTest`): (a) a rota assinada responde com `nosniff`, `default-src 'none'; sandbox` e `Content-Disposition` com `inline`; (b) um SVG com `<script>` gravado direto em `avatares/<id>/x.svg` (foto antiga) e apontado por `photo_path` sai com os mesmos cabeçalhos e o mesmo corpo; (c) a rota sem assinatura segue 403 (já coberto pelo teste antigo, repetido só como guarda de que a mudança não abriu a rota). Mutantes: tirar cada cabeçalho e tirar o `sandbox`.

## 2. Extensão pelo conteúdo (specify §2.2, D3-A)

**Arquivo:** `backend/app/Support/AvatarStorage.php`, método `store`.

`$extension = $file->guessExtension() ?: 'dat';` (nunca `getClientOriginalExtension()`), com o mesmo comentário curto do `ProofStorage`. O comportamento antigo de `dat` como reserva vale; para conteúdo binário genérico o PHP devolve `bin` e o teste do 154 já aceitou `bin|dat`.

**Testes** (no arquivo novo do §1 ou em `tests/Unit/AvatarStorageTest.php` se não existir): PNG real com nome de cliente de extensão de 210 caracteres grava `avatares/<id>/<uuid>.png` e o upload por `POST /api/user/photo` responde 200 (antes dava 500 pela coluna); JPEG chamado `foto.exe` grava `.jpg`; `UploadedFile::fake()->image('a.jpg')` segue `.jpg` (os testes antigos do `UserPhotoTest` seguem verdes). Mutante: voltar a `getClientOriginalExtension`.

## 3. Regra e mensagem próprias do avatar (specify §2.1, D1-A, D2-A)

**Arquivos:** `backend/app/Support/AvatarStorage.php` (constantes) e `backend/app/Http/Controllers/UserController.php:80`.

Em `AvatarStorage`: `public const UPLOAD_RULE = 'mimes:jpg,jpeg,png,gif,webp|max:5120';` e `public const UPLOAD_MESSAGES = ['foto.mimes' => 'A foto deve ser uma imagem JPG, PNG, GIF ou WEBP.'];`. Em `uploadPhoto`: `'foto' => 'required|'.AvatarStorage::UPLOAD_RULE` e `Validator::make($request->all(), $rules, AvatarStorage::UPLOAD_MESSAGES)`. O 422 continua em `{errors: ...}` (R6). O `max:5120` em KB é o mesmo de hoje (R2).

**Testes** (no arquivo novo): SVG com `<script>` e SVG renomeado `.png` dão 422 com a mensagem em português e **não gravam arquivo** (`Storage::disk('local')->allFiles()` vazio, `photo_path` do usuário intacto); jpg, png, gif e webp aceitos (o `image()` do fake gera conforme a extensão; webp depende do GD, então o teste de webp usa conteúdo real e não o fake); o teste antigo `test_rejects_a_non_image_file` (PDF) e o de 5 MB seguem verdes. Mutante: voltar `image|max:5120`. **Esta task só depois das §1 e §2**, pelo mesmo motivo do 154: a contenção do download e a extensão protegem mesmo se a regra falhar.

## 4. O Perfil mostra o motivo do servidor (specify §2.4, D5-A, D6-A)

**Arquivo:** `frontend/src/pages/Profile.tsx`, `handlePhotoSelected` (o `catch` hoje ignora o erro e mostra sempre "Não foi possível enviar a foto. Tente novamente.").

Usar `apiErrorMessage(err, 'Não foi possível enviar a foto. Tente novamente.')` de `frontend/src/utils/expenseRequest.ts` (já existente desde o item 154: lê `data.error`, senão a primeira mensagem de `errors`, senão o fallback; o `uploadPhoto` devolve `{errors: {foto: [...]}}`, que o helper cobre). O `accept="image/*"` não muda (D6-A: conveniência, iPhone). Sem pré-checagem no cliente nesta feature (o aviso de `proofFileProblem` é do comprovante, com texto "comprovante").

**Testes** (`frontend/src/pages/Profile.test.tsx`, só acrescentar): um 422 com `errors.foto` mostra a mensagem do servidor; um erro sem corpo segue com o texto padrão (já existe; confirmar que passa). Mutante: voltar ao texto fixo. `tsc --noEmit` e `vitest` do arquivo.

## 5. Backlog e documentação

- **Item 156:** `Resolução` e mover para `concluidos/` no fechamento (skill `promover-backlog`, passo 6).
- **Item 157:** a conferência dele (link `public/storage` inexistente em produção) já está registrada e a prioridade caiu para BAIXA; entra como commit de docs separado neste mesmo PR.
- `docs/sdd/01-specify.md`: o bloco "Formatos do comprovante e cabeçalhos do download" cita o item 156 como fora; atualizar para dizer que a foto de perfil agora segue a mesma regra (jpg, jpeg, png, gif, webp pelo conteúdo, 422 em português, extensão pelo conteúdo, cabeçalhos na rota da foto) e subir a versão do documento.

## 6. Revisão de segurança e fechamento

`security-reviewer` só no delta (`UserPhotoController`, `AvatarStorage`, `UserController::uploadPhoto`), suíte cheia do backend (1 worker, sem outra sessão no banco), Pint, e do front só `tsc` e `vitest related`. Depois: `pr-readiness-checker`, **validação local dele** (stack isolada: subir um SVG no Perfil e ver a mensagem; subir uma foto normal e ver o avatar aparecer, o que confirma que o CSP não atrapalha o `<img>`), `Resolução` no 156 e **um PR único contra `dev`**.

## 7. Riscos

- **Contrato:** um `.svg` que dava 200 passa a dar 422 (D2, decisão dele). Ninguém envia SVG de propósito; o seletor é `image/*` de foto.
- **CSP e `<img>`:** a expectativa é que o CSP não afete a imagem; o teste de ponta a ponta fica na validação local, com a foto enviada aparecendo no Perfil.
- **GD sem webp:** o teste de webp usa conteúdo real, não o fake.
- Sem migration, sem coluna nova: deploy comum do backend e do frontend.
