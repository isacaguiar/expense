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
