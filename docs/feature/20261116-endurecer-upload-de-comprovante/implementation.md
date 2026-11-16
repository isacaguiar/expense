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
