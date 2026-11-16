# Implementation — Endurecer o upload de comprovante (SVG e extensão do cliente)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261116

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-641 | Concluída | 2026-11-16 | Claude | RED: `phpunit tests/Feature/ProofDownloadHeadersTest.php` = 2 falhas (cabeçalhos ausentes). GREEN: os mesmos + `ProofDownloadControllerTest` + `ProofDownloadHistoryTest` = OK (18 testes, 34 asserções). Mutantes: sem `X-Content-Type-Options`, sem `Content-Security-Policy` e sem `sandbox` = todos derrubam o teste. Pint limpo. | `ProofDownloadController::show` manda `nosniff` e `default-src 'none'; sandbox`, mantendo `inline`; teste novo cobre os 3 tipos e um SVG com script gravado antes da feature. Banco de teste recriado (3307, `migrate:fresh`). |
| TASK-642 | Concluída | 2026-11-16 | Claude | RED: `phpunit tests/Unit/ProofStorageTest.php` = 3 falhas (nome do cliente `.xxx…`, `.exe`, `.zzz` usados na extensão). GREEN: `ProofStorageTest` OK (7 testes, 17 asserções) + `ExpenseControllerPayTest --filter huge` OK. Mutante (voltar a `getClientOriginalExtension`) derruba 3 testes do unit e o de rota (500). Relacionados (`--filter "Proof\|Pay\|Settlement\|Complement\|ExpenseControllerStore\|Comprovante"`) = OK (925 testes, 4246 asserções). Pint limpo. | `ProofStorage::store` usa só `guessExtension() ?: 'dat'`. Desvio do plan: o `dat` só aparece se o PHP não tiver palpite; para conteúdo binário genérico ele devolve `bin`, então o teste aceita `bin` ou `dat` e prova o que importa (nunca a extensão do cliente). O teste de rota do B4 (extensão de 210 caracteres em `pay`, 200 e 1 arquivo) ficou em `ExpenseControllerPayTest`. `UploadedFile::fake()->image('x.jpg')` segue gravando `.jpg`. |
