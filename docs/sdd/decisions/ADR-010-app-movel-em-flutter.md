# ADR-010: App móvel em Flutter (substitui a migração para Expo)

Status: Aceita
Data: 2026-10-06

> Aceita pelo mantenedor em 2026-10-08. Este PR muda o status e marca o `ADR-001` como "Superada por ADR-010"; a alteração da Constitution (gate humano, `00-constitution.md` §5.2) e os demais ajustes do SDD, listados em "Consequências", vão em PRs próprios, e o da Constitution só deve ser mergeado depois deste.

## Contexto

O `ADR-001` (2026-08-17) escolheu **Expo + `react-native-web` + Expo Router + `react-native-paper`** para o app móvel, com a meta de unificar web e mobile e minimizar a duplicação de UI. O `frontend/` (React web) continuaria em paralelo até um "corte" de produção (TASK-010).

Passaram-se semanas e o plano não saiu do papel:

- **`app/` nunca foi criado.** O Épico A (`docs/feature/20260817-migracao-frontend-expo/`) tem todas as tasks Pendentes e o `implementation.md` vazio; por isso o `ADR-009` já o deixou fora do arquivamento.
- **O web seguiu sozinho.** Evoluiu em MUI para 19 páginas e 12 componentes (cadastro com código, convite, pagamentos com Pix e comprovante, relatórios, perfil, notificações, analytics), sem compartilhar UI com nenhum app. A unificação por `react-native-web` não se concretizou e o corte (TASK-010) segue sem decisão.
- **Surgiu o objetivo de publicar o app na Google Play.**

Levantamento feito ao avaliar esse objetivo:

- **A lógica de dinheiro mora no backend:** ciclos, saldos, acertos par-a-par (`computeCycleSummary`), autorização, fechamento e geração do Pix. O cliente é fino. As exceções são pequenas: o rateio de parcelas (hoje montado no web, `frontend/src/utils/installments.ts`; os itens 070 e 071 do backlog o levam ao backend), a validação de formulários (o backend valida de novo) e quem vê qual botão (o backend impõe de qualquer forma).
- **Logo, o reaproveitamento de TypeScript do web para o app seria pequeno** (tipos e poucos validadores), e a UI do web é MUI, que nenhuma das duas stacks aproveita.
- **O mantenedor já tem um app Flutter publicado:** o Disfagia APP (pacote `novemax.disfagia.v2`, versão `1.0.8+39`, `targetSdk` 36), com pipeline de build e publicação na conta do Play Console em que o Shared Expense será publicado. O Disfagia é um app simples (sem login, formulário complexo ou upload): comprova o toolchain e a publicação, não a complexidade de 19 telas autenticadas.

O `ADR-001` já tinha considerado Flutter e o descartou porque "dobraria o esforço de manter duas linguagens/paradigmas de UI para o mesmo conjunto de telas, sem ganho claro dado o tamanho da equipe". As premissas mudaram em três pontos: a unificação com o web não aconteceu (as duas linguagens existem de qualquer forma), o cliente é fino (o ganho de reaproveitar código é pequeno) e o mantenedor já domina o pipeline Flutter.

## Decisão

1. O app móvel do Shared Expense será em **Flutter (Dart)** com Material 3, no projeto **`app/`** na raiz do repositório (caminho que o SDD já usa), **Android primeiro**. iOS não faz parte desta decisão; nada a impede no futuro.
2. O **`frontend/` (React web) não muda de papel**: continua sendo o cliente web em produção. Fica abandonada a convergência web+mobile por `react-native-web`; não há plano de trocar o web pelo app.
3. O **backend não muda de contrato por causa do app**: mudanças só aditivas (`00-constitution.md` §4.1 e §4.3), e web e app consomem a mesma API.
4. **Regra de negócio fica no backend**, como já exige a Constitution §1 item 1 ("nenhuma lógica de negócio deve viver no frontend além de validação de UX"). O app não reimplementa regra de dinheiro, o que inclui o rateio de parcelas: hoje ele é montado pelo web, um desvio dessa regra, e passa ao backend antes de o app começar (itens 070 e 071 do backlog).
5. **Escolhas de biblioteca não são decididas aqui** (gerência de estado, rotas, cliente HTTP, armazenamento seguro, deep links, etc.): ficam para o `plan.md` da feature do app (item 074 do backlog).
6. Esta decisão **substitui o `ADR-001`**: com ela aceita, o `ADR-001` passa a "Superada por ADR-010" e o Épico A é marcado como substituído (TASK-001 a TASK-010 e TASK-022 a TASK-026 ficam obsoletas).

## Consequências

**Ganhos**
- Aproveita a experiência e o pipeline Flutter que já existem (build, assinatura, publicação na Play).
- A refatoração do web para servir ao Expo (TASK-022 e TASK-023: client HTTP único e storage assíncrono de token) deixa de ser necessária.
- Como o cliente é fino, a separação entre front e back reduz o risco da troca de stack.

**Custos e trade-offs aceitos**
- **Nenhum código compartilhado com o web** (React/TypeScript de um lado, Dart do outro): cada tela nova é feita duas vezes, mas as regras não, porque ficam no backend.
- **Sem atualização OTA** (só com ferramenta paga de terceiros): toda correção passa pela loja. Mitigação: o item 073 do backlog (versão mínima da API / atualização forçada) e a API aditiva.
- **A experiência prévia é de um app simples**: reservar tempo para estado, formulários e upload de arquivo.
- **O app começa depois**: a ordem escolhida para reduzir risco é 070 (rateio no backend) → 071 (web usa o contrato novo) → 074 (app). O contrato novo é provado em produção por um cliente maduro antes de o app depender dele.
- O mantenedor passa a manter duas stacks de UI.

**Ajustes do SDD** (o status deste ADR e o do `ADR-001` mudam neste PR; o resto vai em PRs próprios)
- `00-constitution.md`, **5 linhas** (além do cabeçalho: versão 1.5 → 1.6 e data), em PR próprio e só depois da aceitação. Texto proposto:
  - §3, tabela de stack: a linha "Frontend mobile/web unificado *(em migração)*" vira `App mobile | Flutter (Dart) + Material 3, projeto app/ — ver decisions/ADR-010`.
  - §3, nota abaixo da tabela: "Decisão registrada em `decisions/ADR-010`: app móvel em **Flutter**, em projeto novo (`app/`), com `frontend/` (React web) permanecendo como cliente web em produção. Substitui a migração para Expo do `decisions/ADR-001`."
  - §4, item 3: "app Expo (`expense/app`)" e "frontend web (`expense/frontend`)" viram "app Flutter (`app/`)" e "frontend web (`frontend/`)".
  - §5.2, linha de deploy: "Deploy (workflow `deploy-backend.yml`, EAS build do Expo)" vira "Deploy (workflow `deploy-backend.yml`)", sem a menção ao EAS, que não existe em Flutter.
  - §5.2, linha do corte de produção: "Corte de produção do frontend novo (`expense/app`) substituindo `expense/frontend`" vira "Publicar o app na Google Play (primeira versão e releases em produção; o `frontend/` web não é substituído)", continuando gate humano. Com este ADR não há corte do web.
- Outros 12 documentos que citam a stack ou esse gate (varredura por `Expo`, `React Native`, `expense/app`, "corte de produção", "app novo"): `02-plan.md` (13 menções, principalmente o §2), `05-context-frontend.md` (6), `01-specify.md`, `03-tasks.md` (ponteiro do Épico A), `06-context-backend.md`, `docs/sdd/README.md`, `agent-architecture.md` (gate "corte de produção do app novo"), `agents-roadmap.md`, `modelo-arquitetural.md` (inclui a linha espelho do gate), `CLAUDE.md`, `AGENTS.md` e a skill `.claude/skills/expense-frontend/SKILL.md`. Total, com a Constitution: 13 arquivos.
- `ADR-001` para "Superada por ADR-010" e este ADR para "Aceita" no índice de `decisions/README.md` (feito neste PR).
- Novo `docs/sdd/07-context-mobile.md` e skill `expense-mobile`, no molde de `05` e `06`.
- Nota de supersessão em `docs/feature/20260817-migracao-frontend-expo/`.
- Os arquivos em `concluidas/` e `concluidos/` são retratos históricos e não são alterados.

## Alternativas consideradas

- **Expo + React Native Paper (manter o `ADR-001`).** Vantagens: mesma linguagem do web, tipos e alguns validadores reaproveitáveis, atualização OTA e build na nuvem. Não escolhida: com o cliente fino o reaproveitamento é pequeno, a unificação com o web não aconteceu, exigiria a refatoração do web (TASK-022 e TASK-023) e o mantenedor já tem pipeline de publicação em Flutter.
- **TWA/PWA do web atual** (Bubblewrap ou PWABuilder). É o caminho de menor esforço (manifest, ícones e `assetlinks.json`) e atualiza junto com o deploy do web. Não escolhida: não entrega app nativo (push, offline), o `ADR-001` já a descartara por não dar a experiência de app "de verdade", e há risco de reprovação por funcionalidade mínima, a reconferir na política vigente.
- **Capacitor sobre o build React.** Descartada: o Google não aceita login OAuth em WebView embutido (`disallowed_useragent`), o que exigiria plugin nativo e mudança no backend; também exige nova versão na loja a cada mudança e fica fora do `ADR-001` e deste.
- **Nativo Kotlin/Swift.** Continua descartado, pelo motivo do `ADR-001`: dobra o esforço e não é multiplataforma.

## Referências

- `ADR-001` (decisão que esta substitui), `ADR-009` (Épico A fora do arquivamento) e `00-constitution.md` §3, §4 e §5.2.
- Épico A: `docs/feature/20260817-migracao-frontend-expo/`.
- Backlog: itens 070 (rateio de parcelas no backend), 071 (web usa o contrato novo), 072 (exclusão de conta), 073 (versão mínima da API) e 074 (app Flutter na Google Play). A trilha é 070 → 071 → 074; 072 e 073 precisam estar prontos antes do release do app.
- Disfagia APP, projeto Flutter do mantenedor fora deste repositório.
- Requisito de publicação na Google Play (não é decisão de stack): https://developer.android.com/google/play/requirements/target-sdk
