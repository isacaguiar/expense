# Specify — CI do frontend (type-check, testes, build)

> Feature: cria um workflow de CI (verificação, não deploy) para `expense/frontend`, rodando em Pull Requests contra `dev`/`main`, para pegar regressão de tipo/teste/build antes do merge. Origem: promoção do item de backlog `008` (`docs/backlog/workflow-cicd-frontend.md`), solicitado diretamente pelo usuário em 2026-08-18.

Versão: 1.0 · Criado em: 20260827

---

## 1. Problema

Hoje só existe `.github/workflows/deploy-backend.yml` — um workflow de **deploy** do backend (dispara em `push` para `main`, não roda nenhum teste, só builda e publica via FTP). Não existe nenhum workflow do GitHub Actions para o frontend, nem nenhuma checagem automática (de tipo, teste ou build) rodando em Pull Request para nenhum dos dois lados do projeto.

Na prática, isso significa que um PR pode ser aberto e mergeado com `tsc` quebrado, teste falhando ou `vite build` falhando, e isso só aparece se alguém rodar os comandos manualmente antes (como o checklist de `docs/sdd/04-implementation.md` já pede, mas sem chegar a ser uma checagem obrigatória do GitHub).

## 2. Requisitos

### 2.1 Workflow de CI (verificação) do frontend

Novo `.github/workflows/ci-frontend.yml`, com trigger `pull_request` para as branches `dev` e `main` (cobre tanto PRs de feature contra `dev` quanto o PR de promoção `dev` → `main`, ambos already fluxos reais do projeto — `docs/sdd/04-implementation.md` §1), rodando, nesta ordem, dentro de `frontend/`:

1. `npm ci` (instalação determinística a partir de `package-lock.json`, já existente).
2. `npx tsc --noEmit` (type-check — mesmo comando já usado manualmente no checklist pré-PR).
3. `npx vitest run` (suíte de testes completa — mesmo comando já usado manualmente).
4. `npm run build` (`vite build` — garante que o build de produção não quebra).

Qualquer passo que falhar reprova o workflow (comportamento padrão do GitHub Actions — um `step` com exit code não-zero para o job).

### 2.2 Sem lint

O frontend não tem ESLint configurado hoje — não há `.eslintrc`/`eslint.config.*` nem script `lint` em `package.json` (confirmado ao investigar o código antes desta etapa). Configurar lint do zero (escolher regras, rodar contra a base inteira, corrigir violações existentes) é trabalho à parte, com escopo e decisão de regras próprios — decisão do usuário ao promover este item: fica de fora desta feature, como um possível item de backlog novo se for decidido depois.

### 2.3 Sem deploy

Este workflow é só CI (verificação) — não builda para produção nem publica nada. Não existe hoje nenhum mecanismo de deploy automatizado para o frontend (ao contrário do backend, que já tem `deploy-backend.yml`) para esta feature estender; criar um exigiria decisões de infraestrutura (onde hospedar, como servir os arquivos estáticos, quais segredos) fora do escopo deste achado — e deploy é sempre gate humano (`00-constitution.md` §5.2), então não seria algo para a IA decidir/criar sozinha de qualquer forma.

## 3. Fora de escopo desta feature

- Configurar ESLint (ou qualquer outro linter) — ver §2.2.
- Qualquer workflow de deploy do frontend — ver §2.3.
- Mudar `deploy-backend.yml` ou criar CI equivalente para o backend (PHPUnit/Pint em PR) — fora do pedido original do item 008, que é especificamente sobre o frontend; pode virar um item de backlog novo à parte, se decidido depois.
- Cache de dependências (`actions/cache` para `node_modules`) ou qualquer otimização de velocidade do workflow — a suíte de testes do frontend já é rápida o suficiente hoje; otimização prematura sem medir o tempo real do workflow em CI.
