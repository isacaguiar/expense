---
description: Cria a pasta docs/feature/<AAAAMMDD>-<slug>/ com os 4 documentos do SDD (specify, plan, tasks, implementation) a partir dos templates de docs/sdd/templates/
---

O usuário passou como argumento um slug curto e/ou uma descrição da feature/épico técnico: `$ARGUMENTS`

Execute, nesta ordem:

1. **Confirme que há task aplicável.** Se o pedido não corresponder a nada em `docs/sdd/03-tasks.md` (épico antigo ainda não migrado) nem a uma necessidade já discutida com o usuário nesta conversa, pare e pergunte antes de criar qualquer pasta — regra de "Quando não houver task aplicável" do `CLAUDE.md` raiz. Não invente escopo a partir só do slug.

2. **Derive o slug final.** Se `$ARGUMENTS` já veio como slug curto (kebab-case, sem acentos, poucas palavras), use-o. Se veio como frase, gere um slug curto a partir dela (kebab-case, sem acentos). Confirme com o usuário se houver ambiguidade real entre duas leituras possíveis do pedido.

3. **Calcule a data de hoje** no formato `AAAAMMDD`.

4. **Verifique se a pasta já existe**: `docs/feature/<AAAAMMDD>-<slug>/`. Se já existir, avise o usuário e pare — não sobrescreva.

5. **Crie a pasta e copie os 4 templates** de `docs/sdd/templates/` para dentro dela:
   - `specify.template.md` → `specify.md`
   - `plan.template.md` → `plan.md`
   - `tasks.template.md` → `tasks.md`
   - `implementation.template.md` → `implementation.md`

   Em cada arquivo copiado, preencha `Versão: 1.0 · Criado em: <AAAAMMDD>` com a data calculada, e o título `# <Tipo> — <Nome da Feature>` com um nome legível derivado do slug/descrição. Deixe todos os outros placeholders `<...>` como estão — não invente conteúdo de negócio, decisão técnica ou tasks; isso é trabalho de outra etapa, com o usuário.

6. **Não escreva código nem migre nada.** Este comando só cria os 4 documentos vazios. Preencher `specify.md` de verdade, e só depois `plan.md`/`tasks.md`/`implementation.md`, é o próximo passo — com revisão do usuário entre cada um, como já descrito em `docs/sdd/README.md` e `CLAUDE.md`.

7. **Se esta feature for a migração de um épico já existente** em `docs/sdd/03-tasks.md` (ex.: um próximo épico como o Épico A ou D quando alguém começar a trabalhar neles), depois de criar a pasta lembre o usuário de deixar só um ponteiro no `03-tasks.md` original, como já foi feito para o Épico B em `docs/feature/20260817-seguranca-api/`. Não edite o `03-tasks.md` sem confirmar com o usuário.

8. **Finalize com um resumo curto**: caminho da pasta criada, os 4 arquivos, e o que falta (preencher `specify.md` primeiro).
