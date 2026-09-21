# Implementation — Data de atualização por documento legal

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260920

---

## 1. Desvios do fluxo padrão (se houver)

Sem desvio de fluxo. Uma particularidade de verificação:

- **O site não tem suíte automatizada**, então as tasks são verificadas por `php -l`, execução real (`php -r` para o helper, servidor `site-static` em `localhost:4173` para as páginas) e, nas extrações de conteúdo, por `diff` do HTML renderizado antes e depois.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-321 | Concluída | 2026-09-20 | Claude Opus 5 | `php -l site/src/helpers.php` → "No syntax errors detected". **(1) Fallback**: com `legal-dates.php` ausente, `legal_updated_at('privacidade')` → `20 de setembro de 2026` e `('termos')` → `20 de setembro de 2026`, com o relógio local em `2026-09-20 22:59`. **(2) Precedência**: com um `legal-dates.php` de teste (`privacidade => 2026-03-05`, `termos => 2025-12-31`), as chamadas devolvem `5 de março de 2026` e `31 de dezembro de 2025` — o artefato ganha do `filemtime`. **(3) Sem `intl`**: `php -m \| grep -c -i '^intl$'` → `0`, e nada falha. **(4) Documento inexistente** devolve `""` em vez de erro. | **Bug pego na verificação, corrigido dentro da task**: a primeira versão usava `date('Y-m-d', filemtime(...))` e devolveu `21 de setembro` com o relógio local em `20/09 22:58`. Causa: `ini_get('date.timezone')` está **vazio** neste projeto, então o PHP assume UTC — às 22h locais já é o dia seguinte em UTC. Passou a converter com `DateTimeImmutable` + `DateTimeZone(FUSO_DO_PROJETO)`, cumprindo o specify §2.4. A constante `FUSO_DO_PROJETO` foi criada no mesmo arquivo para o gerador da TASK-325 usar o mesmo valor. O fallback aceita o arquivo de conteúdo **ou** a própria página, porque `site/src/legal/` só passa a existir na TASK-323 — sem isso esta task não seria verificável sozinha. |
| TASK-322 | Concluída | 2026-09-20 | Claude Opus 5 | `php -l` limpo nas duas páginas. `grep -c "config['updated_at']" site/public/privacidade.php site/public/termos.php` → `0` nas duas. Renderizado em `localhost:4173`: `/privacidade.php` e `/termos.php` exibem "Última atualização: 20 de setembro de 2026", e a contagem de `Warning:|Notice:|Fatal|Undefined` no HTML é `0` em ambas. | **Critério da task corrigido durante a execução** (registrado em `tasks.md`): a redação original pedia `grep -c "updated_at"` igual a `0`, impossível de cumprir porque o nome do próprio helper — `legal_updated_at` — contém a string. O que importa é não restar leitura de `$config['updated_at']`. A mesma correção foi aplicada ao critério da TASK-327, que tinha o defeito idêntico. As duas páginas ainda mostram a mesma data porque ambas caem no `filemtime` das próprias páginas — a diferenciação real chega com a extração do conteúdo (TASK-323/324) e com o artefato do deploy (TASK-325/326). |
