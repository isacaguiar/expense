# Specify — Migração do Frontend para React Native (Expo)

> Feature: unificar web e app mobile num só código de UI, migrando de `expense/frontend` (React web) para um novo projeto `expense/app` (Expo + React Native). Épico migrado de `docs/sdd/03-tasks.md` (Épico A, TASK-001 a TASK-010) para esta pasta, seguindo a convenção adotada a partir de 2026-08-17 (mesmo movimento já feito para o Épico B → `docs/feature/20260817-seguranca-api/`).

Versão: 1.0 · Criado em: 20260817

---

## 1. Problema

O projeto tem hoje um único frontend web (`expense/frontend`: React + Vite + MUI + `react-router-dom`). Surgiu a necessidade de um app mobile. Manter dois códigos de UI completamente separados (um web, um mobile nativo) dobraria o esforço de manter a mesma superfície de telas em duas linguagens/paradigmas distintos, sem ganho claro dado o tamanho da equipe. A decisão de stack para resolver isso já está tomada e travada em `docs/sdd/decisions/ADR-001-migracao-frontend-expo.md` e em `docs/sdd/00-constitution.md` §3. O conjunto de telas a portar é o que já existe em produção — ver `docs/sdd/01-specify.md` §3.7 (`expense/frontend/src/pages`): `LoginPage`, `Dashboard`, `GroupList`, `GroupForm`, `GroupMembersForm`, `ExpenseManager`, com navegação em `Navbar`/`InternalLayout`.

## 2. Achados confirmados

Levantados lendo o código real de `frontend/src` (não a documentação antiga) durante a criação desta feature. Os achados 2.1 a 2.5 não bloqueiam a decisão de stack (já tomada), mas afetam a ordem/qualidade da migração — ver `plan.md` para a decisão sobre cada um.

### 2.1 Client HTTP central existe, mas é código morto

`frontend/src/api.ts` define uma instância axios central (`baseURL: 'http://localhost:8080'`) que **nunca é importada em lugar nenhum**. Todas as páginas chamam `axios`/`fetch` diretamente, com a URL base hardcoded como string (`http://localhost:8000/api/...`) e o header `Authorization` remontado manualmente a cada chamada: `Dashboard.tsx:33`, `GroupList.tsx:41`, `GroupForm.tsx:37,70,72`, `GroupMembersForm.tsx:42-43,64,69`, `ExpenseManager.tsx:80,138`. `LoginPage.tsx:32-37` foge ainda mais do padrão: usa `fetch()` em vez de axios, contra uma URL diferente (`/api/login`).

### 2.2 Leitura de token síncrona e duplicada em 8 lugares

O JWT é lido de `localStorage.getItem('accessToken')` de forma síncrona, repetida de forma independente em: `Navbar.tsx:9`, `Dashboard.tsx:32`, `GroupList.tsx:39`, `GroupForm.tsx:35,58`, `GroupMembersForm.tsx:31`, `ExpenseManager.tsx:77,128`. React Native não tem `localStorage` — o equivalente (`expo-secure-store` ou `AsyncStorage`) é assíncrono. Sem abstrair essa leitura antes, cada um desses 8 pontos precisaria ser reescrito individualmente durante o port das telas, em vez de mudar num único lugar.

### 2.3 Nenhum estado de autenticação compartilhado

Não há `Context`/store de autenticação (`createContext`, Redux, Zustand — nenhum presente no projeto). Cada página reimplementa a própria checagem de token e trata falha de autenticação localmente (ex.: `GroupList.tsx:47-49` só exibe texto "Usuário não autenticado", sem redirecionar).

### 2.4 Nenhuma infraestrutura de teste

`package.json` do frontend não tem `vitest`, `jest` nem `@testing-library/*` em nenhuma dependência, e não existe nenhum arquivo de teste em `frontend/src`.

### 2.5 Tipos duplicados

O tipo `Group` (e outros como `Expense`/`User`) é redefinido de forma independente em pelo menos três arquivos (`Dashboard.tsx`, `GroupList.tsx`, `GroupForm.tsx`), sem um módulo de tipos compartilhado.

## 3. Fora de escopo desta feature

- Qualquer redesenho visual além de replicar 1:1 as telas já existentes em `expense/frontend` (o objetivo é portar, não redesenhar).
- Qualquer mudança de contrato da API — os dois frontends devem continuar consumindo a mesma API sem divergência (ver `plan.md` §2.3, regra de Compatibilidade da Constitution).
- Descontinuar ou alterar `expense/frontend` — ele permanece em produção normalmente até o corte explícito (TASK-010, gate humano).
- Login social (Google OAuth) — credenciais existem mas não há integração implementada hoje; decisão de produto em aberto, registrada em `docs/sdd/02-plan.md` §5 e §7, não faz parte desta migração.
