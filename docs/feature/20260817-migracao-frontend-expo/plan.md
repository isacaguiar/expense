# Plan — Migração do Frontend para React Native (Expo)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260817

---

## 1. Stack e arquitetura (decisão já travada em ADR-001)

**Stack escolhida**: Expo (managed workflow) + `react-native-web` + **Expo Router** (roteamento por arquivo) + **React Native Paper** (Material Design — mesma linguagem visual do MUI atual, minimiza redesenho).

**Local**: novo projeto em `expense/app`. `expense/frontend` **não é alterado nem desligado** durante a migração — continua em produção normalmente, consumindo a mesma API sem mudança de contrato. O corte de produção (apontar produção para `expense/app` no lugar de `expense/frontend`) é decisão futura, com gate humano explícito — ver `tasks.md` TASK-010.

Referências: `docs/sdd/decisions/ADR-001-migracao-frontend-expo.md`, `docs/sdd/00-constitution.md` §3.

### 1.1 Mapeamento de peças

| Hoje (`expense/frontend`) | Depois (`expense/app`) | Observação |
|---|---|---|
| `react-router-dom` | `expo-router` | Rotas viram arquivos em `app/` (convenção do Expo Router). |
| `@mui/material` + `@mui/icons-material` | `react-native-paper` (+ `@expo/vector-icons` para ícones) | Componente a componente: `Button`, `TextField`→`TextInput`, `AppBar`→`Appbar`, etc. |
| `axios` | `axios` | Sem mudança — funciona igual em RN. |
| `src/api.ts` (client HTTP + baseURL) | Portado quase 1:1, só ajustando storage do token (ver §2). |
| `localStorage` (implícito no fluxo web de JWT) | `expo-secure-store` (ou `AsyncStorage` se não precisar de criptografia) | Web puro não tem `localStorage` nativo em RN — precisa de abstração cross-platform. |
| `Navbar` / `InternalLayout` | Layout de navegação do Expo Router (`Tabs` ou `Drawer`) | Vira estrutura de arquivos (`app/(tabs)/_layout.tsx` etc.), não componente solto. |
| `theme.ts` (tema MUI) | Tema do `react-native-paper` (`MD3Theme`) | Portar paleta de cores/tipografia, não o objeto MUI em si. |

### 1.2 Telas a portar

Mesmo conjunto de `frontend/src/pages` (ver `01-specify.md` §3.7 e `specify.md` §1): `LoginPage` → `Dashboard` → navegação principal → `GroupList`/`GroupForm`/`GroupMembersForm` → `ExpenseManager`.

### 1.3 O que não muda

- Backend: nenhuma alteração de contrato de API é necessária só por causa da migração de frontend.
- Autenticação: mesmo fluxo JWT Bearer (`Authorization: Bearer <token>`), só muda onde/como o token fica guardado no cliente.

## 2. Débitos técnicos do `specify.md` §2 — o que vira task agora vs. backlog

Critério: um débito vira task **agora** (bloqueante) quando o trabalho de portar autenticação para RN (TASK-003/004) não tem como ser feito de forma limpa sem resolvê-lo primeiro — senão o mesmo problema (duplicação, leitura síncrona) só seria replicado em mais um lugar (o app). Os demais entram em `docs/backlog/` (diretório de ideias/débitos não agendados, compartilhado entre todas as features — ver `docs/backlog/README.md`).

### 2.1 Bloqueantes → viram task, executadas antes/junto de TASK-003

- **specify.md §2.1** (client HTTP morto/duplicado) → `tasks.md` TASK-022: consolidar `frontend/src/api.ts` como client único, com interceptor de `Authorization`, substituindo as chamadas axios/fetch diretas nas 6 páginas listadas. Sem isso, TASK-003 (portar o client para `expense/app`) portaria a duplicação em vez de um client único.
- **specify.md §2.2 + §2.3** (leitura síncrona de token em 8 lugares, sem contexto compartilhado) → `tasks.md` TASK-023: introduzir uma abstração de storage assíncrona (`getToken`/`setToken`/`clearToken`) e um `AuthContext` que a usa, substituindo a leitura direta de `localStorage`. Essa abstração é o ponto de troca real entre web (`localStorage`) e native (`expo-secure-store`) citado no mapeamento §1.1 — sem ela, TASK-004 (login no app) não tem uma base para reaproveitar.

### 2.2 Não-bloqueantes → `docs/backlog/`

- **specify.md §2.4** (infraestrutura de teste ausente) → `docs/backlog/infra-testes-frontend.md`: não bloqueia nenhuma tela específica; adicionar depois, quando houver decisão de qual runner usar em cada plataforma.
- **specify.md §2.5** (tipos duplicados) → `docs/backlog/tipos-duplicados-frontend.md`: melhora a manutenção, mas nenhuma tela trava sem isso — pode ser feito de forma incremental durante o port de cada tela.
- **specify.md §2.3** (sem redirect automático quando não autenticado) → `docs/backlog/auth-guard-redirect-frontend.md`.
- Configuração de URL da API via variável de ambiente (hoje hardcoded) → `docs/backlog/config-url-api-frontend.md`: relevante antes do corte de produção (TASK-010), não antes.

## 3. Ordem de execução

Sequencial, cada task assume a anterior concluída — mesma ordem documentada originalmente em `docs/sdd/03-tasks.md` Épico A, com as duas tasks de consolidação (§2.1) inseridas antes do restante e a divisão de telas de Grupo (§1.2/`tasks.md`) mantendo a mesma posição relativa:

1. TASK-022, TASK-023 (consolidação client HTTP + auth) — pré-requisito técnico, não dependem de Expo existir ainda.
2. TASK-001 (scaffold Expo) → TASK-002 (RN Paper + tema) → TASK-003 (portar client HTTP, agora já consolidado) → TASK-004 (login) → TASK-005 (navegação) → TASK-006 (Dashboard) → TASK-024/025/026 (telas de Grupo, uma por vez) → TASK-008 (Despesas) → TASK-009 (build web validado) → TASK-010 (corte de produção, gate humano).
