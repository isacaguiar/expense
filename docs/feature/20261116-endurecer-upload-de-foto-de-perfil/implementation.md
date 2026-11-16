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
