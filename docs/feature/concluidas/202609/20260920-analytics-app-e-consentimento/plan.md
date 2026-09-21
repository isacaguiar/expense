# Plan — Analytics no app e consentimento de cookies

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260920

---

## 0. Restrições herdadas das duas superfícies

- **Site**: PHP sem build, JS em ES5/IIFE com progressive enhancement (`site/public/assets/nav.js` é o modelo), deploy FTP de `site/public/` → `/www/` e `site/src/` → `/src/`.
- **App**: Vite 7 + React 18 + MUI 7 + `react-router-dom@7`, deploy FTP de `frontend/dist/` → `/www/app/`.
- **Mesma origem.** Os dois são servidos de `https://expense.novemax.com.br` (o app em `/app/`). É isso que permite uma decisão de consentimento valer para as duas superfícies, sem perguntar duas vezes.
- **Sem código compartilhado.** Pipelines diferentes: o contrato (nome do cookie e valores aceitos) é necessariamente duplicado entre PHP e TypeScript. Mitigação: comentário cruzado dos dois lados apontando um para o outro, como `site/src/config.php` já faz com `free_groups_limit`.

## 1. Estado do consentimento em cookie, não em localStorage (specify §2.1)

- Cookie `scd_consent`, valores `granted` | `denied`, `Path=/`, `Max-Age` de 180 dias, `SameSite=Lax`, `Secure`, sem `HttpOnly` (o JS precisa escrever).
- **Por que cookie e não localStorage**, já que a origem é a mesma: o site é PHP e o cookie chega no `$_COOKIE`, então o servidor decide **antes de renderizar** o que emite. Com localStorage, a decisão só existiria depois do JS rodar, e o HTML já teria saído. É também o único mecanismo que PHP e React leem do mesmo jeito.
- **Ausência do cookie = ausência de decisão** = negado, com banner visível. Estado indeciso não é consentimento.
- Os 180 dias fazem o pedido voltar depois de meio ano — consentimento renovável, não eterno.

## 2. O `gtag.js` só carrega depois do aceite (specify §2.1 e §2.2)

- Nenhuma das superfícies passa a ter `<script src="...gtag/js">` estático. No lugar: um bloco inline de **Consent Mode v2** declarando `analytics_storage: 'denied'` (e `ad_storage` / `ad_user_data` / `ad_personalization` idem) e, só quando o cookie disser `granted`, a injeção dinâmica do `gtag.js`.
- **Por que não Consent Mode sozinho, com o script sempre carregado** (o padrão que o Google recomenda): com `denied` o GA4 não grava cookie, mas ainda dispara *cookieless pings* para o Google. O requisito §2.1 fala de cookie, mas numa superfície autenticada, com dado financeiro, a leitura conservadora é não haver requisição nenhuma antes do aceite. O custo é perder a modelagem de conversão de quem recusa — irrelevante no volume atual.
- `site/src/templates/header.php:18-26` deixa de injetar o gtag incondicionalmente e passa a emitir só o bloco de consentimento.
- Ao **aceitar**: grava o cookie, injeta o `gtag.js` na hora (sem reload) e emite `gtag('consent','update',{analytics_storage:'granted'})`.
- Ao **recusar**: grava o cookie, esconde o banner, e nada é carregado.

## 3. Banner e revogação no site (specify §2.1)

- `site/src/templates/consent.php` (markup do banner + bloco de consentimento), incluído por `footer.php`; `site/public/assets/consent.js` no estilo do `nav.js` — IIFE, `'use strict'`, comentário explicando o porquê.
- **Falha fechada, ao contrário do `nav.js`**: sem JS, o menu precisa continuar usável, mas analytics sem JS simplesmente não existe. Sem o script: nenhum banner e nenhuma medição.
- Revogação: botão "Preferências de cookies" no rodapé, reabrindo o banner. Vai em markup próprio no `footer.php`, **não** em `config.php['footer_nav']` — aquela lista renderiza `<a href>` e isto é `<button>`.

## 4. Bootstrap e banner no app (specify §2.1 e §2.2)

- `frontend/src/analytics/consent.ts`: ler/escrever o cookie, publicar o estado default negado, injetar o `gtag.js` sob demanda.
- `frontend/src/analytics/ConsentBanner.tsx`: MUI `Snackbar` ancorado embaixo, com "Aceitar" e "Recusar" — **não modal**. Bloquear a tela de login com um diálogo cobraria caro na conversão, e a LGPD exige escolha livre, não bloqueio.
- Montado em `App.tsx` acima de `<Routes>`, fora do `RequireAuth`: o banner precisa existir também em `/`, `/cadastro` e `/aceitar-convite`.
- Revogação no app: entrada em `Profile` (`frontend/src/pages/Profile.tsx`) que reabre o banner.
- **Nada de `<script>` em `frontend/index.html`**: o ID viria de env e o `index.html` é servido estático; manter tudo em TS mantém uma fonte só (§7) e é o que permite a injeção condicional do item 2.

## 5. `page_view` a cada rota (specify §2.3)

- `main.tsx:12` monta `<BrowserRouter basename="/app">` no modo declarativo, e `App.tsx` usa `<Routes>`. Em `react-router-dom@7`, `useMatches` só existe em data router (`createBrowserRouter`) — não dá para pegar o padrão da rota pronto sem migrar o roteamento, o que está fora de escopo.
- Decisão: componente `<RouteTracker />` dentro do Router, com `useLocation()`; a cada mudança de `pathname`, dispara `page_view` com o caminho já normalizado pelo item 6.
- `basename` faz `location.pathname` vir **sem** o `/app`. O `page_path` enviado recoloca o prefixo (`/app` + pathname), para que as telas do app e as páginas do site convivam legíveis no mesmo relatório — consequência direta de compartilhar a propriedade `G-RNQM4DT19G`.
- O `config` inicial vai com `send_page_view: false`: sem isso o gtag dispara um `page_view` próprio no carregamento e duplicaria com o primeiro do `RouteTracker`.

## 6. Nada de identificador no que é medido (specify §2.4)

Hoje há dois vazamentos possíveis, não um:

- **Caminho**: `/groups/:id/summary`, `/groups/:id/expenses/:expenseId` etc. (`App.tsx`) — o caminho resolvido levaria IDs de grupo e de despesa.
- **Query string**: `AcceptInvitePage.tsx:17-21` lê `email` e `token` de `useSearchParams()`. A URL real de um convite é `/app/aceitar-convite?email=<e-mail do convidado>&token=<token>`. Enviar `page_location` cru mandaria **o e-mail de uma pessoa e um token de convite válido** para o Google. Este é o ponto mais sério da feature.

Decisões:

- Função pura `sanitizePath(pathname)` que (1) descarta query string e hash por completo e (2) substitui todo segmento composto só de dígitos por `:id`.
- **Por que não `matchRoutes()`**: exigiria manter a lista de rotas num array fora do `App.tsx`, duplicando o roteamento — exatamente o tipo de duplicação que o item 053 do backlog registra como problema. O regex cobre 100% das rotas atuais, cujos params são todos numéricos.
- **Risco assumido**: uma rota futura com param não numérico (slug, UUID) passaria cru. Mitigação: comentário no código e teste unitário fixando o contrato, para quem adicionar a rota ver o que precisa.
- Teste com o `vitest` que já existe (`npm test`): `/groups/42/expenses/1337` → `/groups/:id/expenses/:id`; `/aceitar-convite?email=a@b.com&token=xyz` → `/aceitar-convite`.

## 7. O ID da propriedade num lugar só (specify §2.5)

- App: `frontend/src/config.ts` ganha `GA_MEASUREMENT_ID = import.meta.env.VITE_GA_MEASUREMENT_ID ?? ''`. **Default vazio de propósito**: sem a variável — `npm run dev` local, preview — o analytics fica desligado e não suja a propriedade de produção com tráfego de desenvolvimento.
- `.github/workflows/deploy-frontend.yml:32-33` ganha `VITE_GA_MEASUREMENT_ID: G-RNQM4DT19G` ao lado do `VITE_API_BASE_URL` — mesmo padrão, valor inline, porque um ID de propriedade GA não é segredo (ele vai no HTML de qualquer forma).
- `frontend/.env.example` documenta a variável.
- Site: `site/src/config.php` ganha `'ga_measurement_id' => 'G-RNQM4DT19G'`, e `header.php` para de carregar o ID solto no meio do template — a mesma regra que o site já segue para todo o resto.

## 8. Política de privacidade (specify §2.6)

- `grep -i 'cookie|analytics|google'` em `site/public/privacidade.php` e `termos.php` não retorna nada: os documentos hoje não mencionam medição. É texto novo, não ajuste.
- Encaixa nas seções que já existem, sem criar seção nova (renumeraria as 8): **§2 Quais dados coletamos** (o que o GA coleta), **§4 Compartilhamento** (Google como operador), **§6 Seus direitos** (como revogar).
- `config.php['updated_at']` é string fixa compartilhada por todos os documentos legais (item 051 do backlog): atualizar a data aqui muda a data de todos. Limitação conhecida e já registrada — não vira escopo desta feature.

## 9. Ordem de execução

Há dependência técnica real:

1. **§1** (contrato do cookie) primeiro — é o que as duas superfícies leem.
2. **§7** (ID em config) antes de qualquer carregamento.
3. **§2 + §3** (site): superfície menor e sem build, valida o desenho do consentimento de ponta a ponta.
4. **§4** (app): bootstrap e banner, reusando o contrato já provado no site.
5. **§6** (sanitizer + teste) antes de **§5** (RouteTracker): a função pura e seu teste primeiro, o disparo depois — nunca o inverso, para não existir nem por um commit um `page_view` com e-mail de convidado.
6. **§8** (política) por último, descrevendo o comportamento final já implementado.
