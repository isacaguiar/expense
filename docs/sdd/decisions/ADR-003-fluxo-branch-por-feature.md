# ADR-003: Fluxo de branch por feature (branch principal + tasks mergeadas nela), não mais task → `dev` direto

Status: Aceita
Data: 2026-08-18

## Contexto

Desde 2026-08-17 (`00-constitution.md` §5.1, `04-implementation.md` §1), cada task nascia de `dev` atualizada, numa branch própria (`<tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx`), e abria seu próprio PR contra `dev` — merge em `dev` era gate humano por task.

Esse fluxo acabou de rodar por inteiro na feature `docs/feature/20260818-fluxo-despesas-grupo/`: 6 tasks (TASK-033 a TASK-038), 6 branches, 6 PRs, 6 merges em `dev` para entregar uma única feature coesa. O dono do projeto avaliou isso como fragmentado demais — "não está legal um commit por task" — e pediu um modelo de duas camadas: uma branch principal por feature que acumula as tasks, e só ela conversa com `dev`/`main`.

## Decisão

A partir desta data, o fluxo de branch passa a ter duas camadas:

1. **Branch da feature**: `<tipo>/<AAAAMMDD>-<slug-da-feature>` (mesmo nome da pasta em `docs/feature/`, sem sufixo de task), criada a partir de `dev` atualizada quando a primeira task da feature começa. A primeira task é implementada **direto nela** — não ganha sub-branch própria.
2. **Branch de task** (a partir da segunda task da feature): `<tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx`, criada a partir da branch da feature atualizada (não de `dev`). O checklist pré-merge (pint/phpunit/tsc, critério de aceite verificado, log em `implementation.md`) continua valendo por task, sem mudança.
3. **Merge task → branch da feature**: local, `--no-ff`, **sem PR e sem gate humano** — a branch da feature ainda não é `dev`/`main`, não é "estado compartilhado/produção" no sentido das regras de segurança do projeto, e pode ser reescrita/rebasada livremente antes de chegar em `dev`. Depois do merge, a branch da feature é empurrada pro remoto e a branch de task é descartada.
4. **Checklist de integração**: antes de abrir o PR feature→`dev`, rodar o checklist (pint/phpunit/tsc) de novo na branch da feature já com todas as tasks integradas — pega problema de integração entre tasks que o checklist individual, por task, não pegaria.
5. **PR feature → `dev`**: um único PR, referenciando a feature e as tasks incluídas. Merge continua sendo **gate humano** — só que agora é um gate por feature, não por task.
6. **Promoção `dev` → `main`**: inalterada — PR de promoção separado, gate humano, como já era.

Escopo: vale a partir de agora, para a próxima feature nova. A feature `docs/feature/20260818-fluxo-despesas-grupo/` já foi concluída sob o fluxo antigo (`origin/dev` e `origin/main` já confirmados no mesmo commit) — não é retroagida.

## Consequências

- Histórico de `dev` ganha 1 PR por feature em vez de 1 por task — resolve a queixa de fragmentação.
- Granularidade por task não se perde: continua visível nos commits (`--no-ff` preserva um merge commit por task) dentro do PR único da feature.
- Menos pontos de checagem humana no meio da execução da feature — mitigado por todo o trabalho intermediário ficar pré-`dev` (fácil de desfazer/rebasear/corrigir na branch da feature antes do PR chegar em `dev`) e pelo checklist de integração antes do PR.
- `docs/sdd/00-constitution.md` §5.1 e a tabela de Governança, e `docs/sdd/04-implementation.md` §1, precisam refletir esse novo fluxo — feito nesta mesma mudança.

## Alternativas consideradas

- **Manter o modelo atual (task → PR → `dev` direto)**: rejeitada — é exatamente a fragmentação que motivou esta decisão.
- **Manter PR por task, mas contra a branch da feature em vez de `dev`**: rejeitada pelo dono do projeto — ele optou explicitamente por merge automático (sem gate/PR) no nível task→feature, reservando o gate humano só para o momento em que a feature inteira encontra `dev`.
- **Squash de todas as tasks num commit único ao final**: não adotada — merge `--no-ff` por task já preserva o histórico granular dentro da branch da feature sem precisar de squash, e squash perderia essa granularidade sem ganho adicional.

## Referências

- `docs/sdd/00-constitution.md` §5.1 e tabela de Governança (§5.2).
- `docs/sdd/04-implementation.md` §1.
- `docs/feature/20260818-fluxo-despesas-grupo/` — última feature a seguir o fluxo antigo (task → `dev` direto) por completo.
