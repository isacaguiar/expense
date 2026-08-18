---
description: Recebe o ID de um item de docs/backlog/ e conduz o processo completo até virar código — Specify → Tech Plan → Tasks → execução de cada task — pedindo aprovação humana explícita entre cada etapa.
---

O usuário passou como argumento o ID numérico de um item do backlog (ex.: `001`): `$ARGUMENTS`

Este comando promove um item de `docs/backlog/` (achado que não bloqueava nenhuma task quando foi registrado) para uma feature completa do SDD, seguindo o mesmo espírito do `/nova-feature`, mas partindo de um item já existente e indo até a execução — com um gate de revisão humana entre cada etapa. **Nunca avance de etapa sem uma aprovação explícita do usuário na conversa** (ex. "aprovado", "pode seguir", "ajusta X e segue") — silêncio ou a ausência de objeção não é aprovação.

## 0. Localizar o item

1. Procure o ID em `docs/backlog/README.md` (tabela "Índice"). Se não existir, pare e avise — não invente um item.
2. Leia o arquivo correspondente (`docs/backlog/<arquivo>.md`): `Origem`, `Descrição`, `Por que importa`, `Tipo sugerido`, `Prioridade`, `Status`. Se `Status` já for "Promovido para TASK-0xx", avise o usuário e pergunte se é intencional promover de novo (pode ser um retrabalho) antes de continuar.
3. Confirme com o usuário que este item deve virar feature **agora** — isso é uma decisão consciente (regra "Quando não houver task aplicável" do `CLAUDE.md` raiz não se aplica automaticamente só por existir um ID). Pergunte também: nome/slug da feature, e se o escopo é só este item do backlog ou se deve agrupar com outro(s) item(ns) relacionados.

## 1. Scaffold

Igual ao `/nova-feature`: derive o slug final (kebab-case, sem acentos), calcule a data de hoje (`AAAAMMDD`), confirme que `docs/feature/<AAAAMMDD>-<slug>/` ainda não existe, e copie os 4 templates de `docs/sdd/templates/` para dentro da pasta.

## 2. Etapa Specify

Preencha `specify.md`:
- **§1 Problema**: a partir do campo `Por que importa` do item de backlog, mais qualquer contexto adicional relevante já discutido com o usuário.
- **§2 Achados/Requisitos**: a partir da `Descrição` do item — se o item já cita arquivo:linha, reaproveite; se a promoção revelar mais detalhe, verifique no código real antes de escrever (nunca documente achado técnico sem ler o código correspondente).
- **§3 Fora de escopo**: pelo menos o que ficou de fora da decisão do passo 0.3 (ex.: outros itens do backlog que não entraram no agrupamento).

Apresente o conteúdo ao usuário e **pare, pedindo aprovação explícita**. Se pedir ajuste, aplique e peça aprovação de novo. Só prossiga para a etapa seguinte após aprovação clara.

## 3. Etapa Tech Plan

Só depois do `specify.md` aprovado. Preencha `plan.md`, traduzindo cada item do `specify.md` §2 em decisão técnica (arquivos afetados, abordagem, por que essa abordagem e não outra) — mesmo formato de `docs/sdd/02-plan.md`. Apresente e peça aprovação explícita, mesma regra do passo 2.

## 4. Etapa Tasks

Só depois do `plan.md` aprovado. Preencha `tasks.md`:
- Tasks atômicas (regra de `docs/sdd/03-tasks.md`: "se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"), cada uma apontando para uma seção do `plan.md`.
- IDs novos sequenciais — antes de numerar, confira o maior `TASK-0xx` já usado em **todo** o projeto (`docs/sdd/03-tasks.md` + todos os `docs/feature/*/tasks.md`), não só nesta feature.
- Critérios de aceite testáveis, um por task.

Apresente e peça aprovação explícita, mesma regra dos passos anteriores.

Depois de aprovado: atualize a linha do item em `docs/backlog/README.md` — `Status` vira `Promovido para TASK-0xx` (primeira task criada a partir dele) — e o mesmo campo no arquivo individual do item. Nesta etapa o arquivo ainda **permanece** em `docs/backlog/` (as tasks ainda não foram executadas) — ele só é movido na etapa 6, quando a execução terminar.

## 5. Execução de cada task

Só depois do `tasks.md` aprovado. Para cada task, na ordem do arquivo:

1. Siga o fluxo já documentado em `docs/sdd/04-implementation.md` §1: branch `<tipo>/<AAAAMMDD>-<slug-da-feature>-TASK-0xx` a partir de `dev` atualizada, implemente só o escopo da task (extra descoberto vira task nova em `tasks.md`, não expande a atual).
2. Para codar, use a skill do domínio (`expense-backend`/`expense-frontend`) — carrega convenções e gates automaticamente.
3. Antes de abrir o PR, rode o checklist pré-PR (ou use o agent `pr-readiness-checker`).
4. Abra o PR contra `dev` referenciando o `TASK-0xx`. **Não faça merge, não faça deploy** — esses continuam gate humano explícito (`00-constitution.md` §5.2), este comando não pula isso.
5. Registre uma linha em `implementation.md` da feature: task, status, data, comando executado + resultado real (não "testado" em prosa).
6. Pare após abrir o PR da task e pergunte ao usuário se segue para a próxima task ou para por aqui — não execute todas as tasks em sequência sem checar.
7. Quando todas as tasks da feature já estiverem mergeadas em `dev` e validadas lá, abrir o PR de promoção `dev` → `main` é um passo à parte (fora do loop por task acima) — não abrir esse PR automaticamente ao final da última task sem antes confirmar com o usuário que a validação em `dev` foi feita.

> Feature `docs/feature/20260817-config-url-api-frontend/` é a exceção: foi iniciada antes desta convenção existir e segue no fluxo antigo (branch/PR por task direto pra `main`) até o fim, para não trocar o processo no meio da execução — ver `docs/sdd/04-implementation.md` §1.

## 6. Fechamento

Quando **todas** as tasks de `tasks.md` já tiverem PR aberto (não é preciso esperar merge/deploy — esses são gates humanos separados e podem demorar):

1. Para cada item de backlog que originou esta feature (um ou mais, se agrupados no passo 0.3), acrescente ao final do arquivo em `docs/backlog/<arquivo>.md` uma seção:
   ```
   ## Resolução
   Concluído em: <AAAA-MM-DD>
   Feature: docs/feature/<AAAAMMDD>-<slug>/
   Tasks: TASK-0xx, TASK-0yy
   PRs: <link(s)>
   ```
2. Mova o arquivo de `docs/backlog/<arquivo>.md` para `docs/backlog/concluidos/<arquivo>.md` (mesmo nome, só muda de pasta — histórico do "porquê" preservado no próprio arquivo).
3. Em `docs/backlog/README.md`: remova a linha do item da tabela "Índice" (itens abertos) e adicione uma linha equivalente na tabela "Concluídos" (criar essa segunda tabela se ainda não existir), apontando para `concluidos/<arquivo>.md`.

Finalize com um resumo curto: pasta da feature, o que foi aprovado em cada etapa, quais tasks foram executadas/têm PR aberto, quais ainda faltam, itens de backlog movidos para `concluidos/`, e quaisquer gates humanos pendentes (merge, deploy).
