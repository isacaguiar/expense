# `GroupController::destroy` apaga fisicamente o grupo, em cascata, a pedido de qualquer membro

ID: 095
Origem: docs/feature/20261010-painel-financeiro-pessoal/implementation.md §29 (observação do `security-reviewer`, fora do diff da feature); `backend/app/Http/Controllers/GroupController.php` (`destroy()`, linhas 106-131)
Criado em: 2026-10-22
Prioridade: MEDIA
Status: Aberto

## Descrição
`DELETE /api/groups/{id}` faz `authorizeMembership($group)` (basta ser **membro**) e depois:

- se o grupo tem **algum** `GroupCycleSnapshot`: exclusão lógica (`deleted = true`), o grupo some para todos os membros;
- se **não** tem nenhum: `DB::transaction` que apaga **fisicamente** as participações, as cotas, os pagadores e as despesas de cada despesa, desvincula os membros e apaga o grupo.

Ou seja, qualquer membro, inclusive quem foi **adicionado sem consentimento** (item 086), apaga de forma irreversível todas as despesas, parcelas e comprovantes de um grupo que nunca teve ciclo selado. Esse é justamente o estado de um grupo com meses de despesas ainda em aberto: o snapshot só nasce no primeiro fechamento manual ou na primeira quitação total. A resposta é `200` com "Grupo excluído permanentemente.".

**O comportamento é coberto por teste, e de propósito.** `GroupControllerTest` tem `test_member_can_delete_group_without_closed_cycle_physically`, que cria uma cota **não paga** e espera a exclusão física por um membro, `test_member_can_delete_group_with_closed_cycle_logically_preserving_history` e `test_non_member_cannot_delete_group` (`404`). Então "qualquer membro, mesmo com despesa em aberto" foi exercido como regra, não é acidente de implementação; o que não encontrei é a **decisão** por trás.

**Contra a Constitution?** `§6.0` (linha 102): "Exclusão de registro de negócio (grupo, despesa) é sempre soft delete (coluna `deleted`); nunca `DELETE` físico sem o gate humano de hard delete". A exclusão física de grupo sem competência fechada foi especificada na feature `20260822-melhoria-grupo-conta-participantes` (`specify.md` §2.2), que define a **condição** (nenhum snapshot) e a confirmação por modal no web, mas **não decide quem pode pedir**, nem trata o caso de despesas abertas. Se aquela feature passou pelo gate de hard delete, o tipo de exclusão está coberto; **quem pode** e **com despesa em aberto** não estão. Cabe ao dono do produto dizer.

É também a única saída de quem entrou num grupo sem querer, o que torna o 086 mais grave: a "porta de saída" é apagar o grupo de todo mundo.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Apagar dado definitivamente (hard delete) não é autônomo: exige aprovação humana (`00-constitution.md` §5.2). Este item não muda comportamento sem aprovação do specify. |
| R4 | Mudar quem pode excluir ou quando a exclusão é física **altera os testes de `GroupControllerTest` citados acima, de propósito**, no mesmo passo. |
| R2 | A resposta a quem não é membro continua `404` (não confirma existência). |
| R3 | O contrato da rota é aditivo; mudar quem pode excluir é mudança de autorização e passa pelo `security-reviewer`. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Quem pode excluir (lógica ou física) | A) só o criador (`created_by`, que o model já tem)<br>B) qualquer membro (hoje)<br>C) criador, ou o último membro restante | **A**, com C como saída quando o criador saiu | Excluir o grupo de todos é decisão de quem o criou. C evita grupo que ninguém consegue encerrar. |
| D2 | Exclusão física | A) só se o grupo não tem **nenhuma despesa viva** (grupo recém-criado ou vazio)<br>B) manter a regra de hoje (sem snapshot)<br>C) nunca física: sempre `deleted = true` | **A** | Preserva o caso legítimo ("criei por engano") sem permitir apagar meses de despesas em aberto. C é o mais fiel à §6.0, mas deixa lixo de grupos vazios; pode ser a escolha se o gate de hard delete não for dado. |
| D3 | Grupo com despesa em aberto | A) bloquear a exclusão (`409`) até quitar ou fechar<br>B) permitir só a lógica | **B** | Quem quer sair do grupo ainda deve poder escondê-lo; o histórico fica preservado. |
| D4 | Saída individual | A) tratada no item 086 (`GroupMemberController::destroy`)<br>B) neste item | **A** | Não misturar: este item é sobre excluir o grupo, o 086 sobre sair dele. |

## Por que importa
Perda de dado irreversível de terceiros por uma única chamada de qualquer membro, em produção hoje. A chance é pequena (precisa de membro mal-intencionado ou de um clique errado, e há modal no web), mas o dano é total e sem recuperação, e grupos estão no escopo v1 do app Flutter (074), que consumirá a mesma rota.

Relacionado: 086 (membro adicionado sem consentimento e sem como sair), 085.

Tipo sugerido: backend (+ frontend, para a cópia do modal)
