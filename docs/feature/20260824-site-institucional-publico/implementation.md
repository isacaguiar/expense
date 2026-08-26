# Implementation — Site Institucional Público

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260824

---

## 1. Desvios do fluxo padrão

`/site` não é código Laravel nem React — não há `pint`/`phpunit`/`tsc` aplicável. Validação foi feita
servindo os arquivos com o servidor embutido do PHP e inspecionando via accessibility tree, log do
servidor e console do navegador (ver log abaixo). Configuração `site-static` em
`.claude/launch.json` só para permitir essa checagem local — não é infraestrutura de deploy.

Revisão 1.1 (migração para PHP): o Browser pane não estava aberto do lado do usuário nesta sessão,
então não foi possível tirar screenshot da landing page renderizada — a verificação da revisão 1.1
foi 100% via `read_page` (accessibility tree, confirma todo o texto/estrutura), `read_console_messages`
(sem erros JS) e `preview_logs` (todas as respostas HTTP 200, sem warning/notice do PHP). Recomenda-se
uma checagem visual (screenshot) quando o Browser pane estiver disponível, antes de considerar o
visual definitivamente aprovado.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 2026-08-24 | isacaguiar | `mkdir -p site/assets`; `cp frontend/src/assets/images/logo-expense.png site/assets/logo-expense.png`; criado `site/assets/style.css`. | Logo reaproveitada do frontend por decisão do usuário. |
| TASK-002 | Concluída | 2026-08-24 | isacaguiar | Criado `site/index.html`. Servido via `python -m http.server 4173 --directory site` e verificado no browser: logo centralizada, sem outro conteúdo, sem erros no console (`read_console_messages` vazio). | Screenshot confirmou renderização. |
| TASK-003 | Concluída | 2026-08-24 | isacaguiar | Criado `site/privacidade.html`. Verificado em `http://localhost:4173/privacidade.html`: texto completo renderizado, sem erros no console. | Contato `novemax@gmail.com` conforme confirmado com o usuário. |
| TASK-004 | Concluída | 2026-08-24 | isacaguiar | Criado `site/termos.html`. Verificado em `http://localhost:4173/termos.html`: texto completo renderizado, link cruzado para `privacidade.html`, link `← Voltar` clicado e confirmado que navega de volta para `index.html`. Servidor local parado ao final (`preview_stop`). | — |
| TASK-005 | Concluída | 2026-08-24 | isacaguiar | Criado `site/public/` (document root) e `site/src/` (config.php, helpers.php, templates/header,nav,footer,logo.php). `php -l` em todos os `.php` sem erro de sintaxe. | `site/src/` fica fora do document root do `php -S`, não é acessível por URL. |
| TASK-006 | Concluída | 2026-08-24 | isacaguiar | Criado `site/public/index.php` a partir do mockup `assets/images/site.png`. Servidor: `php -S localhost:4173 -t site/public` (via `.claude/launch.json` → `site-static`). `preview_logs` mostrou só `[200]` em `/`, `/assets/style.css`, `/assets/favicon.png` (sem warning/notice PHP). `read_console_messages` sem erros. `read_page` confirmou as 5 seções e todo o texto/dados do mockup (hero, cartão de dashboard com os 3 gastos e 4 saldos, grid de 6 recursos, 3 passos, faixa final de CTA). | Avatares do mockup viraram iniciais coloridas (não fotos). Screenshot visual não pôde ser tirado nesta sessão (Browser pane não estava aberto do lado do usuário) — verificação foi via accessibility tree (`read_page`) + rede + console, não visual. |
| TASK-007 | Concluída | 2026-08-24 | isacaguiar | Criado `site/public/privacidade.php` reaproveitando `header.php`/`nav.php`/`footer.php`. `read_page` em `http://localhost:4173/privacidade.php` confirmou as 8 seções, o link de contato e o `← Voltar` para `index.php`. | Mesmo texto legal da v1.0, sem alteração de conteúdo. |
| TASK-008 | Concluída | 2026-08-24 | isacaguiar | Criado `site/public/termos.php` na mesma estrutura. Navegado em `http://localhost:4173/termos.php`, `read_console_messages` sem erros. | Mesmo texto legal da v1.0, sem alteração de conteúdo. |
| TASK-009 | Concluída | 2026-08-24 | isacaguiar | `rm site/index.html site/privacidade.php site/termos.php site/assets/logo-expense.png site/assets/style.css` (arquivos soltos da v1.0 na raiz de `site/`, fora de `public/`/`src/`) + `rmdir site/assets`. Atualizado `.claude/launch.json` → `site-static` de `python -m http.server` para `php -S localhost:4173 -t site/public`. | — |
| TASK-010 | Concluída | 2026-08-24 | isacaguiar | A pedido do usuário, instalado o plugin `impeccable@impeccable` (`claude plugin marketplace add pbakaus/impeccable` + `claude plugin install impeccable@impeccable`, v4.1.1, escopo user) e, após reinício de sessão para carregar o skill, rodado `impeccable polish` em `site/public/index.php`. `node .../scripts/context.mjs --target site/public/index.php` (sem PRODUCT.md/DESIGN.md — ok pra refinamento estreito, código incumbente é a autoridade visual). Achados reais corrigidos em `site/public/assets/style.css`: (1) contraste do badge "Pendente" ~1.98:1 e do badge "Paga" ~3.85:1 (fundos pastel + texto claro, abaixo do mínimo AA de 4.5:1) — `--orange` `#ee9d1f→#92400e` e `--green-dark` `#0e8a5d→#0a7a4f`; (2) valores "A pagar" em `--red` `#dc4a3a` (~4.13:1) → `#c0392b` (~5.44:1). Contraste recalculado via função de luminância relativa rodada no console do navegador (`javascript_tool`): pending 6.35:1, paid 4.74:1, valor "a pagar" 5.44:1 — todos ≥4.5:1. Também adicionados: `:focus-visible` (outline visível em todo elemento interativo, hoje ausente), `::selection` e scrollbar temáticos (Firefox `scrollbar-color` + `::-webkit-scrollbar-*`), `font-variant-numeric: tabular-nums` nos valores monetários, hover com leve elevação nos `feature-card`, e uma única animação de entrada (fade+translateY, `@media (prefers-reduced-motion: no-preference)`) nos elementos do hero — não replicada em outras seções, conforme a diretriz do skill de "um momento autoral, não efeitos espalhados". `node .../scripts/detect.mjs --json site/public/index.php site/public/assets/style.css` → `[]` (nenhum achado mecânico). Reverificado responsivo em 375px: `scrollWidth === clientWidth` (sem overflow horizontal), nav e sidebar do dashboard corretamente ocultos pelas media queries. | Scan via URL (Puppeteer, mais completo) não rodou — pacote `puppeteer` não está instalado e instalá-lo não foi pedido; o scan estático (`--json <arquivos>`) cobre HTML/CSS ligado. Sem screenshot visual nesta tarefa também (Browser pane seguiu indisponível do lado do usuário) — verificação foi via accessibility tree, `getComputedStyle`/contraste calculado em runtime, console, rede e o detector mecânico do próprio plugin. |
