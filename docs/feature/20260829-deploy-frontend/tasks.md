# Tasks — Deploy do frontend (GitHub Actions → FTP)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260829

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-225 | Adicionar `frontend/public/.htaccess` com fallback SPA para `index.html` | infra | plan.md §2 | nenhum | Pendente |
| TASK-226 | Criar `.github/workflows/deploy-frontend.yml` (push `main` → build → FTP `dist/` para `/frontend/`) | infra | plan.md §1 | antes do deploy (merge em `main`) | Pendente |
| TASK-227 | Corrigir `server-dir` do `deploy-backend.yml` de `/expense/backend/` para `/backend/` | infra | plan.md §3 | antes do merge em `main` (muda o destino real do deploy de backend) | Pendente |

## Critérios de aceite

- **TASK-225**: `frontend/public/.htaccess` existe com o bloco `mod_rewrite` do `plan.md` §2. `cd frontend && npm run build` gera `frontend/dist/.htaccess` idêntico (o Vite copia `public/` para a raiz do `dist/`). `npx tsc --noEmit` e `npx vitest run` seguem verdes (arquivo não afeta build de código).
- **TASK-226**: `.github/workflows/deploy-frontend.yml` existe, com `on: push: branches: [main]` + `workflow_dispatch`; job com Node 20, `npm ci` e `npm run build` em `working-directory: frontend`, `VITE_API_BASE_URL=https://expense-api.novemax.com.br` no ambiente do build; passo `SamKirkland/FTP-Deploy-Action@v4.3.5` com `local-dir: frontend/dist/`, `server-dir: /frontend/`, secrets `SFTP_HOST`/`SFTP_USER`/`SFTP_PASS`. YAML válido (`actionlint` ou parse do próprio GitHub ao abrir o PR). Não roda em `pull_request`.
- **TASK-227**: `.github/workflows/deploy-backend.yml` tem `server-dir: /backend/`; nenhuma outra linha do arquivo alterada (`git diff` mostra só essa mudança).
