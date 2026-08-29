# Plan — Deploy do frontend (GitHub Actions → FTP)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260829

---

## 1. `.github/workflows/deploy-frontend.yml` (`specify.md` §2.1)

- **Estrutura espelhada do `deploy-backend.yml`**, adaptada ao frontend. Não reusar o job do backend nem transformar em workflow único — são stacks, gatilhos de build e destinos diferentes; dois workflows curtos são mais legíveis que um com `if:` por linguagem.
- **`on`:** `push: branches: [main]` + `workflow_dispatch`. Não roda em PR (isso é o `ci-frontend.yml`).
- **Job** `runs-on: ubuntu-latest`, `environment: PROD` (mesmo do backend, para herdar os secrets do ambiente).
- **Passos:**
  1. `actions/checkout@v4`.
  2. `actions/setup-node@v4` com `node-version: '20'`, `cache: 'npm'`, `cache-dependency-path: frontend/package-lock.json` (idêntico ao `ci-frontend.yml` — mesma versão de Node que já valida a suíte).
  3. `npm ci` em `working-directory: frontend`.
  4. `npm run build` em `working-directory: frontend`, com `env: VITE_API_BASE_URL: https://expense-api.novemax.com.br`. `frontend/src/config.ts` lê `import.meta.env.VITE_API_BASE_URL` em build time; sem a env o fallback seria `http://localhost:8000`.
  5. `SamKirkland/FTP-Deploy-Action@v4.3.5` com `server: ${{ secrets.SFTP_HOST }}`, `username: ${{ secrets.SFTP_USER }}`, `password: ${{ secrets.SFTP_PASS }}`, `protocol: ftp`, `port: 21`, `local-dir: frontend/dist/`, `server-dir: /frontend/`, `log-level: verbose`.
- **`local-dir` com barra final** — a FTP-Deploy-Action exige `local-dir`/`server-dir` terminando em `/`.
- **Sem `dangerous-clean-slate`** — a action mantém um `.ftp-deploy-sync-state.json` no destino e envia só o diff; um deploy que renomeia um chunk com hash novo (padrão do Vite) remove o antigo pelo state. Aceitável para começar; se sobrar lixo de builds antigos, limpeza manual pontual.

## 2. `frontend/public/.htaccess` (`specify.md` §2.2)

- Conteúdo:
  ```apache
  <IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.html [L]
  </IfModule>
  ```
- `frontend/public/` não existe hoje — criar o diretório só com esse arquivo. O Vite copia `public/*` para a raiz do `dist/` no `build` sem configuração (comportamento padrão), então o `.htaccess` chega ao `server-dir: /frontend/` no deploy.
- `RewriteBase /` assume que o app é servido na raiz de `expense.novemax.com.br` (sem subpath) — condiz com não haver `base` no `vite.config.js`.

## 3. `.github/workflows/deploy-backend.yml` (`specify.md` §2.3)

- Uma linha: `server-dir: /expense/backend/` → `server-dir: /backend/`.
- **Impacto:** o próximo deploy de backend passa a escrever em `/expense/backend/` (correto), não mais em `/expense/expense/backend/`. Se a pasta duplicada existir no host com uma cópia servida, pode ser preciso ajuste manual no servidor — fora do escopo do workflow (`specify.md` §3). Por isso a task carrega gate humano "antes do merge em `main`".

## 4. Ordem de execução e gates

Sem dependência técnica entre as três tasks. Ordem sugerida em `tasks.md`: `.htaccess` → `deploy-frontend.yml` → fix do `deploy-backend.yml`.

Gates (tabela de `00-constitution.md` §5.2 — deploy é gate humano):
- Criar os workflows e abrir o PR: autônomo.
- O merge em `dev` e depois em `main` (que ativa os dois deploys) é gate humano — como qualquer merge em `main`.
- TASK-227 (muda o destino real do deploy de backend em produção) recebe gate explícito "antes do merge em `main`": o dono do projeto confirma que o host está pronto para o novo caminho antes de promover.
