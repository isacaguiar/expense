# Futuras implementações — Migração do Frontend para React Native (Expo)

> Backlog de ideias/achados que não viraram task formal (`TASK-0xx`) nesta feature — não bloqueiam a migração em `tasks.md`, mas ficam registradas aqui para não se perder. Não é um dos 4 documentos padrão do SDD (`specify`/`plan`/`tasks`/`implementation`); é uma adição desta feature. Um item daqui só ganha ID `TASK-0xx` quando alguém decidir de fato executá-lo — nesse momento, promova-o para `tasks.md` (ou para um novo épico/feature, se o escopo já tiver saído do de migração de frontend) e remova-o desta lista.

Versão: 1.0 · Criado em: 20260817

---

## 1. Configuração de URL da API via variável de ambiente

Hoje a URL base da API está hardcoded como string em cada arquivo (`http://localhost:8000/api/...`, ver `specify.md` §2.1) em vez de vir de uma env var (`VITE_API_BASE_URL` no web, equivalente via `expo-constants`/`app.config.ts` no app). Não bloqueia nenhuma task de `tasks.md`, mas é necessário antes de TASK-010 (corte de produção) — sem isso, apontar `expense/app` para um backend de produção exigiria editar código-fonte em vez de configuração.

**Tipo sugerido**: frontend.

## 2. Infraestrutura de testes

Não existe nenhum teste automatizado no frontend hoje (nem `vitest`/`jest`/testing-library em `package.json`, nem arquivos de teste). Ver `specify.md` §2.4. Decisão em aberto: padronizar em Vitest + Testing Library no web, e RN Testing Library no app, ou buscar um runner único para as partes de lógica compartilhada.

**Tipo sugerido**: frontend/infra.

## 3. Extrair tipos duplicados para módulo compartilhado

`Group` (e outros tipos como `Expense`/`User`) são redefinidos de forma independente em pelo menos três arquivos (`Dashboard.tsx`, `GroupList.tsx`, `GroupForm.tsx` — ver `specify.md` §2.5). Candidato natural a um módulo `types.ts` compartilhado, e potencialmente reaproveitável entre `expense/frontend` e `expense/app` se a estrutura de pastas permitir importação cruzada (ou via um pacote `shared` num monorepo).

**Tipo sugerido**: frontend.

## 4. Auth guard / redirect automático

Hoje, páginas que exigem autenticação apenas exibem um texto de erro ("Usuário não autenticado") quando o token está ausente/inválido, sem redirecionar automaticamente para a tela de login (ver `specify.md` §2.3, ex. `GroupList.tsx:47-49`). Um guard de rota (web: wrapper de rota; app: verificação no layout do Expo Router) melhoraria a experiência em ambas as plataformas.

**Tipo sugerido**: frontend.
