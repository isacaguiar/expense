# Constitution — Controle de Despesas Compartilhadas

> Este documento define as regras que **todo** trabalho no projeto (humano ou IA) deve seguir. Ele é o topo da hierarquia do SDD: Specify, Plan, Tasks e Implementation não podem contradizê-lo. Mudar a Constitution é sempre um **gate humano** (ver bloco Governança).

Versão: 1.0 · Última atualização: 2026-08-17

---

## 1. Arquitetura

1. O sistema é dividido em **frontend(s) desacoplado(s)** consumindo um **backend REST stateless** (Laravel) via **JWT Bearer** (`tymon/jwt-auth`). Nenhuma lógica de negócio deve viver no frontend além de validação de UX.
2. A **fonte da verdade do domínio é o código** — migrations (`backend/database/migrations`) e Models (`backend/app/Models`) —, não os `README.md` históricos. Onde houver divergência, o código vence e o README deve ser corrigido ou removido (ver `01-specify.md`, seção "Divergências").
3. Controllers devem ser **finos**: validação (idealmente via `FormRequest`, hoje ainda inline em `$request->validate()`) + orquestração. Regra de negócio não trivial (ex.: cálculo de divisão de despesa, apuração de saldo entre membros) deve migrar para uma camada de `Services`/`Actions` — hoje essa lógica está espalhada dentro de `ExpenseController` e `GroupExpenseReportController`.
4. Toda nova tabela usa o prefixo `ex_` (convenção já em uso: `ex_groups`, `ex_expenses`, `ex_quotas`, `ex_participations`, `ex_groups_members`, `ex_expenses_payers`, `ex_users`).
5. Exclusão de registros de negócio (grupo, despesa) é **soft delete** via coluna `deleted` (já implementado em `Group`/`Expense`) — nunca `DELETE` físico sem gate humano explícito (ver Governança).
6. Toda rota autenticada define claramente **quem pode acessar o quê**: hoje isso não é verdade em todos os endpoints (ver Segurança, item 5) — é dívida a resolver, não padrão a repetir em código novo.

## 2. Qualidade

1. Backend: `laravel/pint` (PSR-12) deve rodar limpo antes de qualquer commit. Está no `composer.json`, mas não há hook/CI que o obrigue hoje — enquanto isso não existir, é responsabilidade manual do autor da task.
2. Toda regra de negócio nova (cálculo, autorização, fluxo financeiro) exige teste PHPUnit (`backend/tests`). Não escrever teste é aceitável só com justificativa explícita na task.
3. Frontend(s): TypeScript em modo estrito, sem `any` não justificado.
4. Nenhuma task é considerada "pronta" com endpoint quebrado. Hoje existem rotas registradas via `Route::apiResource` cujo método correspondente **não existe** no controller (`GET/PUT/PATCH/DELETE /expenses/{id}`, `DELETE /groups/{groupId}/members/{userId}}`) — chamadas a esses endpoints hoje resultam em erro fatal. Isso é dívida herdada, registrada no backlog (`03-tasks.md`), e não deve se repetir: ao registrar uma rota (`apiResource` ou manual), todos os métodos referenciados devem existir e ter teste mínimo.
5. Não deixar controllers "stub" (`ParticipationController`, `QuotaController` hoje só têm métodos vazios) registrados em rotas ativas. Scaffold sem implementação fica fora de `routes/api.php` até ter conteúdo.

## 3. Stack

Trava as versões/peças abaixo. Trocar qualquer uma é decisão de **Governança**, nunca decisão unilateral (humana ou IA) dentro de uma task comum.

| Camada | Tecnologia atual |
|---|---|
| Backend | PHP 8.1+, Laravel 10, `tymon/jwt-auth` (auth), Laravel Sanctum, `endroid/qr-code` (Pix) |
| Banco | MySQL 8.0 |
| Frontend web | React 18 + TypeScript + Vite + MUI (`@mui/material`) + `react-router-dom` + `axios` |
| Frontend mobile/web unificado *(em migração)* | Expo + `react-native-web` + Expo Router + `react-native-paper` — ver `02-plan.md` |
| Infra local | Docker Compose (MySQL + Adminer) |
| Deploy backend | GitHub Actions → FTP (`scd.novemax.com.br`) |

> Decisão já tomada e registrada em `02-plan.md`/`03-tasks.md`: migração do frontend para **Expo + React Native Paper**, em projeto novo (`expense/app`), com `expense/frontend` (React web atual) continuando em paralelo até o corte.

## 4. Compatibilidade

1. A API hoje é **sem versionamento** (`/api/*`). Mudança breaking em contrato de resposta/rota exige uma de duas coisas: (a) campo/rota nova aditiva, ou (b) introdução de `/api/v2` — nunca alterar o contrato de um endpoint existente em produção sem depreciação assistida.
2. Migrations são **aditivas por padrão** (nova coluna nullable ou com default, nova tabela). Migration destrutiva (`drop`, `rename`, alterar tipo de coluna existente) é ação com **gate humano** (Governança) antes de rodar em qualquer banco compartilhado.
3. Quando o app Expo (`expense/app`) e o frontend web (`expense/frontend`) coexistirem, ambos consomem a **mesma API** — nenhuma mudança de contrato pode quebrar um dos dois sem o outro ser atualizado junto.
4. Navegadores: baseline "evergreen" (últimas 2 versões de Chrome/Edge/Firefox/Safari) — sem suporte a IE ou navegadores EOL.

## 5. Governança

1. Todo trabalho de negócio segue as 5 fases do SDD **nesta ordem**: `Constitution → Specify → Plan → Tasks → Implementation`. Uma Task só é aceita se rastreável até um item do Plan, e o Plan até o Specify. Decisões de stack/arquitetura (ex.: item da tabela abaixo ou mudança do que está travado em §3) ficam registradas com contexto e alternativas descartadas em `docs/sdd/decisions/` (formato ADR) — esta Constitution registra a regra vigente, `decisions/` registra o porquê.

1.1. **Fluxo de branch/PR** (a partir de 2026-08-17, primeira feature a segui-lo por completo é a que vier depois de `docs/feature/20260817-config-url-api-frontend/` — essa continua no fluxo antigo, branch/PR por task direto pra `main`, pra não trocar o processo no meio da execução): `main` é a branch de produção (dispara `deploy-backend.yml` a cada push) — equivalente ao "master" de um Gitflow clássico, sem renomear a branch. `dev` é a branch de integração, criada a partir de `main`. Toda branch de task nasce de `dev` atualizada, nomeada `<tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx` (nomenclatura da pasta da feature em `docs/feature/`, com o ID da task no final) — ver `04-implementation.md` §1 para o passo a passo completo, incluindo quando abrir o PR de promoção `dev` → `main`.
2. **Gates human-in-the-loop** — o que pode ser feito de forma autônoma vs. o que exige aprovação humana explícita antes de executar:

| Ação | Autônomo (IA/dev em branch) | Exige aprovação humana |
|---|---|---|
| Redigir/atualizar Specify, Plan, Tasks | ✅ | — |
| Editar **esta Constitution** | ❌ | ✅ sempre |
| Escrever código, testes, migrations em branch de feature | ✅ | — |
| Rodar migration em banco **local/dev** | ✅ | — |
| Rodar migration em banco **compartilhado/produção** | ❌ | ✅ |
| Migration **destrutiva** (drop/rename/alterar tipo) em qualquer ambiente além do local | ❌ | ✅ |
| Abrir Pull Request (branch de task → `dev`) | ✅ | — |
| Merge de PR em `dev` | ❌ | ✅ (revisão humana do PR) |
| Abrir PR de promoção (`dev` → `main`), após validação/teste em `dev` | ✅ | — |
| Merge em `main` (produção) | ❌ | ✅ (revisão humana do PR de promoção) |
| Deploy (workflow `deploy-backend.yml`, EAS build do Expo) | ❌ | ✅ — na prática, o próprio merge em `main` já dispara `deploy-backend.yml`; não fazer merge em `main` sem essa consequência estar clara |
| Rotacionar, expor ou remover segredo/credencial | ❌ | ✅ |
| Apagar dado definitivamente (hard delete) | ❌ | ✅ |
| Corte de produção do frontend novo (`expense/app`) substituindo `expense/frontend` | ❌ | ✅ |

3. Achados que **já exigem decisão humana** (não corrigir silenciosamente, registrar e esperar aprovação — ver `03-tasks.md`):
   - Segredos versionados em texto puro (`README.md` raiz: senha do jasypt; client-id/secret do Google OAuth; arquivo `client_secret_*.json` na raiz do repositório) → precisa rotacionar credenciais e remover do histórico/arquivo.
   - `.github/workflows/deploy-backend.yml` referencia `working-directory: backend-php`, mas a pasta real é `backend/` → deploy de produção provavelmente aponta para caminho errado.
   - `GET /pix/generate` está **fora** do grupo de middleware `jwt.auth` em `routes/api.php` → qualquer pessoa não autenticada pode gerar o QR Code Pix de um usuário informando só o e-mail dele (ver Segurança, item 5).
   - `GroupController@show/update/destroy` não verificam se o usuário autenticado é membro do grupo → qualquer usuário logado pode ver/editar/"deletar" (soft delete) grupo de outra pessoa pelo ID.

## 6. Segurança

1. Nunca commitar segredo em texto puro (senha, client secret, API key, token). **Violação existente conhecida**: ver Governança item 3 — tratar como dívida prioritária, não como padrão aceitável para código novo.
2. Senha de usuário sempre hasheada (`User::$casts['password'] = 'hashed'` — já correto). Nunca logar senha em texto puro (hoje `AuthController::login` loga `$credentials` inteiro via `Log::debug`, incluindo a senha em claro no log — dívida a corrigir).
3. Tokens de convite/reset de senha (`InvitationController`) hoje trafegam como **query string** na URL (`?token=...`) — evitar em código novo: preferir corpo de requisição (POST) ou, se precisar ir por link de e-mail, tratar como token de uso único e curto prazo (o `forgotPassword` já expira em 60 min, o que é correto; o padrão deve se estender a todo fluxo de convite).
4. JWT deve ter expiração configurada (`JWT_TTL`) e o app cliente deve tratar expiração/refresh — não assumir token eterno.
5. Toda rota que expõe dado financeiro ou pessoal deve estar dentro do grupo `jwt.auth`, e o controller deve checar que o usuário autenticado tem relação com o recurso (é membro do grupo, é o dono do dado) antes de retornar/alterar. **Duas violações confirmadas hoje** (registradas como tasks de segurança prioritárias em `03-tasks.md`):
   - `GET /pix/generate` sem autenticação.
   - `GroupController@show/update/destroy` sem checagem de membership (IDOR).
6. Qualquer correção dos itens 1, 3 (violação existente) e 5 é código de segurança: pode ser **desenvolvido** de forma autônoma em branch, mas o **merge/deploy** segue o gate humano da tabela de Governança — e a rotação de credenciais (item 1) é sempre 100% humana.
