# Specify — Métricas de aquisição, cadastro e ativação por canal

> Feature: medir o caminho visita → cadastro concluído → primeiro uso, por canal de origem, reaproveitando o GA4 e o consentimento que já existem. Origem: item 066 de `docs/backlog/` (`metricas-aquisicao-ativacao.md`), registrado em 2026-10-04 numa conversa sobre divulgação gratuita do Expense. Pré-requisito do piloto de divulgação (item 063) e das páginas por público (061), do conteúdo (062) e da indicação (065).

Versão: 1.0 · Criado em: 20261004

---

## 1. Problema

Visitas isoladas não mostram quais canais trazem pessoas que realmente usam o produto. Antes de um piloto de divulgação gratuita em comunidades, é preciso saber, por canal, quantas pessoas visitaram, quantas concluíram o cadastro e quantas chegaram a usar um grupo com despesas. Hoje isso não é possível: o app só envia `page_view`, a origem de campanha se perde ao entrar no app e não há definição de "ativação".

Dependência: `docs/feature/concluidas/202609/20260920-analytics-app-e-consentimento/` (concluída) entregou a propriedade GA4 única `G-RNQM4DT19G` para site e app, o cookie de consentimento `scd_consent` compartilhado e o `page_view` por rota. Esta feature parte dela e não troca nada disso.

## 2. Achados confirmados e requisitos

### 2.1 Só existe `page_view`; não há nenhum evento de ação

O único `gtag('event', …)` do app é `page_view` (`frontend/src/analytics/pageView.ts:31-34`, com `page_path` e `page_location`), disparado a cada troca de rota por `RouteTracker.tsx:24-26`. O site não envia evento customizado: só o `page_view` automático do `gtag('config')` (`site/public/assets/consent.js`). Os CTAs do site para o app são links simples sem listener.

Login, cadastro, criação de grupo e de despesa não geram nada além do `page_view` da rota seguinte. A taxonomia de eventos de ação foi deixada fora da feature anterior de propósito (`specify.md:47` dela).

Requisito: criar um conjunto pequeno de eventos de ação (§2.9) e uma função única de envio que valide o que sai.

### 2.2 A origem da campanha se perde ao entrar no app

- `RouteTracker.tsx:22,25` lê só `pathname` da localização e passa só ele a `trackPageView`; a query nunca chega ao evento.
- `pageView.ts:29-34` monta `page_location` com `origin + caminho` sanitizado, e `sanitizePath.ts:24` descarta a query inteira. Isso foi feito para não vazar o `?email=…&token=…` do convite (`AcceptInvitePage.tsx:31-32`).
- No site, os destinos de cadastro e login são `/app/cadastro` e `/app/`, sem parâmetros (`site/src/config.php:37-38`).

Efeito: um link de campanha direto para o app, como `/app/cadastro?utm_source=…`, chega ao GA sem a origem. Site e app estão no mesmo host (`/app`), então o `_ga` é compartilhado e quem pousa em uma página do site com UTM mantém a origem na sessão ao seguir o CTA. Isso é inferência sobre o comportamento do GA4 e deve ser conferido no DebugView.

Requisito: definir uma convenção de UTM e deixar passar para o evento **apenas** `utm_source`, `utm_medium`, `utm_campaign` e `utm_content`, com valores restritos a um conjunto de caracteres e a um tamanho máximo. Todo o resto da query (`email`, `token`, `google_code`, qualquer outro parâmetro) continua descartado.

### 2.3 Revogar o consentimento não interrompe o envio na mesma sessão

`setConsent('denied')` só faz `gtag('consent','update',{analytics_storage:'denied'})` (`consent.ts:134-136`). A flag `window.SCD_GA_LOADED` continua `true` e `isAnalyticsActive()` devolve essa flag (`consent.ts:88-90`), então `trackPageView` segue enviando `page_view` depois da revogação, até recarregar a página. O critério de promoção do item 066 exige conferir recusa **e** revogação.

Requisito: o gate que libera qualquer evento deve olhar o consentimento atual (`granted`), não só se o script foi carregado. Eventos novos não podem herdar esse defeito.

### 2.4 "Cadastro concluído" acontece em três caminhos diferentes

| Caminho | Onde a conta passa a existir | Momento seguro para medir |
|---|---|---|
| E-mail | `POST /api/pre-register/verify` | depois de `res.ok`, antes do `navigate` (`RegisterPage.tsx:144-158`) |
| Google | dentro do login: `GoogleAuthController.php:200-210` cria o usuário quando não há `google_id` nem e-mail conhecido | depois da troca do código (`LoginPage.tsx:24-31`), **mas** `exchangeLoginCode` devolve só `access_token`, `token_type` e `expires_in` (`GoogleAuthController.php:148-152`); o frontend não distingue conta nova de login |
| Convite | o usuário é criado por quem convidou (`GroupMemberController.php:48-68`, senha aleatória + e-mail de convite) | ao ativar a conta com a senha: `POST /api/invitations/verify` com sucesso em `AcceptInvitePage.tsx:72-79` |

Detalhe do convite: `AcceptInvitePage` é o mesmo componente da redefinição de senha (`mode: 'invite' | 'reset'`, `AcceptInvitePage.tsx:24-41`) e usa o mesmo endpoint (`InvitationController::verify`). Só `mode === 'invite'` é cadastro.

Requisitos:
- Medir cadastro só depois da confirmação de sucesso, nunca ao montar a tela, para que recarga não duplique.
- Para o Google, o backend passa a informar, de forma aditiva, se a conta acabou de ser criada. Decisão do usuário em 2026-10-04: sinalizar no backend em vez de aceitar o limite.

### 2.5 Não existe "entrar no grupo" no cliente; ativação não pode depender disso

Quem convida adiciona a pessoa ao grupo no servidor, na hora: `GroupMemberController.php:78` (`attach`), tanto para conta existente quanto para a conta criada no convite. Não há tela de aceite que conclua a entrada. Portanto:

- "Criou um grupo" é observável no cliente: `POST /api/groups` em `GroupForm.tsx:73`. O mesmo handler também edita (`isEdit`, `GroupForm.tsx:70-71`); o evento só vale na criação.
- "Entrou em um grupo" **não** é observável no cliente. Quem foi convidado já nasce membro.
- "Registrou uma despesa" é observável: `POST /api/expenses` em `ExpenseForm.tsx:124-129`.

Isso cumpre a exigência do item 066 de não penalizar quem entra por convite: se a ativação exigisse "criar grupo", todo convidado ficaria de fora.

### 2.6 Tráfego de desenvolvimento só é excluído no app

- App: `GA_MEASUREMENT_ID` vem de `VITE_GA_MEASUREMENT_ID` e o padrão é vazio (`frontend/src/config.ts:17`); sem ID nada carrega. Só o `deploy-frontend.yml` preenche.
- Site: o ID é fixo em `site/src/config.php:32` e `header.php:54` o publica sem checar o host. A feature anterior já registrou uma visita de teste local contada na propriedade real (`20260920-analytics-app-e-consentimento/implementation.md:25`).

Requisito: o site não deve carregar o GA fora do host de produção.

### 2.7 Privacidade e consentimento

- Nenhum evento envia e-mail, nome, valores financeiros, tokens, IDs de grupo ou de despesa, nem `user_id` (excluído em `specify.md:49` da feature anterior).
- O gtag continua sem ser carregado antes do aceite (`plan.md:26` da feature anterior). Consequência assumida: quem recusa não aparece no GA, então o GA subconta cadastros e ativações.
- Os eventos novos precisam caber no que a política de privacidade já descreve (`site/src/legal/privacidade.php`); a conferência do texto fica para o Tech Plan.
- *Acrescentado no Tech Plan (2026-10-04):* a política hoje diz que os endereços das telas vão "sem identificadores", "nunca com … dados que venham no endereço", e descreve só páginas, origem da visita, dispositivo e navegador (`privacidade.php:26-33` e `:56-59`). Ao enviar etiquetas de campanha (UTM) e eventos de ação, esse texto deixa de ser verdadeiro e precisa ser ajustado.
- *Acrescentado no Tech Plan (2026-10-04):* todo `gtag('event', …)` leva por padrão o `page_location` igual ao endereço real do documento. Hoje isso não aparece porque o único evento, `page_view`, sobrescreve `page_location` (`pageView.ts:33`). Um evento novo disparado em `/aceitar-convite?email=…&token=…` levaria e-mail e token ao Google se não sobrescrever também. Os eventos automáticos da "medição aprimorada" do GA4 (rolagem, cliques de saída, interação com formulário) usam o endereço real do mesmo jeito; se estiverem ligados na propriedade, o risco já existe em produção. O repositório não mostra essa configuração, então a conferência é no console do GA4.

### 2.8 Relatório por canal

Não existe definição de métricas, relatório nem ADR de analytics no repositório. O GA4 é o único armazenamento de medição. O usuário tem acesso de edição à propriedade `G-RNQM4DT19G` (confirmado em 2026-10-04), então a configuração do console (marcar eventos-chave, registrar dimensões de parâmetro, montar a exploração de funil) pode ser feita e validada por ele.

Requisito: um documento versionado que traga a definição de cada evento, a convenção de UTM, a definição de ativação, o passo a passo do GA4 e um modelo de relatório por canal (visitas, cadastros, ativações) que declare os limites: usuários sem consentimento ausentes, sem atribuição entre dispositivos, e a contagem de `ex_users` no banco como referência para o total real de contas.

### 2.9 Definições propostas (para aprovação)

**Eventos** (nome do evento GA4 → quando → parâmetros):

| Evento | Quando dispara | Parâmetros permitidos |
|---|---|---|
| `sign_up` | cadastro confirmado: e-mail (`res.ok` do verify), Google com conta nova, convite (`mode='invite'` com sucesso) | `method`: `email`, `google` ou `invite` |
| `group_created` | `POST /api/groups` com sucesso e fora do modo edição | nenhum |
| `expense_created` | `POST /api/expenses` com sucesso | nenhum |

`sign_up` e `method` seguem o nome recomendado pelo GA4. Nenhum evento dispara em efeito de montagem, e nenhum em falha da API.

**Ativação** não é um evento próprio: é uma definição de relatório. Pessoa ativada = tem `expense_created` ao menos uma vez dentro de 7 dias do cadastro. A janela de 7 dias e a ausência de "criar grupo" como pré-requisito são as duas escolhas a aprovar. Quem entra por convite já é membro; quem se cadastra sozinho precisa de um grupo para lançar despesa, então a ativação cobre os dois casos.

**Canal**: a dimensão de aquisição vem dos UTM (`utm_source/medium/campaign/content`) e das dimensões de "primeira origem" do usuário no GA4; na ausência de UTM, tráfego direto ou de busca fica nos agrupamentos padrão. Tabela de nomes de campanha para o piloto: parte do documento do §2.8.

## 3. Fora de escopo desta feature

- As páginas por público (061), o conteúdo educativo (062), o kit de divulgação (063), os casos reais (064) e o fluxo de indicação (065). Esta feature entrega só a medição que eles vão usar e a convenção de UTM.
- Medição no servidor (Measurement Protocol), banco de eventos próprio, `user_id` ou qualquer dado pessoal no analytics.
- Eventos além dos três do §2.9: login, convidar membro, pagamento Pix, edição, exclusão. `member_invited` fica para o item 065, que trata de indicação.
- Propriedade ou stream separados para o app, e o app Expo (em migração).
- Mudar a política de carregar o gtag só após o aceite (modelagem de conversão de quem recusa), ou refazer o banner de consentimento.
- Atribuição entre dispositivos, `gclid`/Google Ads e cross-domain (site e app estão no mesmo host).
- Corrigir o item 046 (`refreshToken` salvo como `"undefined"`, `LoginPage.tsx:73`) e os outros itens abertos do backlog que não sejam o 066.
- Qualquer coisa das features em andamento `backend/20260930-recuperacao-acesso` e `20260923-google-callback-modsecurity-iss`, exceto a mudança aditiva no `GoogleAuthController` descrita no §2.4.
