# Specify — Configuração de URL da API e Auth Guard no Frontend Web

> Feature: eliminar URL da API hardcoded e falta de redirecionamento automático em telas autenticadas do frontend web, preparando o terreno para múltiplos ambientes e para a futura migração ao Expo. Origem: itens `001` e `004` de `docs/backlog/` (agrupados nesta feature por decisão do usuário — ambos tocam a mesma superfície de código: chamadas HTTP e checagem de autenticação em `frontend/src/pages`).

Versão: 1.0 · Criado em: 20260817

---

## 1. Problema

`docs/backlog/config-url-api-frontend.md` (item 001): a URL base da API está hardcoded como string em cada página do frontend em vez de vir de variável de ambiente. Isso não bloqueia nenhuma task da migração Expo, mas é necessário antes do corte de produção (`TASK-010` em `docs/feature/20260817-migracao-frontend-expo/tasks.md`) — sem isso, apontar um frontend para um backend de produção exige editar código-fonte em vez de configuração.

`docs/backlog/auth-guard-redirect-frontend.md` (item 004): páginas que exigem autenticação hoje só exibem um texto de erro quando o token está ausente/inválido, sem redirecionar para o login. Não bloqueia nenhuma task, mas melhora a experiência e evita reimplementar a checagem página a página conforme novas telas surgirem.

## 2. Achados confirmados

Levantados lendo o código real de `frontend/src` (não a documentação antiga) durante a promoção desta feature.

### 2.1 URL da API hardcoded em 8 pontos, com 2 valores diferentes e uma base nunca usada

`frontend/src/api.ts:4` define uma instância axios central com `baseURL: 'http://localhost:8080'` — mas essa instância **nunca é importada em lugar nenhum** (achado já registrado em `docs/feature/20260817-migracao-frontend-expo/specify.md` §2.1, TASK-022 daquela feature trata a consolidação em si; aqui o objetivo é só a origem do valor, não centralizar o client). Todas as páginas chamam `axios`/`fetch` diretamente contra `http://localhost:8000`, hardcoded como string literal:

- `frontend/src/pages/Dashboard.tsx:33`
- `frontend/src/pages/GroupForm.tsx:37,70,72`
- `frontend/src/pages/GroupMembersForm.tsx:42,43,64,69`
- `frontend/src/pages/ExpenseManager.tsx:80,138`
- `frontend/src/pages/GroupList.tsx:41`
- `frontend/src/pages/LoginPage.tsx:23` (comentado, ver 2.3) e `LoginPage.tsx:32` (ativo, contra `/api/login` em vez de `/auth/login`)

Não existe nenhum arquivo `.env`/`.env.example` em `frontend/`, nem leitura de `import.meta.env` em nenhum arquivo — a configuração via variável de ambiente do Vite não está montada.

### 2.2 Falta de redirecionamento automático em falha de autenticação

Duas páginas detectam erro 401 e apenas exibem texto de erro sem navegar:
- `frontend/src/pages/GroupList.tsx:47-48`
- `frontend/src/pages/ExpenseManager.tsx:91-92`

Não há `Context`/store de autenticação nem wrapper de rota que centralize essa checagem — `frontend/src/App.tsx` usa `react-router-dom` (`Routes`/`Route`) com `InternalLayout` (`App.tsx:18`) envolvendo as rotas privadas, mas sem nenhuma lógica de guard: uma rota sem token válido renderiza a página normalmente e só falha na primeira chamada de API.

### 2.3 Código morto relacionado que fica fora do escopo mas é adjacente

`LoginPage.tsx:22-30` tem um bloco inteiro comentado (fluxo de login antigo, contra `/auth/login`) convivendo com o fluxo ativo (`fetch` contra `/api/login`, `LoginPage.tsx:31-51`). Isso não é tratado nesta feature (não é sobre URL hardcoded nem sobre guard) — ver §3.

## 3. Fora de escopo desta feature

- Consolidar as chamadas `axios`/`fetch` diretas num client HTTP único (isso é `TASK-022`, já planejada em `docs/feature/20260817-migracao-frontend-expo/tasks.md`) — aqui o valor da URL passa a vir de variável de ambiente, mas cada página continua chamando `axios`/`fetch` do jeito que chama hoje.
- Abstração assíncrona de storage de token / `AuthContext` compartilhado (`TASK-023` da mesma feature) — o guard desta feature pode reusar a leitura síncrona de `localStorage` que já existe hoje; não introduz um Context novo.
- Configuração de URL via `expo-constants`/`app.config.ts` no app Expo (`expense/app`) — esse projeto ainda não existe (`TASK-001` daquela feature ainda pendente). Esta feature cobre só `expense/frontend` (web); o padrão equivalente para Expo fica para quando `TASK-001` rodar.
- Remover o bloco de código comentado em `LoginPage.tsx:22-30` ou unificar `/auth/login` vs `/api/login` (achado 2.3) — tangencial, não é sobre URL hardcoded nem guard.
- Qualquer tela de "acesso negado"/mensagem intermediária antes do redirect — o guard redireciona direto para o login.
