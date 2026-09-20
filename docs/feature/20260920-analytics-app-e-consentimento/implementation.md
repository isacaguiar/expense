# Implementation — Analytics no app e consentimento de cookies

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260920

---

## 1. Desvios do fluxo padrão (se houver)

Sem desvio de fluxo. Duas particularidades de verificação, por causa da natureza da feature:

- **O site não tem suíte automatizada.** `pint`/`phpunit` são do backend e `tsc`/`vitest` são do app; as tasks do site (309 a 312, 320) são verificadas por `php -l`, execução real no servidor local (`site-static` em `.claude/launch.json`, `localhost:4173`) e inspeção de rede/cookies no navegador.
- **"Nenhuma requisição ao Google" é critério de rede, não de código.** Onde a task exige isso, a prova registrada abaixo é a leitura de `read_network_requests` e de `document.cookie`, não a leitura do fonte.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-309 | Concluída | 2026-09-20 | Claude Opus 5 | `php -l site/src/config.php` → "No syntax errors detected"; `php -r '$c = require "site/src/config.php"; echo $c["ga_measurement_id"];'` → `G-RNQM4DT19G`; `grep -n ga_measurement_id site/src/config.php` → linha 33 | Chave posicionada ao lado de `site_url`, com comentário cruzado apontando para `VITE_GA_MEASUREMENT_ID` no app (plan §0: o contrato é duplicado por não haver código compartilhado). Comportamento do site inalterado nesta task — o `header.php` ainda carrega o gtag como antes, é a TASK-310 que troca. |
| TASK-310 | Concluída | 2026-09-20 | Claude Opus 5 | `php -l site/src/templates/header.php` → "No syntax errors detected"; `grep -c G-RNQM4DT19G site/src/templates/header.php` → `0`. No servidor local (`site-static`, `localhost:4173`), com os cookies do domínio zerados e recarregando: `document.cookie` → `(vazio)` (nenhum `_ga*` criado); `read_network_requests` → 14 requisições, **todas** para `localhost:4173`, nenhuma para `googletagmanager.com`; `window.dataLayer` → uma única entrada, `consent default` com os quatro sinais `denied`. Repetido em `/precos.php`: mesmo estado, `typeof window.gtag` → `function`, console sem erros. | Dois achados durante a verificação: (1) havia cookies `_ga`/`_ga_RNQM4DT19G` **pré-existentes** em `localhost:4173`, resíduo de execução local anterior com o header antigo — foi preciso zerá-los para provar que nada novo é criado; (2) o bloco antigo ficava **antes** do `<meta charset>`, e a reescrita passou o charset para a primeira linha do `<head>`, onde ele deve estar. O ID vai para o JS por `json_encode` (contexto JavaScript), não por `e()` (contexto HTML). |
