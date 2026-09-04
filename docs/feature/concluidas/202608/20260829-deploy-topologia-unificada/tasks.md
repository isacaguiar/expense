# Tasks — Topologia de deploy unificada (site + /app + /api)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260829

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-228 | `deploy-backend.yml`: `server-dir` `/backend/` → `/api/` | infra | plan.md §1 | antes do deploy (merge em `main`) | Pendente |
| TASK-229 | Reescrever `deploy-site.yml` (dois passos FTP: `site/public/` → `/www/`, `site/src/` → `/src/`; sem `local-dir: ./`/`exclude`) | infra | plan.md §2 | antes do deploy (merge em `main`) | Pendente |
| TASK-230 | Apontar o site para o app: `site/src/config.php` `app_login_url`/`app_signup_url` → `/app/` | frontend | plan.md §3 | nenhum | Pendente |
| TASK-231 | Servir o app React em `/app`: `vite.config.js` `base`, `main.tsx` `basename`, import morto em `App.tsx`, `.htaccess` escopado, `deploy-frontend.yml` `server-dir` → `/www/app/` | frontend | plan.md §4 | antes do deploy (merge em `main`) | Pendente |

## Critérios de aceite

- **TASK-228**: `.github/workflows/deploy-backend.yml` tem `server-dir: /api/`; `git diff` mostra só essa linha. `implementation.md` §1 registra a pendência: docroot de `expense-api.novemax.com.br` → `/expense/api/public/`.
- **TASK-229**: `.github/workflows/deploy-site.yml` tem dois passos `SamKirkland/FTP-Deploy-Action@v4.3.5` — um com `local-dir: site/public/` / `server-dir: /www/`, outro com `local-dir: site/src/` / `server-dir: /src/`, ambos com secrets `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS`. Sem `local-dir: ./`, sem bloco `exclude:`, sem `# server-dir` comentado, sem `setup-node`/`npm`. `on: push main` + `workflow_dispatch`. YAML válido (parse).
- **TASK-230**: `site/src/config.php` tem `'app_login_url' => '/app/'` e `'app_signup_url' => '/app/'`; comentário do bloco ajustado. `php -l site/src/config.php` sem erro (ou parse equivalente).
- **TASK-231**: `frontend/vite.config.js` tem `base: '/app/'`. `frontend/src/main.tsx` tem `<BrowserRouter basename="/app">`. `frontend/src/App.tsx` não importa mais `BrowserRouter`/`Router` sem uso. `frontend/public/.htaccess` usa `RewriteBase /app/` e reescreve para `/app/index.html`. `.github/workflows/deploy-frontend.yml` tem `server-dir: /www/app/`. `cd frontend && npx tsc --noEmit` limpo; `npx vitest run` verde (ajustes de teste incluídos se algum assumir rota na raiz); `npm run build` gera `dist/index.html` referenciando `/app/assets/...`.
