# Manual do usuário e site descrevem a entrada no grupo como imediata

ID: 136
Origem: feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/` (TASK-581, varredura das docs vivas que citam a adição direta de membro, `plan.md` §13)
Criado em: 2026-11-05
Prioridade: MEDIA
Status: Concluído (PR #267)

## Descrição
Com a feature do convite pendente, quem é convidado só entra no grupo ao aceitar (**Aceitar** em Meus Grupos), e o botão de Participantes passou de "Adicionar" a "Convidar". Quatro textos para o usuário final ainda descrevem a entrada como imediata. Eles **não foram editados** na TASK-581: são conteúdo público (o site é publicado por FTP pelo `deploy-site.yml`) e o manual tem uma fonte, uma cópia em texto e um PDF gerado que precisam dizer a mesma coisa (`docs/manual/README.md`).

| Onde | Linha | O que diz hoje |
|---|---|---|
| `site/public/manual.php` | 79 a 81 | "adicione participantes pelo e-mail. Quem já tem conta passa a ver o grupo **na hora**. Quem ainda não tem recebe um e-mail com um link para definir a própria senha, e **entra direto no grupo**." |
| `site/public/recursos.php` | 23 | "Quem ainda não tem conta recebe um link para definir a própria senha e **já entra no grupo**." |
| `docs/manual/manual.html` | 165 e 244 | "ao ser **adicionado** a um grupo, você recebe um e-mail…" e "em **Participantes**, **adicione** as pessoas por e-mail." |
| `MANUAL.md` | 31 a 32 e 129 | os mesmos dois trechos do `manual.html` |

Além dos textos, o `MANUAL.pdf` (raiz) é gerado do `manual.html` por `docs/manual/gerar-pdf.ps1` (Chrome headless) e teria de ser regerado e versionado. O manual também não fala de **Convites recebidos** (Aceitar e Recusar, em Meus Grupos) nem de **Convites pendentes** (Cancelar, em Participantes), que são telas novas.

## Por que importa
No dia em que a feature for ao ar, o site e o manual passam a prometer uma entrada imediata que o sistema não faz mais, e quem convida espera ver a pessoa na lista de membros na hora.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quando corrigir | A) uma task nova (TASK-582) na própria feature do convite, antes do PR único<br>B) item à parte, depois do merge | **A** | O texto público fica errado no mesmo deploy da feature, e há precedente de o manual mudar dentro da feature (TASK-543, TASK-457 e TASK-458). Mexer no `tasks.md` aprovado e no site público é decisão do usuário. |
| D2 | Alcance | A) só corrigir os quatro trechos<br>B) corrigir e acrescentar um parágrafo sobre convites recebidos e pendentes | **B** | A tela nova (Aceitar e Recusar) é o primeiro contato de quem foi convidado, e o manual é onde essa pessoa procura. |

**Decidido por ele em 2026-11-05 ("siga com a 582"): D1 = A, D2 = B.** Vira a TASK-582 da feature `docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/`.

Relacionado: 133 (convite pendente), 135.

Tipo sugerido: doc (com regeração do `MANUAL.pdf`).

## Resolução
Concluído em: 2026-11-09
Feature: docs/feature/concluidas/202611/20261103-convite-pendente-no-grupo/
Tasks: TASK-582
PRs: https://github.com/isacaguiar/expense/pull/267

Decisões do usuário em 2026-11-05: D1-A (task nova na própria feature, antes do PR) e D2-B (corrigir e acrescentar o que faltava sobre convites recebidos e pendentes). `MANUAL.md`, `docs/manual/manual.html`, `site/public/manual.php` e `site/public/recursos.php` passam a descrever convidar e aceitar, e o `MANUAL.pdf` foi regerado por `docs/manual/gerar-pdf.ps1` (7 páginas). O site só sai no merge em `main` (`deploy-site.yml`).
