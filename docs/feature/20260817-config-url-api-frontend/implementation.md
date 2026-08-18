# Implementation — Configuração de URL da API e Auth Guard no Frontend Web

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260817

---

## 1. Desvios do fluxo padrão (se houver)

`frontend/node_modules` está versionado no repositório (achado durante a TASK-027, ao rodar `npm install`) — `frontend/.gitignore` já lista `node_modules/`, mas os arquivos já estavam commitados antes dessa regra existir, então continuam rastreados. Alterações de build (`.vite/deps/*`, `package-lock.json`) geradas por `npm install`/`npm run dev` nesta task foram revertidas com `git restore` antes do commit, para não misturar ruído de build com o escopo da task. Corrigir isso (remover `node_modules` do controle de versão) não bloqueia nenhuma task desta feature — candidato a item de `docs/backlog/`.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-027 | Mergeado (PR #4) | 2026-08-17 | Claude (IA) | `npm install` (instala `typescript`/`vite`, ausentes em `node_modules`) — ok. `npx tsc --noEmit` — sem erros. `grep -rn "http://localhost:8000\|http://localhost:8080" frontend/src` — só `config.ts` (fallback esperado) e `LoginPage.tsx:24` (bloco comentado, fora de escopo). Validado no browser (`npm run dev`, porta 3000): login preenchido e enviado com `VITE_API_BASE_URL=http://localhost:8000` gera `POST http://localhost:8000/api/login`; alterando `.env` para `http://localhost:9999` e reiniciando o dev server, a mesma ação gera `POST http://localhost:9999/api/login` — confirma que a URL vem da variável de ambiente, não de string hardcoded. | — |
| TASK-028 | PR aberto | 2026-08-17 | Claude (IA) | `npx tsc --noEmit` — sem erros. Validado no browser (`npm run dev`, porta 3000): navegar para `/dashboard` sem `accessToken` em `localStorage` renderiza a tela de Login (`location.href` volta pra `/`); com `localStorage.setItem('accessToken', 'fake-token-for-guard-test')`, `/dashboard` renderiza "Meus Grupos" normalmente. | Branch/PR desta task ainda segue o fluxo antigo (direto pra `main`) — feature iniciada antes da convenção `branch → dev → main` (`00-constitution.md` §5.1). |
