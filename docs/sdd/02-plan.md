# Plan — Engenharia

> Traduz o `01-specify.md` em decisões técnicas. Toda task em `03-tasks.md` deve apontar para uma seção daqui.

Versão: 1.0 · Última atualização: 2026-08-17

---

## 1. Arquitetura de alto nível

```
┌────────────────────┐      ┌────────────────────┐        ┌──────────────┐
│ expense/frontend    │      │ expense/app         │        │              │
│ React web (Vite,    │      │ Expo (RN Paper,     │        │              │
│ MUI, react-router)  │      │ react-native-web,   │        │              │
│                      │      │ Expo Router)         │        │              │
└─────────┬───────────┘      └─────────┬──────────┘        │              │
          │  HTTPS + JWT Bearer         │  HTTPS + JWT Bearer│              │
          └───────────────┬─────────────┘                    │              │
                           ▼                                  │              │
                  ┌────────────────────┐                      │   MySQL 8    │
                  │ backend/ (Laravel) │──── Eloquent ────────▶│   ex_*      │
                  │ REST API stateless │                      │   tabelas    │
                  └────────────────────┘                      └──────────────┘
```

Os dois frontends coexistem durante a migração e consomem a **mesma API** sem contrato divergente (regra de Compatibilidade da Constitution).

## 2. Frontend — migração para React Native (decisão tomada)

**Stack escolhida**: Expo (managed workflow) + `react-native-web` + **Expo Router** (roteamento por arquivo) + **React Native Paper** (Material Design — mesma linguagem visual do MUI atual, minimiza redesenho).

**Local**: novo projeto em `expense/app`. `expense/frontend` **não é alterado nem desligado** durante a migração — continua em produção normalmente. O corte (apontar produção para `expense/app`) é um gate humano (ver Constitution, Governança).

### 2.1 Mapeamento de peças

| Hoje (`expense/frontend`) | Depois (`expense/app`) | Observação |
|---|---|---|
| `react-router-dom` | `expo-router` | Rotas viram arquivos em `app/` (convenção do Expo Router). |
| `@mui/material` + `@mui/icons-material` | `react-native-paper` (+ `@expo/vector-icons` para ícones) | Componente a componente: `Button`, `TextField`→`TextInput`, `AppBar`→`Appbar`, etc. |
| `axios` | `axios` | Sem mudança — funciona igual em RN. |
| `src/api.ts` (client HTTP + baseURL) | Portado quase 1:1, só ajustando storage do token (ver abaixo). |
| `localStorage` (implícito no fluxo web de JWT) | `expo-secure-store` (ou `AsyncStorage` se não precisar de criptografia) | Web puro não tem `localStorage` nativo em RN — precisa de abstração cross-platform. |
| `Navbar` / `InternalLayout` | Layout de navegação do Expo Router (`Tabs` ou `Drawer`) | Vira estrutura de arquivos (`app/(tabs)/_layout.tsx` etc.), não componente solto. |
| `theme.ts` (tema MUI) | Tema do `react-native-paper` (`MD3Theme`) | Portar paleta de cores/tipografia, não o objeto MUI em si. |

### 2.2 Telas a portar (mesmo conjunto de `frontend/src/pages`, ver `01-specify.md` §3.7)
`LoginPage` → `Dashboard` → navegação principal → `GroupList`/`GroupForm`/`GroupMembersForm` → `ExpenseManager`. Ordem sugerida em `03-tasks.md`.

### 2.3 O que não muda
- Backend: nenhuma alteração de contrato de API é necessária só por causa da migração de frontend.
- Autenticação: mesmo fluxo JWT Bearer (`Authorization: Bearer <token>`), só muda onde o token fica guardado no cliente.

## 3. Backend

- Convenção de camadas alvo (para código novo/refatorado, não retrofit obrigatório do existente): `Route → Controller (fino) → FormRequest (validação) → Service/Action (regra de negócio) → Model`.
- Onde nasce a camada de Service primeiro: cálculo de divisão de despesa (hoje em `ExpenseController`) e apuração de saldo (hoje em `GroupExpenseReportController`), porque são as duas áreas com lógica de negócio mais densa e mais reaproveitável entre os dois relatórios existentes (`reportByGroupAndYear` e `reportByGroupAndYearMonthlySettlement` duplicam ~80% da lógica hoje).
- Débitos de implementação a resolver antes de expandir a API (senão os próximos consumidores — o app RN — herdam os mesmos bugs): métodos ausentes em `ExpenseController` (`index`/`show`/`update`/`destroy`) e em `GroupMemberController` (`destroy`), ambos registrados via rota mas inexistentes hoje (ver `01-specify.md` §3.3/3.4 e Constitution §2.4).

## 4. Banco de dados

- MySQL 8.0, tabelas `ex_*` já existentes (ver glossário em `01-specify.md` §2). Nenhuma mudança de schema é necessária para a migração de frontend.
- Política de migration: aditiva por padrão; destrutiva exige gate humano (Constitution §4.2).
- Item em aberto de produto (não técnico): decidir se `Participation` volta a ser populada (retomando o modelo do README antigo) ou se é removida/formalizada como cálculo derivado — ver `01-specify.md` §6.

## 5. Integrações

- **Pix** (`endroid/qr-code` + `App\Helpers\PixPayload`): funcional, mas o endpoint (`GET /pix/generate`) está público hoje — corrigir autenticação é prioridade de segurança (Constitution §6.5), não uma melhoria de arquitetura opcional.
- **E-mail** (convite, reset de senha): dois fluxos paralelos (ver `01-specify.md` §3.2) — candidato a unificação futura, registrado no backlog.
- **Google OAuth**: client-id/secret existem (arquivo `client_secret_*.json` na raiz do repo + valores no `README.md` raiz), mas **não há nenhuma integração de login Google implementada no código atual** (nenhuma referência a Socialite/OAuth nos controllers). Ou é um recurso planejado e não iniciado, ou é resíduo — decisão de produto pendente; enquanto isso, os valores vazados precisam ser tratados como segredo (Constitution §5).

## 6. Ambientes

- **Local**: `docker-compose.yml` sobe MySQL 8.0 (porta 3306) + Adminer; backend roda via `php artisan serve`/equivalente (não há serviço Docker para o PHP em si no compose atual); frontend via `npm run dev` (Vite, porta 5173 conforme README).
- **Produção (backend)**: GitHub Actions (`.github/workflows/deploy-backend.yml`) → build Laravel → deploy via FTP para `scd.novemax.com.br`, usando `secrets.*` do GitHub (boa prática — contrasta com os segredos vazados em texto puro no repo). **Flag**: o workflow usa `working-directory: backend-php`, mas a pasta real do projeto é `backend/` — o deploy de produção provavelmente está apontando para um caminho que não existe no repo atual. Precisa de correção humana e validação de que o último deploy realmente funcionou antes de mexer em mais nada de infra.
- **Produção (frontend)**: não há workflow de deploy para `expense/frontend` no `.github/workflows` atual — a build/deploy do front, se existe, é manual ou fora deste repositório.
- **App Expo (`expense/app`)**: ainda não existe ambiente de build/deploy (EAS) — será criado como parte do épico de migração em `03-tasks.md`, com deploy sempre como gate humano.

## 7. Decisões em aberto (produto, não técnicas)

Registradas aqui para não serem esquecidas, mas não bloqueiam a migração de frontend:
1. Retomar ou não o modelo de `Participation` com status pago/pendente (README antigo) — ver `01-specify.md` §6.
2. Unificar os dois fluxos de convite (`InvitationController::invite` vs `GroupMemberController::store`).
3. O que fazer com as credenciais Google OAuth órfãs (usar para login social de fato, ou remover).
