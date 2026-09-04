# Implementation — CORS origin de rede local configurável

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-130 | Implementada na branch da feature (feature completa nesta branch, task única) | 2026-08-21 | IA (Claude Code) | `cd backend && ./vendor/bin/pint --test config/cors.php` — PASS; `php artisan tinker --execute="dd(config('cors.allowed_origins'));"` com `FRONTEND_NETWORK_URL` definida no `.env` → `['http://localhost:3000', 'http://192.168.0.23:3000']`; com `FRONTEND_NETWORK_URL=` (vazia, override de shell) → `['http://localhost:3000']`; `php artisan test` (suíte completa) — 99 passed (233 assertions) | `backend/config/cors.php` usa `array_filter([...])` para incluir a origem de rede só quando `FRONTEND_NETWORK_URL` está definida; `backend/.env` (gitignored) recebe a variável com o IP que antes estava hardcoded; `frontend/vite.config.js` commitado com `host: true` (já estava pronto localmente, sem mudança de código nesta task) |
