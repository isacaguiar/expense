# Specify — Analytics no app e consentimento de cookies

> Feature: instrumentar o app React com Google Analytics e passar a pedir consentimento antes de qualquer cookie de analytics — no app e no site. Promoção do item 048 do backlog (`/promover-backlog 048`), agrupada com o pedido novo de medir o app (conversa de 2026-09-20).

Versão: 1.0 · Criado em: 20260920

---

## 1. Problema

O app não é medido e o site é medido errado.

**Não se mede o app.** `frontend/index.html` não carrega nada de analytics — nenhuma ocorrência de `gtag`/`googletagmanager` em `frontend/`. Não há como saber quais telas são usadas, onde o usuário abandona, nem se o cadastro novo (`docs/feature/20260919-cadastro-de-usuarios/`) converte.

**O site mede sem consentimento.** `site/src/templates/header.php:18-26` injeta o `gtag.js` (`G-RNQM4DT19G`) incondicionalmente, antes até do `<meta charset>`, sem banner e sem forma de recusa, enquanto `site/public/privacidade.php` publica uma política. É o item 048 do backlog (MEDIA, aberto): analytics com cookie é tratamento de dado pessoal sob a LGPD, e o consentimento precisa ser livre, informado e revogável.

Repetir o snippet no app ampliaria esse descasamento para uma superfície **autenticada**, onde a navegação é de usuário identificável e as rotas carregam grupos, despesas e pagamentos. Por isso a decisão foi instrumentar o app já com consentimento e resolver o 048 na mesma feature, em vez de duplicar o débito e voltar depois.

## 2. Requisitos

### 2.1 Nenhum cookie de analytics antes do aceite

Consent Mode v2 com `analytics_storage: 'denied'` como estado padrão, definido **antes** do `gtag.js` carregar, no app e no site. Banner com aceitar e recusar, escolha persistida entre visitas e revogável depois. Critério verificável: com a escolha ausente ou recusada, nenhum cookie `_ga*` é criado.

### 2.2 Medição do app React

O app passa a carregar o gtag na propriedade **`G-RNQM4DT19G`** — a mesma do site (decisão do usuário; separar em stream própria fica para depois).

### 2.3 `page_view` a cada mudança de rota

`frontend/src/main.tsx:12` monta `<BrowserRouter basename="/app">` e `frontend/src/App.tsx` declara 22 rotas em `<Routes>`. Um `gtag('config', ...)` no HTML dispara `page_view` só no carregamento inicial: trocar de tela dentro do app não geraria nada. O disparo precisa acompanhar a navegação do React Router.

### 2.4 Nenhum identificador de dado do usuário no caminho medido

As rotas privadas são `/groups/:id/summary`, `/groups/:id/expenses/:expenseId`, etc. (`frontend/src/App.tsx`). Mandar o caminho literal enviaria IDs de grupo e de despesa para o Google a cada tela. O que for medido tem que ser o **padrão** da rota, não a URL resolvida.

### 2.5 O ID da propriedade mora em um lugar só

`frontend/src/config.ts:1` já estabelece o padrão (`import.meta.env.VITE_* ?? default`) e `.github/workflows/deploy-frontend.yml:32-33` já injeta `VITE_API_BASE_URL` no build. O ID do GA segue o mesmo caminho — sem string solta espalhada por componente.

### 2.6 A política de privacidade e o comportamento contam a mesma história

`site/public/privacidade.php` descreve o tratamento de dados; o texto precisa refletir o consentimento que passa a existir, incluindo como revogar.

## 3. Fora de escopo desta feature

- **Eventos de ação nomeados** (login, criar grupo, lançar despesa, fechar ciclo): a decisão foi "snippet + rotas SPA". Taxonomia de eventos é trabalho próprio e exige cuidado com parâmetros para não vazar dado.
- **Propriedade ou stream separada para o app**: decidido reusar `G-RNQM4DT19G`. Se depois quiser separar site de produto, é troca de ID, não rearquitetura — desde que 2.5 seja respeitado.
- **User-ID do GA / `user_id`**: não entra. Nenhum identificador de usuário é enviado.
- **Backend**: nenhuma rota, migration, model ou log novo. A feature é frontend + site.
- **Outros itens de backlog do site**: 049 (página 404), 050 (três nomes do produto), 051 (`updated_at` fixo), 054 (og:image dedicada) seguem abertos.
- **App Expo (`app/`)**: não existe no repositório hoje.
