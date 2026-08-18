# Specify — Infraestrutura de Testes no Frontend Web

> Feature: dar ao frontend web (`expense/frontend`) uma infraestrutura de testes automatizados que hoje não existe, reduzindo o risco de regressão conforme a mesma superfície de telas passa a existir também no app Expo (`expense/app`), consumindo a mesma API. Origem: item `002` de `docs/backlog/`.

Versão: 1.0 · Criado em: 20260817

---

## 1. Problema

`docs/backlog/infra-testes-frontend.md` (item 002): não existe nenhum teste automatizado no frontend hoje. A ausência de testes não bloqueia nenhuma task da migração para React Native, mas aumenta o risco de regressão conforme o mesmo conjunto de telas passa a existir em duas plataformas (web e app) consumindo a mesma API — uma mudança de contrato ou de comportamento em `expense/frontend` hoje só é percebida manualmente.

## 2. Achados confirmados

Levantados lendo o código real de `frontend/` durante a promoção desta feature.

### 2.1 Nenhuma infraestrutura de teste existe

`frontend/package.json` não tem `vitest`, `jest` nem `@testing-library/*` em nenhuma dependência (`dependencies` ou `devDependencies`), não há script de teste em `package.json` (só `dev`, `build`, `preview`), e não existe nenhum arquivo `*.test.*`/`*.spec.*` em `frontend/src`.

### 2.2 Stack atual é compatível com Vitest sem fricção

`frontend/vite.config.js` já usa Vite 7.3.1 (`package.json:25`) com `@vitejs/plugin-react`. Vitest compartilha configuração com o Vite já existente (mesmo `vite.config`, mesmo resolver de módulos/paths, sem bundler paralelo a manter) — não há CI hoje que rode testes de frontend (só `.github/workflows/deploy-backend.yml`, que é só do backend), então não há integração de CI a ajustar nesta feature.

### 2.3 Peças já existentes que servem de alvo natural para os primeiros testes

`frontend/src/components/RequireAuth.tsx` (guard de rota, `TASK-028`) e a lógica de redirect em 401 de `GroupList.tsx`/`ExpenseManager.tsx` (`TASK-029`) foram implementados nesta mesma sessão sem nenhum teste automatizado — hoje a única validação é manual, feita no browser a cada mudança. São bons primeiros alvos por serem unidades pequenas, isoladas e já terem comportamento conhecido/documentado (`docs/feature/20260817-config-url-api-frontend/`).

## 3. Fora de escopo desta feature

- Infraestrutura de teste para `expense/app` (Expo/React Native) — o projeto ainda não existe (`TASK-001` de `docs/feature/20260817-migracao-frontend-expo/` ainda pendente); o padrão equivalente (provavelmente RN Testing Library) fica para quando esse projeto for criado.
- Cobertura de testes para todas as páginas já existentes (`Dashboard`, `GroupForm`, `GroupMembersForm`, `LoginPage`) — esta feature entrega a infraestrutura funcionando e testes de exemplo para as peças já isoladas (§2.3), não cobertura completa do frontend atual.
- Integração com CI (rodar `npm test` automaticamente em push/PR) — não existe workflow de frontend hoje; adicionar um é uma decisão separada, não pedida pelo item 002.
- Testes end-to-end (Playwright/Cypress) — o item de backlog fala em testes de unidade/componente (Vitest + Testing Library), não E2E.
