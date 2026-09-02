# Bugfix — App não responsivo no celular (meta viewport ausente)

Versão: 1.0 · Criado em: 20260901 · Branch: `fix/20260901-frontend-meta-viewport-mobile`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — só uma `<meta>` tag em `frontend/index.html`; nenhuma rota/controller/middleware, nenhuma lógica de autenticação. A tela de login apenas renderiza melhor.
- [ ] **Migration ou contrato de API** — HTML estático; sem banco, sem mudança de resposta/rota/status/payload.
- [ ] **Causa raiz obscura / correção ampla** — causa identificada com precisão (`frontend/index.html` sem `<meta name="viewport">`, confirmado pelo histórico do git); correção de 1 linha + 1 teste-guard. (A auditoria da Parte 2 só produz uma lista; se virar correção ampla, escala para `/nova-feature` — regra do README.)
- [ ] **Decisão de produto/arquitetura** — a meta viewport é requisito padrão de setup web/MUI, não escolha de produto.

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** Ao abrir o `frontend/` (React web, servido sob `/app`) pelo celular, o app
  renderiza como página desktop encolhida — "letras pequenas", sem adaptação ao tamanho da
  tela. A tela de login inclusive mostra o painel de branding lateral (que deveria sumir no
  mobile) espremido ao lado do formulário.
- **Reprodução:**
  1. Abrir o app em produção (`/app/`) num celular, ou num desktop com o navegador em
     viewport ≤ 480px (DevTools mobile / `resize_window` 375×812).
  2. Observar a tela de login (`/`) e qualquer tela interna (`/meus-grupos`).
  3. Conteúdo aparece reduzido no zoom, exige pinça para ler; layout desktop (sidebar fixa,
     colunas lado a lado) permanece em vez do layout mobile.
- **Esperado vs. atual:**
  - **Esperado:** em telas estreitas, o app usa os layouts mobile que já existem no código —
    login só com o card do formulário (`LoginBrandingPanel` oculto em `xs`), navegação por
    hambúrguer + `MobileNavDrawer` no lugar do `Sidebar` fixo, fonte em tamanho legível sem
    zoom.
  - **Atual:** navegadores mobile assumem viewport de layout ~980px e encolhem a página
    inteira para caber. Todos os breakpoints MUI (`xs`/`md`) são avaliados contra 980px, então
    o layout `md`+ (desktop) sempre vence — só que reduzido no zoom.
- **Causa raiz:** `frontend/index.html:3-9` — o `<head>` tem `<meta charset>`, `<title>` e os
  `<link>` de fonte, mas **não tem** `<meta name="viewport" content="width=device-width, ...">`.
  `git log -p --all -- frontend/index.html` confirma que a string `viewport` nunca existiu no
  arquivo (desde o commit inicial `ffb989187`). Sem essa tag, o "layout viewport" do mobile
  não passa a ser a largura real do dispositivo, e todo o CSS/JS responsivo (media queries,
  breakpoints do MUI) trabalha contra uma largura fictícia de ~980px. O trabalho responsivo
  já entregue (features `20260819-novo-layout-tela-login`, `20260826-navegacao-mobile-*`)
  fica inerte no celular por causa dessa única linha ausente.

## 2. Correção

- **O que muda e por quê:** adiciona `<meta name="viewport" content="width=device-width, initial-scale=1" />`
  ao `<head>` de `frontend/index.html` (logo após o `<meta charset>`). É o requisito padrão
  de setup web/MUI que estava ausente. Com ele, o layout viewport do mobile passa a ser a
  largura real do dispositivo, os breakpoints do MUI (`xs`/`md`) voltam a refletir a tela e o
  layout responsivo já existente (login sem painel de branding, `MobileNavDrawer` no lugar do
  `Sidebar` fixo) passa a ativar no celular. Forma padrão, sem `maximum-scale`/`user-scalable=no`
  (bloquear pinch-zoom é anti-padrão de acessibilidade).
- **Arquivos tocados:**
  - `frontend/index.html` — 1 linha adicionada.
  - `frontend/src/index-html.test.ts` — novo; guarda de regressão (importa `../index.html?raw`
    via Vite e afirma que a `<meta name="viewport">` com `width=device-width` está presente).
  - `docs/bugfix/README.md` — linha na tabela "Em andamento".
- **Teste de regressão:** `frontend/src/index-html.test.ts` — falha (vermelho) sem a tag,
  passa (verde) com a tag. TDD: red confirmado antes da correção, green depois.
- **Riscos / efeitos colaterais:** baixíssimo. É uma meta tag padrão; nenhum código JS/CSS
  muda. Telas que antes só eram vistas "com zoom" agora renderizam no layout mobile real —
  possíveis ajustes de usabilidade em telas internas são tratados fora deste bugfix
  (auditoria de usabilidade mobile, Parte 2 do plano). Não toca auth, API, banco.
- **Fora de escopo (registrar em `docs/backlog/`):** `frontend/index.html` tem
  `<html lang="en">` (app é pt-BR) e `<title>SCD - Login</title>` fixo em todas as rotas;
  `frontend/dist/index.html` está versionado apesar de `dist/` estar no `.gitignore`.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-01 | `cd frontend && npx vitest run src/index-html.test.ts` (antes da correção) | 1 failed — `toMatch` não encontra `<meta name="viewport">` (TDD red) |
| 2026-09-01 | `cd frontend && npx vitest run src/index-html.test.ts` (depois da correção) | 1 passed (TDD green) |
| 2026-09-01 | `cd frontend && npx tsc --noEmit` | sem erros |
| 2026-09-01 | `cd frontend && npm test` | 27 arquivos, 175 testes, todos passando (inclui o novo guard) |
| 2026-09-01 | `cd frontend && npm run build` | `✓ built in 11.66s` (aviso pré-existente de chunk > 500 kB, não relacionado) |
| 2026-09-01 | Browser em viewport 375×812 (`/app/`) | `meta[name=viewport]` presente; `documentElement.clientWidth` = 375 (era ~980); `visualViewport.scale` = 1 (sem shrink-to-fit); sem overflow horizontal (`scrollWidth` = 375); painel de branding oculto (`xs`); sem erro no console |
| 2026-09-01 | Browser em viewport 375×812 (`/app/meus-grupos`) | shell mobile renderiza — botão hambúrguer + `MobileNavDrawer` no lugar do `Sidebar` fixo |
