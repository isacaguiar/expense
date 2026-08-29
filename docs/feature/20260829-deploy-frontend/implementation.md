# Implementation — Deploy do frontend (GitHub Actions → FTP)

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260829

---

## 1. Desvios do fluxo padrão (se houver)

Feature só de infra (arquivos em `.github/workflows/` e `frontend/public/`). Não há `pint`/`phpunit`; o checklist de frontend (`tsc`, `vitest`, `vite build`) roda por causa da TASK-225 (`.htaccess` copiado no build). O merge em `main` que ativa os deploys é gate humano; TASK-227 tem gate adicional "antes do merge em `main`" por mudar o destino real do deploy de backend.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-225 | Concluída | 2026-08-29 | IA (feature branch) | `cd frontend && npm run build` → `dist/.htaccess` gerado idêntico ao `public/.htaccess` (Vite copia `public/` p/ a raiz do `dist/`) | `frontend/public/.htaccess` novo, bloco `mod_rewrite` do plan §2. `frontend/public/` não existia antes. Churn de `frontend/dist/` (arquivos legados no índice, `dist/` é gitignore) revertida — não entra no commit. |
