# ADR-011: Convenção de release (tag de versão em `main`)

Status: Aceita
Data: 2026-10-24

## Contexto

Em 2026-10-24 o repositório não tinha nenhuma tag, local ou em `origin`. `main` é a branch de
produção e cada push nela dispara `deploy-backend.yml`, `deploy-frontend.yml` e `deploy-site.yml`
(`00-constitution.md` §5.1; `04-implementation.md` §1.8). O deploy do backend também aplica
migrations (`php artisan migrate --force`, `ADR-008`, emenda 2026-09-03).

Sem tag, não há como apontar "o que estava em produção" num dado momento nem um ponto de retorno
antes de uma promoção `dev` → `main`. Na data desta ADR, `dev` acumulava cerca de 160 commits e
106 arquivos ainda não promovidos, incluindo 2 migrations novas (aditivas): o ponto de retorno
passou a ser necessário antes dessa promoção, não depois.

Os merges de promoção anteriores (#200, #202, #204, #208, #216, #218) foram identificados só pelo
número do PR.

## Decisão

**1. Formato.** SemVer com prefixo `v`: `vMAJOR.MINOR.PATCH`. Uma única tag cobre API, web e site,
porque saem juntos de `main`. O prefixo `app-vX.Y.Z` fica **reservado** para o app Flutter (item
074 do backlog, `ADR-010`); a regra dele será decidida quando o app existir, não aqui.

**2. Bump.**

| Parte | Quando sobe |
|---|---|
| MINOR | Promoção `dev` → `main` com feature nova. |
| PATCH | Promoção que contém só correção de bug (BFF, `ADR-004`). |
| MAJOR | Quebra de contrato da API (`00-constitution.md` §4.1, `/api/v2`) ou migration destrutiva aprovada. |

**3. Quando tagar.** No merge commit de `main` do PR de promoção, **depois** do merge e do deploy
verde. O PR de promoção declara a versão proposta no título ou na descrição. A tag diz "isto está
em produção", não "isto pretendemos subir".

**4. Tag anotada.** Sempre `git tag -a`, com mensagem listando os PRs/features incluídos. Sem
assinatura GPG por ora.

**5. Notas de release no GitHub Release.** `gh release create vX.Y.Z --verify-tag
--generate-notes`. Não há `CHANGELOG.md`.

**6. Gate humano.** Criar e publicar tag de release em `main` exige aprovação humana
(`00-constitution.md` §5.2). A IA prepara os comandos e para antes de `git push`.

**7. Sem automação.** Nenhum workflow novo. Tag não dispara deploy (os workflows filtram por
branch `main`). Para retornar o **código** a um release, `workflow_dispatch` permite escolher o
ref da tag; migrations não são revertidas por isso.

**8. Hotfix segue o fluxo existente.** BFF → `dev` → promoção → PATCH. Não há atalho direto em
`main`.

**9. Baseline retroativa: `v1.0.0`.** Aponta para `01dcb406e7` (merge do PR #218, 2026-10-21,
estado de produção antes da primeira promoção sob esta convenção). É a única tag criada antes do
merge e do deploy que ela descreve. A próxima promoção deve ser `v1.1.0`, salvo se contiver
mudança de MAJOR.

## Consequências

- Cada promoção passa a ter um nome, um commit e notas. Rollback de código tem um ponto claro; o de
  banco continua manual.
- O PR de promoção ganha um item a mais: declarar a versão proposta.
- Um passo humano a mais no fim de cada promoção (`git tag` + `git push` + `gh release create`),
  descrito em `04-implementation.md` §1.9.
- `frontend/package.json` está em `1.0.0` e **não** acompanha as tags; a tag é a fonte da versão
  de release. Sincronizar os dois é possível no futuro, não é exigido.
- A regra normativa vive em `00-constitution.md` §5.1.1.2; as demais menções são ponteiros
  (`ADR-007`). A edição da Constitution é gate humano (§5.2).

## Alternativas consideradas

- **`CHANGELOG.md` na raiz.** Histórico dentro do repo, mas é um arquivo a mais para manter a cada
  promoção e uma segunda fonte além dos PRs. Descartada: o GitHub Release gera as notas dos
  títulos de PR sem custo.
- **Os dois (GitHub Release e `CHANGELOG.md`).** Redundância que vai contra a fonte única do
  `ADR-007`.
- **Tag por componente já agora** (`api-v…`, `web-v…`). API, web e site saem juntos de `main`; três
  linhas de versão não trariam informação. Só o app Flutter terá ciclo próprio, e por isso só o
  prefixo dele fica reservado.
- **CalVer ou tag por data** (`prod-2026-10-21`). Marca o dia, mas não diz se a promoção quebra
  contrato ou só corrige bug.
- **Tagar antes do merge** (a versão que se pretende lançar). Pode apontar para algo que nunca foi
  ao ar se o PR de promoção for fechado. `v1.0.0` é exceção declarada, pois descreve um estado que
  já estava em produção.
- **Tag criada por workflow.** Esconderia um gate humano dentro de automação e exigiria definir
  como o workflow decide o bump. Descartada por ora; reavaliar se o ritmo de promoções crescer.
- **Assinatura GPG das tags.** Cerimônia sem ganho num repositório de um mantenedor.
- **Tag autônoma após o merge.** Reduziria atrito, mas uma tag errada exige apagar tag pública.
  Descartada: o gate humano fica.

## Referências

- `docs/sdd/00-constitution.md` §5.1.1.2 (regra) e §5.2 (gate)
- `docs/sdd/04-implementation.md` §1.8 (promoção `dev` → `main`) e §1.9 (tag de release)
- `ADR-003-fluxo-branch-por-feature.md`, `ADR-004-fluxo-bugfix.md`, `ADR-007-fonte-unica-e-arquitetura-do-agente.md`,
  `ADR-008-deploy-backend-ssh-rsync.md`, `ADR-010-app-movel-em-flutter.md`
- Tag `v1.0.0` → `01dcb406e700f0d20a2ba5eedf831e22200459a4`
- Branch/PR desta mudança: `docs/20261024-convencao-de-release`
