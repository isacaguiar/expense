# ADR-009: Arquivar feature/bugfix concluídos em `concluidas`/`concluidos/<AAAAMM>/`

Status: Aceita
Data: 2026-09-03

## Contexto

`docs/feature/` cresceu para 51 pastas `<AAAAMMDD>-<slug>/` num diretório flat, sem índice e sem
etapa de arquivamento. Feature concluída ficava lado a lado com feature em andamento, e a única
forma de saber o estado de uma era abrir o `tasks.md`/`implementation.md` dela — não há marcador
no nível da pasta. À medida que o projeto avança, `docs/feature/` só cresce.

`docs/bugfix/` já move o concluído para uma subpasta `concluidos/` (`ADR-004` §2), mas flat —
então essa pasta tem o mesmo problema a médio prazo. `docs/backlog/` segue o mesmo padrão flat
(`concluidos/`), fora do escopo desta decisão.

## Decisão

**1. Feature.** Quando o PR único da feature é mergeado em `dev` — o gate que já encerra a feature
(`ADR-003`, `04-implementation.md` §1.7) — a pasta `docs/feature/<AAAAMMDD>-<slug>/` é movida para
`docs/feature/concluidas/<AAAAMM>/<AAAAMMDD>-<slug>/`.

**2. Bugfix.** No Fechamento (`docs/bugfix/README.md`), o arquivo do bug vai para
`docs/bugfix/concluidos/<AAAAMM>/<AAAAMMDD>-<slug>.md` — a subpasta `concluidos/` do `ADR-004`
ganha um nível por mês.

**3. `<AAAAMM>` = mês de criação.** São os 6 primeiros dígitos do nome da pasta/arquivo
(`substr(nome, 0, 6)`), não o mês de conclusão. Sem lookup de data, sem campo novo. Consequência
assumida: `concluidas/202608/` pode conter uma feature finalizada em setembro.

**4. Estado é posicional, sem índice.** `docs/feature/` e `docs/bugfix/` na raiz passam a conter
só trabalho em andamento. "Concluída/concluído" é a própria localização — **não** se cria
`docs/feature/README.md`.

**5. Branch inalterada.** A branch da feature continua `<tipo>/<AAAAMMDD>-<slug>` (nome derivado no
momento da criação; o move é pós-merge, quando a branch já foi integrada). `ADR-003` segue intacto.

**6. Backlog fora do escopo.** `docs/backlog/concluidos/` continua flat. Só os ponteiros dele para
pastas de feature movidas foram atualizados para o caminho arquivado.

**7. Migração retroativa (nesta ADR).** 48 pastas de feature com PR mergeado em `dev` foram movidas
para `docs/feature/concluidas/202608|202609/`; os 6 arquivos já em `docs/bugfix/concluidos/` foram
redistribuídos em `202608/` e `202609/`. 3 pastas de feature sem PR de feature em `dev`
(`20260817-migracao-frontend-expo` — épico com log vazio; `20260818-tipos-despesa` — `specify.md`
ainda é template; `20260821-login-social-google` — spec parada, item de backlog aberto) ficam em
`docs/feature/`. Todos os links cruzados nos docs SDD, ADRs, `docs/backlog/` e comentários de
`backend/`/`frontend/` foram reescritos para o caminho novo.

## Consequências

- `docs/feature/` na raiz vira uma lista curta do que está aberto; o custo de navegar cai.
- "Concluída" ganha uma definição única e verificável (PR único mergeado em `dev`), antes só
  implícita no log de cada pasta.
- Referência a uma feature concluída passa a ter caminho mais longo
  (`concluidas/<AAAAMM>/<AAAAMMDD>-<slug>/`). ADRs e comentários de código existentes foram
  atualizados; os que surgirem depois já devem usar o caminho arquivado.
- Três formas de arquivamento coexistem: feature e bugfix por `<AAAAMM>/`, backlog ainda flat.
  Alinhar backlog é um passo futuro possível, não obrigatório.
- Ganham o passo do move: `docs/bugfix/README.md` §Fechamento, e as skills `/nova-feature`,
  `/novo-bug`, `/promover-backlog`.
- `00-constitution.md` §5.1.1 (nova) e §5.1.2 registram a regra — a edição da Constitution é
  gate humano (§5.2).

## Alternativas consideradas

- **Bucket por mês de conclusão.** Mais intuitivo (`concluidas/202609/` = finalizadas em setembro),
  mas exige registrar a data de conclusão na feature (hoje não existe) e um lookup por pasta na
  migração. Descartada pelo custo e pela ambiguidade que introduz na migração retroativa.
- **Criar `docs/feature/README.md` com tabelas "Em andamento"/"Concluídas"** (como `bugfix` e
  `backlog`). Tornaria o estado explícito sem depender da localização, mas adiciona um índice para
  manter em sincronia a cada PR. Descartada — a localização já carrega o estado; menos peças móveis.
- **Não arquivar, só criar o índice.** Deixa `docs/feature/` crescendo sem limite — o problema de
  origem.
- **Alinhar `docs/backlog/concluidos/` ao mesmo `<AAAAMM>/` já nesta ADR.** Aumenta o raio de
  mudança sem pedido concreto; fica como follow-up.

## Referências

- `docs/sdd/00-constitution.md` §5.1.1 (arquivamento de feature), §5.1.2 (BFF)
- `docs/bugfix/README.md` §Fechamento
- `ADR-002-sdd-por-feature.md` (emenda 2026-09-03), `ADR-003-fluxo-branch-por-feature.md`,
  `ADR-004-fluxo-bugfix.md` (emenda 2026-09-03)
- `docs/sdd/04-implementation.md` §1; `docs/sdd/README.md` (Trabalho por feature, Correção de bug)
- `.claude/skills/nova-feature/SKILL.md`, `.claude/skills/novo-bug/SKILL.md`,
  `.claude/skills/promover-backlog/SKILL.md`
- Branch/PR desta mudança: `chore/arquivar-concluidos-anomes`
