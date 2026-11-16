# Implementation — Endurecer o upload da foto de perfil (SVG e extensão do cliente)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261116

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-647 | Concluída | 2026-11-16 | Claude | RED: `phpunit tests/Feature/UserPhotoHardeningTest.php` = 2 falhas (cabeçalhos ausentes). GREEN: o mesmo + `UserPhotoTest` (antigo, sem edição) = OK (11 testes, 35 asserções). Mutantes: sem `X-Content-Type-Options`, sem `Content-Security-Policy` e sem `sandbox` = os 3 derrubam o teste. Pint limpo. | `UserPhotoController::show` manda `nosniff` e `default-src 'none'; sandbox`, mantendo `inline`; teste novo (arquivo `UserPhotoHardeningTest`) cobre a foto enviada, um SVG com script gravado antes da feature e a rota sem assinatura (403). Banco de teste recriado (3307, `migrate:fresh`). O efeito do CSP sobre o `<img>` (esperado: nenhum) fica para a validação local dele. |
| TASK-648 | Concluída | 2026-11-16 | Claude | RED: `phpunit tests/Feature/UserPhotoHardeningTest.php` = 2 falhas (extensão do cliente de 210 caracteres dava 500; `foto.exe` gravada como `.exe`). GREEN: o mesmo + `UserPhotoTest` (antigo, sem edição) = OK (14 testes, 44 asserções). Mutante (voltar a `getClientOriginalExtension`) = 2 falhas. Relacionados (`--filter "Photo\|Avatar\|User"`) = OK (153 testes, 889 asserções). Pint limpo. | `AvatarStorage::store` usa só `guessExtension() ?: 'dat'`. Os 3 testes novos (extensão de 210 caracteres, `foto.exe`, JPEG normal) entraram em `UserPhotoHardeningTest`; o do JPEG é guarda de que o caminho comum não mudou. |
| TASK-649 | Concluída | 2026-11-16 | Claude | RED: `phpunit tests/Feature/UserPhotoHardeningTest.php` = 3 falhas (SVG e SVG renomeado `.png` aceitos, 200). GREEN: o mesmo + `UserPhotoTest` (antigo, sem edição: PDF e 5 MB seguem recusados) = OK (18 testes, 63 asserções): SVG com script e SVG renomeado dão 422 com "A foto deve ser uma imagem JPG, PNG, GIF ou WEBP.", sem gravar arquivo e sem trocar a foto que o usuário já tinha; png, gif, webp (conteúdo real) e jpg seguem aceitos. Mutante (voltar `image|max:5120`) = 3 falhas. Relacionados (`--filter "Photo|Avatar|User|Profile"`) = OK (157 testes, 908 asserções), sem editar nenhum teste antigo. Pint limpo. | `AvatarStorage::UPLOAD_RULE` e `UPLOAD_MESSAGES` (chave `foto.mimes`) usadas em `UserController::uploadPhoto`; o corpo de erro segue `{errors: ...}` com 422 (R6). O projeto não tem `lang/`, por isso a mensagem é própria. **Muda contrato (D2): um `.svg` que dava 200 passa a dar 422; gate humano antes do merge.** |
