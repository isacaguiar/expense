# Plan — Topologia de deploy unificada (site + /app + /api)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260829

---

## 1. Backend: `server-dir` `/backend/` → `/api/` (`specify.md` §2.2)

- `.github/workflows/deploy-backend.yml`, passo "🚀 Deploy via FTP": `server-dir: /backend/` → `server-dir: /api/`. Nenhuma outra linha.
- Nada mais: o Laravel não sabe nem se importa com o nome da pasta FTP; ele só precisa que o docroot do subdomínio aponte pro `public/` dele. `APP_URL`, rotas, signed URLs, OAuth, CORS — tudo inalterado.
- Registrar no `implementation.md` a pendência de infra: docroot de `expense-api.novemax.com.br` → `/expense/api/public/`.

## 2. `deploy-site.yml` reescrito — dois destinos (`specify.md` §2.3)

O site precisa de `public/` na raiz do docroot (`/expense/www/`) e `src/` um nível acima (`/expense/src/`). A `FTP-Deploy-Action` faz um par `local-dir`→`server-dir` por invocação, então **dois passos**:

```yaml
      - name: 🚀 Deploy do site (public → /www/)
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.SFTP_HOST }}
          username: ${{ secrets.SFTP_USER }}
          password: ${{ secrets.SFTP_PASS }}
          protocol: ftp
          port: 21
          server-dir: /www/
          local-dir: site/public/
          log-level: verbose

      - name: 🚀 Deploy dos templates (src → /src/)
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.SFTP_HOST }}
          username: ${{ secrets.SFTP_USER }}
          password: ${{ secrets.SFTP_PASS }}
          protocol: ftp
          port: 21
          server-dir: /src/
          local-dir: site/src/
          log-level: verbose
```

- Cada passo mantém seu próprio `.ftp-deploy-sync-state.json` no destino (default) — `/www/.ftp-deploy-sync-state.json` e `/src/.ftp-deploy-sync-state.json` — sem colisão.
- Remover do arquivo: `local-dir: ./`, o bloco `exclude:`, o `state-name` custom, `dangerous-clean-slate` e a linha `# server-dir` comentada. Manter `on` (push main + workflow_dispatch), `runs-on`, `environment: PROD`, `checkout@v4`, "Log de sucesso".
- `name` do workflow: "Deploy para Hostgator" → "Build e Deploy Site" (alinha com os outros; "Build" figurativo).
- **Risco `/www/`**: o passo do `public` sobe pra raiz do docroot compartilhado — se `deploy-frontend` já tiver posto `app/` lá, a sync-state do site não mexe em `app/` (a action só remove o que ela mesma rastreia), então convivem. Confirmar no primeiro deploy real.

## 3. Site aponta pro app (`specify.md` §2.4)

- `site/src/config.php`: `'app_login_url' => '/app/'`. Para `app_signup_url`: **decisão — manter `'/app/'`** (aponta pra mesma tela; menos mudança que mexer no `nav.php`, e "Cadastre-se" levar pro login é aceitável até existir registro). Não remover o botão nesta feature.
- O comentário no `config.php` ("Ainda não existe domínio de produção nem tela de login/cadastro publicada — placeholders") passa a descrever só o `signup`; ajustar o texto.

## 4. App em `/app` (`specify.md` §2.5)

- `frontend/vite.config.js`: adicionar `base: '/app/'` no objeto do `defineConfig` (junto de `plugins`/`server`/`test`). Efeito: `index.html` referencia `/app/assets/...`; o dev server passa a servir em `http://localhost:3000/app/` — aceitável (ou usar `base` condicional a `mode === 'production'` se atrapalhar o fluxo local; **decisão: `base` fixo `/app/`**, mais simples, o dev só abre `/app/`).
- `frontend/src/main.tsx`: `<BrowserRouter basename="/app">`.
- `frontend/src/App.tsx` linha 2: `import { BrowserRouter as Router, Routes, Route, Navigate }` — `Router` não é usado no arquivo (só `Routes`/`Route`/`Navigate`). Trocar para `import { Routes, Route, Navigate }`. Verificar com `grep` antes de remover.
- `frontend/public/.htaccess`:
  ```apache
  <IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /app/
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /app/index.html [L]
  </IfModule>
  ```
  O Vite copia `public/` pra raiz do `dist/`, e o `dist/` inteiro sobe pra `/www/app/` — então o `.htaccess` fica em `/www/app/.htaccess`, governando só `/app/*`. O `/` do site (PHP) não é afetado.
- `.github/workflows/deploy-frontend.yml`: `server-dir: /frontend/` → `server-dir: /www/app/`. `local-dir: frontend/dist/` inalterado. `VITE_API_BASE_URL` inalterado.
- Testes de frontend: rodar a suíte; ajustar só o que quebrar por assumir rota na raiz. `basename` não deve afetar testes que usam `MemoryRouter` (a maioria) — confirmar.

## 5. Pendências manuais (`specify.md` §2.6) — nota, não task

Vão para `implementation.md` §1: docroot de `expense.novemax.com.br` → `/expense/www/`; docroot de `expense-api` → `/expense/api/public/`; limpar `/expense/frontend/` (lixo do deploy antigo). O primeiro deploy real só serve o esperado depois dessas ações no painel.

## 6. Ordem de execução e gates

Sem dependência técnica forte entre as tasks — mas a ordem lógica é: backend (§1, isolada) → site (§2, §3) → app (§4). Uma feature, um PR contra `dev` no fim.

Gates (`00-constitution.md` §5.2): reescrever workflows e abrir PR = autônomo. Merge em `dev` e depois em `main` (ativa os 3 deploys) = humano. Os docroots do painel são ação manual do dono, sem os quais o deploy roda verde mas não serve o esperado — não bloqueiam o PR, bloqueiam o "funciona em produção".
