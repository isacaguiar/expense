# Tasks — Métricas de aquisição, cadastro e ativação por canal

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261004

IDs a partir de TASK-383: o maior em `dev` é TASK-379 e a branch `backend/20260930-recuperacao-acesso` já usa até TASK-382. Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-383 | Corrigir o gate de consentimento do app: revogar interrompe os envios e reaceitar reenvia `consent update granted` | frontend | plan.md §1 | nenhum | Pendente |
| TASK-384 | Criar `trackEvent` com lista fechada de eventos e parâmetros e `page_location` sanitizado, extraindo o montador compartilhado com `trackPageView` | frontend | plan.md §2 | nenhum | Pendente |
| TASK-385 | Capturar a campanha UTM validada em memória e anexá-la ao primeiro envio medido | frontend | plan.md §3 | nenhum | Pendente |
| TASK-386 | Informar `new_user` na troca do código do login Google | backend | plan.md §4 | nenhum | Pendente |
| TASK-387 | Disparar `sign_up` com `method: email` ao confirmar o cadastro por e-mail | frontend | plan.md §5 | nenhum | Pendente |
| TASK-388 | Disparar `sign_up` com `method: invite` ao ativar a conta por convite | frontend | plan.md §5 | nenhum | Pendente |
| TASK-389 | Disparar `sign_up` com `method: google` ao criar conta pelo login Google | frontend | plan.md §5 | nenhum | Pendente |
| TASK-390 | Disparar `group_created` ao criar um grupo | frontend | plan.md §5 | nenhum | Pendente |
| TASK-391 | Disparar `expense_created` ao registrar uma despesa | frontend | plan.md §5 | nenhum | Pendente |
| TASK-392 | Publicar o ID do GA no site só no host de produção | frontend | plan.md §6 | nenhum | Pendente |
| TASK-393 | Atualizar a política de privacidade para eventos de ação e etiquetas de campanha | frontend | plan.md §7 | antes do merge (usuário revisa o texto) | Pendente |
| TASK-394 | Documentar as métricas em `docs/analytics/README.md` e apontar para ele no contexto do frontend | doc | plan.md §8 | nenhum | Pendente |
| TASK-395 | Validar os fluxos no preview inspecionando o `dataLayer` | frontend | plan.md §9 | nenhum | Pendente |
| TASK-396 | Validar os eventos no GA4 depois do deploy e concluir o checklist do console | infra | plan.md §8 e §9 | depois do deploy em produção; o usuário opera o GA4 | Pendente |

TASK-392 e TASK-393 são tipo `frontend` porque o site institucional (PHP, `site/`) não tem tipo próprio na tabela do SDD e é entregue pelo mesmo fluxo de deploy do frontend (`deploy-site.yml`).

## Critérios de aceite

- **TASK-383**: `cd frontend && npx vitest run src/analytics/consent.test.ts` verde, com casos novos que provam (a) aceitar → revogar faz `isAnalyticsActive()` devolver `false` e `trackPageView` não enfileira nada no `dataLayer` depois disso; (b) aceitar → revogar → aceitar emite dois `['consent','update',{analytics_storage:'granted'}]` e injeta um único `<script>` do gtag. `npx tsc --noEmit` sem erro.
- **TASK-384**: existe `frontend/src/analytics/trackEvent.ts` com testes verdes provando que (a) sem consentimento não envia e não enfileira; (b) parâmetro fora da lista é descartado (`{ method: 'email', email: 'a@b.com' }` envia só `method`); (c) `sign_up` disparado na rota `/aceitar-convite?email=a@b.com&token=abc` produz `page_location` sem `?`, sem o e-mail e sem o token; (d) `trackPageView` e `trackEvent` usam o mesmo montador, e os testes atuais de `RouteTracker.test.tsx` e `sanitizePath.test.ts` continuam verdes. `npx tsc --noEmit` sem erro e o tipo só aceita os três eventos do plano.
- **TASK-385**: testes verdes provando que (a) `utm_source`, `utm_medium`, `utm_campaign` e `utm_content` válidos são anexados ao `page_location` do primeiro envio medido e não aparecem nos seguintes; (b) valor fora de `^[a-z0-9._-]{1,64}$` descarta só aquele parâmetro; (c) campanha capturada antes do aceite sai no primeiro envio depois do aceite, e nada sai antes; (d) `email`, `token` e `google_code` nunca chegam ao `page_location`, mesmo junto de UTM; (e) o módulo não grava em `localStorage` nem cria cookie. A asserção `not.toContain('?')` de `RouteTracker.test.tsx` continua valendo para URL sem UTM válido.
- **TASK-386**: `cd backend && php artisan test --filter GoogleAuthControllerTest` verde, com casos novos: conta criada no callback → a troca devolve `new_user: true`; usuário achado por `google_id` → `false`; achado por e-mail → `false`; código semeado só com o token (formato antigo) → `false`; a flag some junto com o código (segunda troca devolve 401). Os testes existentes passam sem alteração. `./vendor/bin/pint --test` limpo e o agent `security-reviewer` sem apontamento aberto.
- **TASK-387**: `RegisterPage.test.tsx` prova que a confirmação com sucesso chama `trackEvent('sign_up', { method: 'email' })` exatamente uma vez, antes do `navigate`, e que código inválido, erro de rede, pré-cadastro e reenvio não chamam.
- **TASK-388**: `AcceptInvitePage.test.tsx` prova que `mode='invite'` com sucesso chama `trackEvent('sign_up', { method: 'invite' })` uma vez; falha da API não chama; `mode='reset'` com sucesso não chama.
- **TASK-389**: `LoginPage.test.tsx` prova que a troca do código com `new_user: true` chama `trackEvent('sign_up', { method: 'google' })` uma vez; `new_user: false` ou ausente não chama; falha na troca não chama; login por e-mail e senha não chama. `LoginResponse` ganha `new_user?: boolean` e `tsc` passa. Depende de TASK-386 no código integrado.
- **TASK-390**: `GroupForm.test.tsx` prova que criar com sucesso chama `trackEvent('group_created')`; editar com sucesso não chama; falha da API não chama.
- **TASK-391**: `ExpenseForm.test.tsx` prova que o sucesso do `POST /api/expenses` chama `trackEvent('expense_created')` antes do `navigate` e que a falha não chama.
- **TASK-392**: com `php -S localhost:4173 -t site/public` rodando, `curl -s http://localhost:4173/ | grep SCD_GA_ID` mostra `null`, e `curl -s -H 'Host: expense.novemax.com.br' http://localhost:4173/ | grep SCD_GA_ID` mostra `"G-RNQM4DT19G"`. A página abre no preview sem erro de console e o menu e o banner continuam funcionando. Só `header.php` e `helpers.php` mudam em `site/`.
- **TASK-393**: o diff de `site/src/legal/privacidade.php` reescreve o item 2 e o item 4 conforme plan §7 (ações medidas sem conteúdo, etiquetas de campanha, nenhum dado de conta, grupo, despesa ou pagamento enviado) sem contradizer a medição de que uma despesa foi criada. `bash site/tools/gerar-datas-legais.sh` roda sem erro e nenhuma data é editada à mão. O usuário aprova o texto antes do merge.
- **TASK-394**: `docs/analytics/README.md` existe com o dicionário dos três eventos, a convenção de UTM com o formato dos valores e um exemplo de nome de campanha, a definição de ativação, os limites conhecidos, o modelo de relatório por canal e o checklist do GA4 do plan §8. `docs/sdd/05-context-frontend.md` aponta para ele em "Antes de codar", e os links relativos dos dois documentos resolvem. O usuário aprova o conteúdo antes do merge.
- **TASK-395**: com o app em `npm run dev` e um ID fictício (`VITE_GA_MEASUREMENT_ID=G-TESTE0000`, nunca o de produção), inspecionando `window.dataLayer`: cada um dos três cadastros, `group_created` e `expense_created` aparecem uma vez no sucesso e não aparecem na falha da API nem ao recarregar; recusa e revogação não enfileiram nada; aceitar → revogar → aceitar volta a enviar; a rota `/aceitar-convite?email=…&token=…` não mostra e-mail nem token em nenhum item do `dataLayer`; um link com `?utm_source=…&utm_medium=…&utm_campaign=…` aparece só no primeiro envio, também quando o aceite vem depois da chegada. Resultado registrado no `implementation.md` com comando e saída reais.
- **TASK-396**: depois do deploy, o usuário confere no GA4 (DebugView e Tempo real) cada cenário do plan §9 e o resultado fica no `implementation.md`: eventos vistos e não vistos conforme o esperado; a campanha chega lida de `page_location` (ou a alternativa `campaign_*` do plan §3 foi aplicada); nenhum hit, nem os automáticos, leva e-mail ou token. O checklist do `docs/analytics/README.md` fica concluído (eventos-chave `sign_up` e `expense_created`, dimensão personalizada `method`, filtro de tráfego interno, redação de dados, exploração de funil). Esta task só termina depois do deploy, então o PR da feature abre sem ela.

## Ordem de execução

Segue o plan §10:

1. TASK-383 primeiro: os eventos novos passam pelo gate.
2. TASK-384 em seguida: TASK-385 e as de disparo (387, 388, 390, 391) usam o `trackEvent` e o montador de `page_location`.
3. TASK-386 antes de TASK-389. As demais de disparo não dependem dela.
4. TASK-392 e TASK-393 são independentes do resto e entre si; entram quando for conveniente.
5. TASK-394 depois dos eventos, para documentar o que de fato ficou implementado.
6. TASK-395 depois de todas as tasks de código. TASK-396 por último, depois do deploy.
