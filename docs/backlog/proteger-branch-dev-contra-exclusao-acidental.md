# Nada impede apagar a branch `dev` (ou a `main`) no GitHub: a `dev` sumiu depois do merge do #276

ID: 152
Origem: PR #277 (feature `docs/feature/concluidas/202611/20261112-ajustes-de-texto-participantes-e-pagamentos/`); a `dev` foi apagada no GitHub em 2026-11-12 às 16:17 UTC (evento `DeleteEvent` no usuário dele), uns 70 minutos depois do merge do #276
Criado em: 2026-11-13
Prioridade: MÉDIA
Status: Aberto

## Descrição
O PR de promoção #276 (`dev` para `main`) tinha a própria `dev` como branch de origem. Depois do merge, o GitHub oferece "Delete branch", e a `dev` foi apagada (a opção automática `delete_branch_on_merge` do repositório está `false`, então foi um clique manual, o mesmo que se faz nos PRs de feature e de arquivamento). Nada no repositório impede isso: não há regra de proteção em `dev` nem em `main`.

Efeito visto: ao empurrar a branch da feature seguinte, o `gh pr create --base dev` falhou ("No commits between dev and ..."), e o `git fetch` sem `--prune` ainda mostrava um `origin/dev` velho, o que escondeu o problema por um tempo. A `dev` foi recriada a partir da `main` (`5f17e436b1`, árvore idêntica ao último tip), com a autorização dele, e o #277 abriu normalmente.

## Por que importa
Desta vez nada se perdeu, porque a `main` já continha tudo o que a `dev` tinha. Mas se a `dev` for apagada com commits ainda não promovidos, o trabalho só sobra no histórico dos PRs mergeados e no reflog de quem tiver clonado. A `main` apagada seria pior: os 3 deploys de produção dependem dela. O repositório é público, então uma regra de proteção de branch não tem custo.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como evitar | A) regra de proteção (ruleset) no GitHub que bloqueia a exclusão (e o force push) de `dev` e `main`<br>B) só convenção: não clicar em "Delete branch" nos PRs de promoção<br>C) abrir a promoção a partir de uma branch descartável (`release/...`) em vez da própria `dev` | **A** | Custa uma configuração só e vale para sempre; B depende de lembrar, e foi um hábito legítimo (apagar a branch depois do merge) que causou isto; C muda o fluxo da ADR-011 e dobra os PRs de promoção. Configuração de repositório é gate dele: eu não altero |

Tipo sugerido: configuração do repositório, sem código. Se ele aceitar a A, basta uma linha em `docs/sdd/decisions/ADR-011-convencao-de-release.md` (ou no `README.md` do SDD) dizendo que `dev` e `main` são protegidas contra exclusão.
