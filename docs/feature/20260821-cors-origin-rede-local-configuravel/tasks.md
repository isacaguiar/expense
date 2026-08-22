# Tasks — CORS origin de rede local configurável

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs seguem a numeração global do projeto (maior existente antes desta feature: TASK-129).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-130 | Tornar a origem CORS de rede local configurável via `FRONTEND_NETWORK_URL` e commitar `host: true` no Vite | infra | plan.md §1, §2 | antes do merge | Implementada na branch da feature |

## Critérios de aceite

- **TASK-130**: `backend/config/cors.php` não tem mais IP hardcoded (`git diff`/leitura do arquivo). Com `FRONTEND_NETWORK_URL` indefinida no `.env`, `allowed_origins` resolve para `['http://localhost:3000']` (comportamento igual ao pré-hardcode). Com `FRONTEND_NETWORK_URL=http://192.168.0.23:3000` no `.env`, `allowed_origins` resolve para `['http://localhost:3000', 'http://192.168.0.23:3000']` — verificável via `php artisan tinker --execute="dd(config('cors.allowed_origins'))"` nos dois cenários. Suíte de testes do backend continua verde (`php artisan test`). `frontend/vite.config.js` com `host: true` commitado.
