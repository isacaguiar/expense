---
name: novo-bug
description: Cria o arquivo docs/bugfix/<AAAAMMDD>-<slug>.md (fluxo BFF, correção de defeito) a partir do template, rodando a Triagem que decide se o trabalho segue no fluxo leve ou vira feature SDD.
---

O usuário passou como argumento um slug curto e/ou uma descrição do bug (texto após `/novo-bug`).

Este skill só faz o scaffold do artefato do BFF (Bug-Fix Flow) — ver `docs/bugfix/README.md` e `docs/sdd/decisions/ADR-004-fluxo-bugfix.md`. **Não escreve código, não cria branch, não corrige nada.** Preencher o arquivo e corrigir o bug é o passo seguinte.

Execute, nesta ordem:

1. **Confirme que é bug, não feature.** BFF é só para corrigir comportamento que já existe e está quebrado. Se o pedido é desenvolvimento novo (tela, endpoint, campo, regra nova), pare e diga que o caminho é `/nova-feature`. Se já existe item em `docs/backlog/` para isso, o caminho é `/promover-backlog <ID>`.

2. **Rode a Triagem com o usuário** — as 4 caixas de `docs/bugfix/README.md` §"Quando usar o BFF" (não repita a lista aqui; use a de lá). Se você já tem informação suficiente da conversa para marcar alguma com confiança, marque e **pare**: o trabalho vira feature (`/nova-feature`), não BFF. Se nenhuma se aplica, siga. Na dúvida real sobre uma caixa, pergunte ao usuário antes de decidir — não presuma.

3. **Derive o slug final** (kebab-case, sem acentos, poucas palavras). Se o argumento veio como frase, gere o slug a partir dela. Confirme com o usuário se houver ambiguidade real entre duas leituras.

4. **Calcule a data de hoje** no formato `AAAAMMDD`.

5. **Verifique se o arquivo já existe**: `docs/bugfix/<AAAAMMDD>-<slug>.md`. Se existir, avise e pare — não sobrescreva.

6. **Crie o arquivo** copiando `docs/bugfix/templates/bugfix.template.md`. Preencha:
   - `Versão: 1.0 · Criado em: <AAAAMMDD>` com a data calculada.
   - `Branch: fix/<AAAAMMDD>-<slug>` com a data e o slug.
   - O título `# Bugfix — <título legível>` derivado do slug/descrição.
   - A Triagem: marque as caixas conforme o passo 2 (todas desmarcadas se o trabalho seguiu no BFF).
   - Se a conversa já trouxe sintoma / reprodução / causa raiz, pode preencher a §1; senão, deixe os placeholders. Não invente causa raiz — só escreva `arquivo:linha` que você verificou no código real.
   - Deixe §2 e §3 com os placeholders do template.

7. **Adicione a linha em `docs/bugfix/README.md`**, tabela "Em andamento": arquivo, título, data de criação, branch `fix/<AAAAMMDD>-<slug>`, Status `Aberto`.

8. **Finalize com um resumo curto**: caminho do arquivo criado, resultado da Triagem (e, se marcou alguma caixa, que o próximo passo é `/nova-feature`, não continuar aqui), e o que falta (preencher §1 Problema, depois criar a branch `fix/...` e corrigir — usando a skill do domínio `expense-frontend`/`expense-backend`).

## Ao concluir o bug

Fechamento completo em `docs/bugfix/README.md` §Fechamento. Em resumo: acrescente a seção `## Resolução` ao arquivo, mova-o para `docs/bugfix/concluidos/<AAAAMM>/<AAAAMMDD>-<slug>.md` (`<AAAAMM>` = os 6 primeiros dígitos do nome do arquivo, mês de criação) e passe a linha da tabela "Em andamento" para "Concluídos" no `README.md`. Ver `ADR-009`.
