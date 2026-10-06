# Plan — Métricas de aquisição, cadastro e ativação por canal

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261004

---

Sem ADR: não há troca de stack nem de arquitetura. O GA4 e o consentimento já existem; a feature só acrescenta eventos, uma convenção de campanha e um sinal aditivo no backend. As decisões de analytics continuam registradas na pasta da feature, como na anterior.

## 1. Gate de consentimento (specify §2.3)

- `frontend/src/analytics/consent.ts`: `isAnalyticsActive()` passa a devolver `Boolean(window.SCD_GA_LOADED) && getConsent() === 'granted'`. O cookie é a fonte da verdade, então revogar corta `page_view` e eventos na hora, sem estado extra para manter em sincronia. Rejeitada a alternativa de zerar `SCD_GA_LOADED` ao revogar: ela deixaria o script carregado e faria o próximo aceite injetá-lo de novo.
- Bug irmão no mesmo trecho, achado ao ler o código: `loadAnalytics()` sai cedo quando `SCD_GA_LOADED` já é `true` (`consent.ts:67`), então revogar e aceitar de novo na mesma sessão nunca reenvia `consent update granted`, e o GA fica negado até recarregar. `setConsent('granted')` passa a sempre emitir o `update` antes de chamar `loadAnalytics()`.
- Testes em `consent.test.ts`: revogar depois de aceitar faz `isAnalyticsActive()` virar `false`; aceitar → revogar → aceitar emite dois `update granted`.
- O mesmo defeito de re-aceite existe no site (`consent.js:52`) mas é de outra superfície e não bloqueia esta feature: registrado como item 067 do backlog.

## 2. Camada única de eventos (specify §2.1 e §2.7)

- Novo `frontend/src/analytics/trackEvent.ts`. Assinatura tipada com mapa fechado de eventos e parâmetros: `sign_up` com `{ method: 'email' | 'google' | 'invite' }`, `group_created` e `expense_created` sem parâmetros. Em tempo de execução, descarta qualquer chave fora da lista permitida, de modo que um parâmetro com e-mail ou valor não chega ao `gtag` nem por descuido.
- Não envia sem `isAnalyticsActive()` e não enfileira no `dataLayer` quando inativo, pelo mesmo motivo de `pageView.ts:15-18`: evento enfileirado seria processado no aceite seguinte e mediria retroativamente.
- **Sobrescreve sempre `page_location`**, usando o mesmo montador de `trackPageView`: `origin` + `sanitizePath(window.location.pathname)`. Sem isso o gtag mandaria o endereço real, e em `/app/aceitar-convite?email=…&token=…` isso leva e-mail e token ao Google. O montador é extraído de `pageView.ts:29-33` para uma função compartilhada, para que página e evento não divirjam.
- Teste obrigatório: `sign_up` disparado na rota do convite com query de e-mail e token produz `page_location` sem `?`, sem o e-mail e sem o token.
- Defesa em profundidade contra os eventos automáticos da "medição aprimorada" (specify §2.7): conferir no GA4 e, se necessário, redigir no console as chaves `email`, `token`, `google_code` e `code` (Admin → Fluxos de dados → Redação de dados) ou desligar os eventos automáticos. Essa parte vive no checklist do §8 e é executada pelo usuário; tentar `gtag('set', {page_location})` no app fica como contingência, só se o DebugView mostrar que ajuda.

## 3. Campanha UTM (specify §2.2)

- Novo `frontend/src/analytics/campaign.ts`. No carregamento do app (`initAnalytics`, chamado em `main.tsx` antes do render), lê `window.location.search` e guarda **em memória** (variável do módulo, sem `localStorage`, sem cookie) apenas `utm_source`, `utm_medium`, `utm_campaign` e `utm_content`. Cada valor só é aceito se casar com `^[a-z0-9._-]{1,64}$`; o que não casar é descartado sozinho. A convenção do documento do §8 usa minúsculas e hífen, então esse formato não rejeita link nosso.
- Por que capturar no carregamento e não só ler a URL de cada rota: um visitante novo que chega a `/app/cadastro?utm_...` ainda não aceitou o banner. O `RouteTracker` já rodou sem enviar nada, e a rota seguinte (depois do aceite) não tem a query. Capturar antes e enviar depois recupera a origem. O dado de campanha não é pessoal e não fica gravado no aparelho.
- O montador de `page_location` (§2) anexa `?utm_...` **uma vez**, no primeiro envio medido da sessão (`page_view` ou evento, o que sair antes), e consome a campanha pendente. O GA4 deriva a campanha da sessão a partir do primeiro hit; repetir em todo hit seria ruído.
- `sanitizePath.ts` continua descartando a query inteira; a campanha é acrescentada depois, por outro caminho. Assim o contrato "nenhuma query passa" do `sanitizePath` não muda.
- Testes: o teste atual `nunca envia query string` (`RouteTracker.test.tsx:69-79`) continua valendo para `email`, `token`, `google_code` e qualquer outro parâmetro, mas a asserção `not.toContain('?')` passa a valer só quando não há UTM válido; casos novos cobrem UTM válido, UTM com valor inválido, campanha consumida uma única vez e campanha pendente enviada só depois do aceite.
- Hipótese a validar no DebugView: o GA4 lê a campanha de `page_location`. Se não ler, a alternativa é enviar `campaign_source`, `campaign_medium`, `campaign_name` e `campaign_content` como parâmetros do mesmo hit.
- O site não muda para UTM: o `gtag('config')` do site já lê `document.location` e o site não tem dado sensível na query. Link de campanha para uma página do site funciona como está, e o `_ga` compartilhado leva a origem ao app (mesmo host).

## 4. Sinal de conta nova no backend (specify §2.4, caminho Google)

- `backend/app/Http/Controllers/GoogleAuthController.php`. Em `handleLoginCallback`, o ramo que cria o usuário (`:200-210`) marca `$isNewUser = true`. Ao gravar o código de troca (`:224`), grava também uma chave de cache separada `google_login_new_user:<code>` com o mesmo TTL (`LOGIN_CODE_TTL_MINUTES`), só quando a conta é nova.
- `exchangeLoginCode` (`:140-153`): depois de puxar o token (e só se ele existir), faz `Cache::pull` da chave nova e devolve `new_user` (booleano) junto de `access_token`, `token_type` e `expires_in`. Sempre presente, `false` quando não é conta nova.
- Por que chave separada e não mudar o valor do código para array: os testes existentes semeiam e leem `google_login_code:<code>` como string (`GoogleAuthControllerTest.php:286,306,328`), e um código emitido antes do deploy (TTL de 1 minuto) continua válido, sem ramo de compatibilidade. A mudança é puramente aditiva e o contrato antigo da resposta se mantém.
- O campo não revela nada que a pessoa autenticada não saiba.
- Testes PHPUnit em `GoogleAuthControllerTest.php`: conta criada no callback → troca devolve `new_user: true`; usuário achado por `google_id` ou por e-mail → `false`; código semeado sem flag (formato antigo) → `false`; a flag é de uso único junto com o código.
- Risco de conflito: a feature em andamento `20260923-google-callback-modsecurity-iss` pode mexer em `callback()` e em `handleLoginCallback`. O diff aqui é pequeno e localizado, então o conflito, se ocorrer, se resolve no PR.

## 5. Pontos de disparo (specify §2.4 e §2.5)

Todos chamam `trackEvent` **depois** de confirmada a resposta da API e dentro do handler de ação, nunca em `useEffect` de montagem, então recarga não duplica.

- `sign_up` por e-mail: `RegisterPage.tsx`, em `handleConfirm`, depois de `res.ok` e antes de `navigate` (`:156-158`). `method: 'email'`.
- `sign_up` por convite: `AcceptInvitePage.tsx`, depois do `await axios.post` de `/api/invitations/verify` (`:72-78`), apenas quando `!isReset` (o componente também faz redefinição de senha). `method: 'invite'`.
- `sign_up` por Google: `LoginPage.tsx`, no `.then` da troca do código (`:25-31`), quando `data.new_user === true`. O tipo `LoginResponse` ganha `new_user?: boolean`. O `google_code` já é removido da URL antes da chamada (`:23`), então recarregar não repete.
- `group_created`: `GroupForm.tsx`, depois do `axios.post` de criação (`:73`), só quando não é edição (`isEdit`).
- `expense_created`: `ExpenseForm.tsx`, no `.then` do `POST /api/expenses` (`:128-130`), antes do `navigate`. O formulário só cria (a edição fica em `ExpenseView`).
- Testes de página (os `*.test.tsx` vizinhos já existem): mock de `../analytics/trackEvent`; afirmam chamada no sucesso e **ausência** de chamada na falha da API e em recarga/montagem.

## 6. Site: exclusão de tráfego de desenvolvimento (specify §2.6)

- `site/src/templates/header.php:54`: `window.SCD_GA_ID` só recebe o ID quando o host da requisição é o host de `site_url` (`parse_url($config['site_url'], PHP_URL_HOST)`); nos demais casos recebe `null`. O `consent.js` já sai de `loadAnalytics` quando não há ID (`:52`), então nada carrega fora de produção. O banner continua aparecendo em dev, o que é inofensivo.
- Lógica em `site/src/helpers.php` como função pequena, para o `header.php` não ganhar regra de negócio solta, ao lado dos outros helpers do site.
- Rejeitada a checagem em `consent.js` (`location.hostname`): o ID estaria no HTML mesmo em dev, e a regra ficaria duplicada entre PHP e JS.
- O site não tem suíte de testes. Verificação: `php -S localhost:4173 -t site/public` (config `site-static`) com `curl` sem `Host` de produção (ID `null`) e com `curl -H 'Host: expense.novemax.com.br'` (ID presente).
- App: já excluído por construção (`config.ts:17`, ID vazio por padrão). Só vira documento: não definir `VITE_GA_MEASUREMENT_ID` ao rodar localmente.

## 7. Política de privacidade (specify §2.7)

- `site/src/legal/privacidade.php`, item 2 (`:26-33`) e item 4 (`:56-59`): descrever que, com consentimento, também são medidas ações como concluir o cadastro, criar um grupo e registrar uma despesa, **sem conteúdo**, e que o endereço das telas pode levar etiquetas de campanha (`utm_*`) que identificam a origem do link e não pessoas. Manter a frase de que nenhum dado de conta, grupo, despesa ou pagamento é enviado, reescrita para não contradizer a medição de que uma despesa foi criada.
- A data "Última atualização" do documento sai do histórico do git no deploy (`site/tools/gerar-datas-legais.sh`), então não há data para editar à mão.
- Texto jurídico em página pública: o usuário revisa o diff antes do merge (gate "antes do merge" na task).

## 8. Documento de métricas e relatório (specify §2.8 e §2.9)

- Novo `docs/analytics/README.md` (diretório novo, no mesmo nível de `backlog` e `bugfix`; a pasta da feature migra para `concluidas/` ao fim e um documento vivo não deve se mover com ela). Conteúdo: dicionário dos três eventos e parâmetros; convenção de UTM (formato dos valores, nomes de campanha do piloto); definição de ativação; limites conhecidos; modelo de relatório por canal; checklist do GA4.
- Checklist do GA4 (executado pelo usuário, que tem acesso de edição): marcar `sign_up` e `expense_created` como eventos-chave; registrar `method` como dimensão personalizada de evento; criar o filtro de tráfego interno; conferir a medição aprimorada e a redação de dados (§2); montar a exploração de funil `sign_up` → `expense_created`.
- Limites a escrever: quem recusa o banner não aparece no GA; sem atribuição entre dispositivos; o GA4 não garante a janela de 7 dias com precisão de relatório padrão, então a janela é uma convenção de leitura e o relatório usa a contagem de `ex_users` no banco como referência do total real de contas.
- Um ponteiro curto em `docs/sdd/05-context-frontend.md`, em "Antes de codar": quem for disparar evento de analytics lê `docs/analytics/README.md` antes. Evita que o próximo evento nasça fora da camada do §2.

## 9. Validação (todas as seções)

- Unitária: vitest nos arquivos tocados, `npx tsc --noEmit`, `pint --test` e `php artisan test` no backend.
- Real, com o usuário no GA4 (DebugView/Tempo real): cadastro por e-mail, por Google (conta nova e conta existente) e por convite; criação de grupo e de despesa; falha de API sem evento; recusa e revogação sem envio; aceitar → revogar → aceitar; link com UTM em visita nova e em visita com consentimento já dado; página `/aceitar-convite?email=…&token=…` sem e-mail nem token em nenhum hit, inclusive nos automáticos. O resultado entra no `implementation.md` e, se o GA4 não ler a campanha de `page_location`, aplica-se a alternativa do §3.

## 10. Ordem de execução

Há dependência técnica:

1. §1 (gate) antes de tudo: os eventos novos passam por ele.
2. §2 (`trackEvent` e montador de `page_location`) antes de §3 e §5, que o usam.
3. §4 (backend) antes do `sign_up` por Google do §5; os outros pontos de disparo não dependem dele.
4. §6 e §7 (site) são independentes de tudo isso e entre si.
5. §8 depois dos eventos, para documentar o que de fato foi implementado.
6. §9 por último, e a parte real depende de deploy em um ambiente com o ID de produção.
