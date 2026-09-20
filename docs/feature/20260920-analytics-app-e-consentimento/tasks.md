# Tasks — Analytics no app e consentimento de cookies

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260920

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-309 | Acrescentar `ga_measurement_id` ao `config.php` do site | frontend | plan.md §7 | nenhum | Pendente |
| TASK-310 | Trocar o gtag incondicional do `header.php` pelo bloco de Consent Mode v2 | frontend | plan.md §2, §7 | nenhum | Pendente |
| TASK-311 | Criar o banner de consentimento do site (`consent.php` + `consent.js` + estilo) | frontend | plan.md §1, §3 | nenhum | Pendente |
| TASK-312 | Acrescentar o botão "Preferências de cookies" ao rodapé do site | frontend | plan.md §3 | nenhum | Pendente |
| TASK-313 | Expor `GA_MEASUREMENT_ID` no `config.ts` do frontend com default vazio | frontend | plan.md §7 | nenhum | Pendente |
| TASK-314 | Injetar `VITE_GA_MEASUREMENT_ID` no build de produção do frontend | infra | plan.md §7 | nenhum | Pendente |
| TASK-315 | Criar o módulo de consentimento do app (`analytics/consent.ts`) | frontend | plan.md §1, §2, §4 | nenhum | Pendente |
| TASK-316 | Montar o `ConsentBanner` no `App.tsx`, fora do `RequireAuth` | frontend | plan.md §4 | nenhum | Pendente |
| TASK-317 | Acrescentar a revogação do consentimento ao `Profile` | frontend | plan.md §4 | nenhum | Pendente |
| TASK-318 | Escrever `sanitizePath()` com teste de query string e de segmento numérico | frontend | plan.md §6 | nenhum | Pendente |
| TASK-319 | Disparar `page_view` por rota com o `RouteTracker` | frontend | plan.md §5, §6 | nenhum | Pendente |
| TASK-320 | Descrever cookies, Google Analytics e revogação na Política de Privacidade | doc | plan.md §8 | nenhum | Pendente |

## Critérios de aceite

- **TASK-309**: `grep -n "ga_measurement_id" site/src/config.php` devolve a chave com `G-RNQM4DT19G`. Nada muda no comportamento ainda: a home continua respondendo 200 e continua carregando o gtag como hoje (é TASK-310 que troca isso).

- **TASK-310**: com o cookie `scd_consent` ausente, carregar qualquer página do site e verificar, por `read_network_requests`, **zero** requisição para `googletagmanager.com`; `document.cookie` sem nenhum `_ga*`; `window.dataLayer` contendo a entrada de `consent` `default` com `analytics_storage: 'denied'`. Além disso, `grep -c "G-RNQM4DT19G" site/src/templates/header.php` devolve `0` — o ID passa a vir do `config.php`.

- **TASK-311**: (a) sem o cookie, o banner aparece e traz os botões "Aceitar" e "Recusar"; (b) clicar em "Aceitar" grava `scd_consent=granted`, dispara a requisição a `gtag/js?id=G-RNQM4DT19G` **sem recarregar a página** e cria o cookie `_ga`; (c) com o cookie limpo, clicar em "Recusar" grava `scd_consent=denied` e mantém zero requisição ao Google e zero `_ga`; (d) recarregar depois de qualquer uma das duas escolhas não mostra o banner de novo; (e) com JavaScript desabilitado, nenhum banner e nenhuma medição — falha fechada.

- **TASK-312**: com `scd_consent=granted` já gravado, o botão "Preferências de cookies" aparece no rodapé de todas as 8 páginas; clicar reabre o banner; escolher "Recusar" sobrescreve o cookie para `denied`, e no próximo carregamento não há requisição ao Google. O bloco do logo Novemax no rodapé continua intacto.

- **TASK-313**: `cd frontend && npx tsc --noEmit` sem erro; `GA_MEASUREMENT_ID` exportado de `src/config.ts` com fallback `''`; `VITE_GA_MEASUREMENT_ID` documentado em `.env.example`; rodar `npm run dev` sem a variável definida não carrega nada do Google (verificado por `read_network_requests`).

- **TASK-314**: `grep -n "VITE_GA_MEASUREMENT_ID" .github/workflows/deploy-frontend.yml` mostra a variável no mesmo bloco `env:` do passo de build, ao lado de `VITE_API_BASE_URL`, e `cd frontend && VITE_GA_MEASUREMENT_ID=G-RNQM4DT19G npm run build` conclui sem erro.

  > **Critério corrigido em 2026-09-20, durante a execução.** A redação original exigia aqui que `grep -rl "G-RNQM4DT19G" dist/` devolvesse um arquivo. Isso é impossível nesta task: enquanto nenhum módulo importar `GA_MEASUREMENT_ID`, o Rollup remove o export por tree-shaking e o valor nunca chega ao bundle. Verificado na prática — o default `localhost:8000` de `API_BASE_URL`, que `src/api.ts` consome, aparece no `dist/`; o ID do GA, sem consumidor, não. A prova de bundle passou para a TASK-315, que é quem introduz o consumidor.

- **TASK-315**: `npx tsc --noEmit` limpo. No dev server, com o cookie ausente: `window.dataLayer` traz o `consent default` negado e `document.scripts` não tem nenhum script com `google` no `src`. Chamando a função de aceite pelo console, o script do `gtag/js` entra no DOM na hora e o cookie `_ga` passa a existir. Gravar `scd_consent=denied` à mão e recarregar mantém zero script do Google. **Herdado da TASK-314**: `cd frontend && VITE_GA_MEASUREMENT_ID=G-RNQM4DT19G npm run build && grep -rl "G-RNQM4DT19G" dist/` passa a devolver pelo menos um arquivo, porque agora existe um consumidor da constante.

- **TASK-316**: com o cookie ausente, o banner aparece em `/app/` (login), `/app/cadastro` e `/app/aceitar-convite` — as três rotas públicas, fora do `RequireAuth`. Aceitar faz o banner sumir; recarregar não traz de volta. O banner não impede clicar nos campos de login por baixo (não é modal).

- **TASK-317**: a tela `Profile` exibe a entrada de preferências de cookies; clicar reabre o banner com a escolha atual visível; trocar a escolha sobrescreve `scd_consent` e o efeito vale no próximo carregamento.

- **TASK-318**: `cd frontend && npm test` verde, com casos cobrindo: `/groups/42/expenses/1337` → `/groups/:id/expenses/:id`; `/aceitar-convite` com `?email=a@b.com&token=xyz` → `/aceitar-convite` (query string inteira descartada); `/meus-grupos` → `/meus-grupos` (inalterado); hash descartado. O teste falha se alguém reintroduzir a query string.

- **TASK-319**: com o consentimento concedido, navegar login → `/meus-grupos` → uma despesa de grupo gera exatamente três `page_view` em `window.dataLayer`, com `page_path` `/app/`, `/app/meus-grupos` e `/app/groups/:id/expenses/:id` — nenhum ID real. Nenhum `page_view` duplicado no primeiro carregamento, o que prova o `send_page_view: false`. Nenhum evento com `page_location` contendo `?`.

- **TASK-320**: `grep -i "cookie\|analytics" site/public/privacidade.php` devolve conteúdo nas seções 2, 4 e 6; o texto nomeia o Google Analytics, declara o consentimento como base legal e explica como revogar pelo rodapé do site e pelo `Profile` do app. A página continua respondendo 200 e o `updated_at` compartilhado (item 051) não é alterado nesta task.
