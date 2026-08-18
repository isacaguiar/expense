# Templates de feature

> Esqueletos em branco dos 4 documentos que toda pasta em `docs/feature/<AAAAMMDD>-<slug>/` deve ter. Copie o conteúdo, preencha os placeholders `<...>`, apague o que não se aplicar.

Formato espelhado do primeiro caso real do projeto: `docs/feature/20260817-seguranca-api/`. Se um template e um caso real divergirem no futuro, o caso real mais recente é o que deve orientar — atualize o template junto.

| Template | Vira | Quando preencher |
|---|---|---|
| [`specify.template.md`](specify.template.md) | `specify.md` | Primeiro documento da feature — o problema/necessidade, antes de qualquer decisão técnica. |
| [`plan.template.md`](plan.template.md) | `plan.md` | Depois do specify aprovado — traduz cada item do problema em decisão de engenharia. |
| [`tasks.template.md`](tasks.template.md) | `tasks.md` | Depois do plan — quebra em unidades atômicas executáveis (mesmo formato de `docs/sdd/03-tasks.md`). |
| [`implementation.template.md`](implementation.template.md) | `implementation.md` | Ao começar a executar a primeira task — vai ganhando uma linha de log por task concluída. |

O jeito mais rápido de criar os quatro de uma vez, já com data e slug preenchidos, é o slash command `/nova-feature <slug-curto>`. Estes templates continuam existindo separadamente para quem preferir copiar manualmente ou consultar o formato.

Ver `docs/sdd/README.md` para onde essa pasta se encaixa no fluxo geral do SDD.
