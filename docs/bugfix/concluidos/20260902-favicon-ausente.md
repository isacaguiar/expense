# Bugfix — Favicon ausente no site e no app (`/favicon.ico` 404, `index.html` sem `<link rel=icon>`)

Versão: 1.0 · Criado em: 20260902 · Branch: `fix/20260902-favicon-ausente`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível**
- [ ] **Migration ou contrato de API**
- [ ] **Causa raiz obscura / correção ampla**
- [ ] **Decisão de produto/arquitetura**

Nenhuma marcada → segue no BFF. Nota: a correção toca dois módulos (`frontend/` e `site/`), mas é a mesma mudança estática trivial em cada um (soltar `favicon.ico` no `public/` + uma tag `<link>`), sem lógica, sem contrato de API, sem migration. Causa raiz clara e verificada. Fica a critério da revisão do PR (gate humano) se prefere quebrar em `/nova-feature`.

## 1. Problema

- **Sintoma:** Nem o site institucional (`expense.novemax.com.br/`) nem o app (`/app/`) exibem ícone na aba / favoritos. O console mostra `GET /favicon.ico` → 404.
- **Reprodução:**
  1. App: `cd frontend && npm run dev`, abrir `http://localhost:3000/app/` → `document.querySelectorAll('link[rel*="icon"]')` retorna lista vazia; a aba fica com o ícone padrão do navegador.
  2. Site: iniciar o site (`php -S 127.0.0.1:8080 -t public` a partir de `site/`, opção "Site" do `iniciar-projeto.bat`), abrir `http://localhost:8080/` → `GET /favicon.ico` → **404** (verificado em 2026-09-02).
- **Esperado vs. atual:** Esperado: a aba mostra o ícone do projeto nos dois. Atual: ícone genérico; `/favicon.ico` 404.
- **Causa raiz:**
  - **App** — `frontend/index.html` (`<head>`) não declarava nenhum `<link rel="icon">`. O `favicon.ico` existia em `frontend/favicon.ico` (raiz do projeto), **fora** de `frontend/public/`, então o Vite nunca o copiava para o build (confirmado: `dist/` só tinha `.htaccess` + `assets/` + `index.html`). App é servido sob `/app/` (`vite.config.js:18` `base: '/app/'`), então o fallback do navegador para `/favicon.ico` na raiz do domínio também não resolveria para o app.
  - **Site** — `site/src/templates/header.php:28` só emitia `<link rel="icon" href="assets/favicon.png">` (via `asset()` em `site/src/helpers.php:12-15`, caminho relativo). Não havia `favicon.ico` em `site/public/`, então o `GET /favicon.ico` que navegadores, bookmarks e bots de link-preview fazem por convenção — independentemente da tag `<link>` — dava 404.

## 2. Correção

- **O que muda e por quê:**
  - **App:** `favicon.ico` movido de `frontend/favicon.ico` para `frontend/public/favicon.ico` (o Vite copia `public/` para a raiz de `dist/`). Adicionado `<link rel="icon" href="/favicon.ico" sizes="any" />` em `frontend/index.html` — o Vite reescreve o caminho root-absoluto para `/app/favicon.ico` no dev e no build (mesmo mecanismo que já reescreve o `src` do `<script>`). Resultado no build: `dist/favicon.ico` + `dist/index.html` com `href="/app/favicon.ico"`.
  - **Site:** novo `site/public/favicon.ico` (o mesmo ícone multi-resolução 16/32/48 — `e9f7f4a4…`). `header.php` passa a emitir `<link rel="icon" href="/favicon.ico" sizes="any" />` **antes** do `<link>` PNG existente. Caminho absoluto `/favicon.ico` porque o site é servido na raiz do domínio (`/www/`, ver `docs/feature/20260829-deploy-topologia-unificada/`). O `<link>` do `favicon.png` fica como estava.
- **Arquivos tocados:**
  - `frontend/index.html` — adiciona `<link rel="icon">`.
  - `frontend/favicon.ico` → `frontend/public/favicon.ico` — `git mv`.
  - `site/src/templates/header.php` — adiciona `<link rel="icon">` do `.ico`.
  - `site/public/favicon.ico` — arquivo novo.
- **Teste de regressão:** sem teste automatizado — bug puramente estático (asset + tag `<link>`), sem lógica de negócio; não há suíte que exercite `index.html` / `header.php`. Verificação manual registrada na §3 (`/favicon.ico` do site e `/app/favicon.ico` do app respondem 200 com ICO válido; `<link rel=icon>` presente no DOM). `npx tsc --noEmit` e `vite build` verdes.
- **Riscos / efeitos colaterais:**
  - `deploy-frontend.yml` (`frontend/dist/` → `/www/app/`) e `deploy-site.yml` (`site/public/` → `/www/`) já publicam os diretórios inteiros — o `favicon.ico` novo entra nos dois deploys sem mexer em workflow.
  - Em produção, `expense.novemax.com.br/favicon.ico` passa a ser servido pelo `.ico` do site; o app em `/app/` tem o seu próprio via `<link>`. Mesma imagem nos dois.
  - `favicon.png` do site (591×546, 147 KB — grande para um favicon) segue referenciado como está; encolher para um favicon de verdade é melhoria não-bloqueante (candidata a `docs/backlog/`), fora do escopo.
  - Há um `favicon.ico` solto e **não versionado** na raiz do repo (`?? favicon.ico`) — pré-existente, não tocado por este fix.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-02 | `cd frontend && npx tsc --noEmit` | sem erros (exit 0) |
| 2026-09-02 | `cd frontend && npm run build` | build OK (exit 0); `dist/favicon.ico` gerado; `dist/index.html` com `<link rel="icon" href="/app/favicon.ico" sizes="any" />` |
| 2026-09-02 | Browser — `GET http://localhost:3000/app/favicon.ico` (Vite dev) | 200, `image/x-icon`, 107424 bytes, header ICO `00 00 01 00`; `<link rel=icon>` presente no DOM apontando p/ `/app/favicon.ico` |
| 2026-09-02 | Browser — `GET http://localhost:8080/favicon.ico` (site, `php -S`) | 200, `image/vnd.microsoft.icon`, 107424 bytes (antes: **404**) |
| 2026-09-02 | Browser — `header.php` em `/` e `/privacidade.php` | ambas com `<link rel="icon" href="/favicon.ico" sizes="any" />` + `<link rel="icon" type="image/png" ...>` |

## Resolução
Concluído em: 2026-09-02
Branch: fix/20260902-favicon-ausente
PR: https://github.com/isacaguiar/expense/pull/137
