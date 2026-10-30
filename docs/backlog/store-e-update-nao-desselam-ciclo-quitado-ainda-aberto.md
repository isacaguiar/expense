# `store()` e `update()` não desfazem a selagem de ciclo quitado e ainda aberto: despesa nova some do `summary()`

ID: 113
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/implementation.md (TASK-531, RK6 do specify); `backend/app/Http/Controllers/ExpenseController.php` (`store()`, `rejectIfCompetenceClosed`, `summary()`, `unsealIfBroken`)
Criado em: 2026-10-29
Prioridade: MEDIA
Status: Aberto

## Descrição
O `store()` só recusa o cadastro em ciclo fechado por data ou com fechamento manual ativo. Um ciclo **quitado** (todas as despesas pagas e todos os acertos confirmados) fica **selado** (`settled_at`) mas segue `open` até a data de corte, e nesse estado o `store()` aceita a despesa nova (201). Só que o `summary()` serve o snapshot selado: a despesa nova **não aparece** nas despesas, nos totais, no Painel nem no Relatório de fechamento, até alguém desfazer um pagamento do ciclo (`unsealIfBroken`, que o `unpay()` já chama). A listagem por mês (`indexByGroup`), que não passa pelo snapshot, mostra as duas.

Reproduzido em 2026-10-27 (TASK-531), com teste temporário: grupo de um só membro, despesa À Vista paga e ciclo selado, segunda despesa pendente cadastrada no mesmo ciclo → `201`, e o `summary()` devolve `settled=true`, só a primeira despesa e `totals {total: 100, paid: 100, pending: 0}`. O alcance provável vai além do cenário do teste: **a carência**. Nos 5 dias depois do fim do mês o ciclo ainda é `open`; se ele já tinha selado e alguém lança uma despesa atrasada datada no mês anterior, o `store()` aceita e o `summary()` a esconde. Foi reproduzido só no `store()`; o `update()` (que também pode acrescentar quota pendente, ao trocar o tipo ou as parcelas) **não foi testado**.

É o espelho do item 102: lá falta **selar** depois de uma edição que quita o ciclo; aqui falta **dessetar** quando entra uma quota pendente.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Uma despesa cadastrada ou editada nunca fica invisível no `summary()` do ciclo em que cai. |
| R2 | O cadastro com pagamento (feature de origem) não piora o problema: o `store()` não sela (D8 do specify). |
| R3 | Mudança aditiva ou sem mudança de contrato para os clientes (Constitution §4 item 1), a menos que a decisão D1 escolha recusar. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como fechar a lacuna | A) dessetar o ciclo (`unsealIfBroken`) quando entra quota pendente, no `store()` e no `update()`<br>B) recusar o cadastro em ciclo selado, como já recusa o fechado<br>C) só avisar na tela | **A** | É o mesmo mecanismo que o `unpay()` já usa e não perde despesa atrasada legítima na carência; B obriga a desfazer um pagamento só para lançar uma despesa. |
| D2 | Caminho | A) `/novo-bug` (BFF) com a Triagem<br>B) feature SDD | **A** | É defeito em comportamento existente; a Triagem decide se algum gatilho o escala. |

## Por que importa
Totais, Painel e Relatório de fechamento ficam errados **sem nenhum erro visível**: o ciclo aparece quitado enquanto há uma despesa pendente que ninguém vê. Fecha o ciclo com o valor errado e esconde dívida entre os membros.

Tipo sugerido: backend
