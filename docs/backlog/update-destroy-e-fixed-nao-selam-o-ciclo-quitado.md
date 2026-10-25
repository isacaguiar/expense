# `update()`, `destroy()` e a edição de despesa `FIXED` não selam o ciclo que ficou quitado

ID: 102
Origem: docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md §0.1 (ponto 2) e §8; `backend/app/Http/Controllers/ExpenseController.php` (`update`, `destroy`, `rejectIfCycleClosed`, `sealCycleIfSettled`)
Criado em: 2026-10-25
Prioridade: BAIXA
Status: Aberto

## Descrição
A foto imutável do ciclo (`GroupCycleSnapshot` com `settled_at`, a "selagem") só é gravada por `sealCycleIfSettled`, que o `ExpenseController` chama em quatro lugares (conferido em 2026-10-25): `summary()` (no ramo de ciclo `closed` ou fechado manualmente, `:697`), `close()` (`:847`), `pay()` (`:1123`) e `confirmSettlement()` (`:1410`). `update()` (`:156`) e `destroy()` (`:361`) **não** a chamam. Há dois caminhos conhecidos em que uma dessas ações deixa o ciclo todo pago e confirmado, mas sem foto:

1. **Edição ou exclusão durante a carência.** `rejectIfCycleClosed` só recusa com `422` quando `BillingCycle::statusFor` é `closed` (ou há fechamento manual ativo). Na carência o status ainda é `open`, então `update()` e `destroy()` passam. Excluir a última despesa pendente de um ciclo cujos acertos já estão confirmados deixa o ciclo quitado sem snapshot. **Reproduzido por teste:** `ExpenseControllerPendingCyclesTest::test_a_settled_cycle_without_a_seal_is_listed_as_awaiting_seal_after_the_last_pending_expense_is_deleted_in_grace` — o `DELETE` na carência não cria snapshot, e depois que o ciclo fecha por data a lista de Acompanhamento o traz com `awaiting_seal: true`.
2. **Despesa `FIXED`.** `rejectIfCycleClosed` devolve `null` para `FIXED` (`:391-398`), então `update()` e `destroy()` valem mesmo com o ciclo já fechado, e editar o valor muda ao vivo as ocorrências ainda **não materializadas** desses ciclos. Isso pode zerar a última pendência de um ciclo fechado. **Só por leitura de código, não reproduzido:** nenhum teste leva um ciclo fechado a quitado por edição de `FIXED`.

Um terceiro caminho aparece no plan: ciclo quitado **antes** de a selagem existir (feature `20260902-pagamento-ciclo-fechado`). Não verifiquei em dados.

**Quando o ciclo sela depois.** Quando alguém abre o ciclo em Pagamentos (`summary()`) já com status `closed` (depois de `closes_at`), ou passa por `pay`, `confirmSettlement` ou `close`. Um ciclo ainda em carência não sela por leitura. Enquanto ninguém faz isso, o ciclo segue calculado ao vivo e a tela de Relatórios o mostra como "Quitado" pelo `awaiting_seal` (Acompanhamento); o Relatório de Fechamento só lê e também não sela.

**Não medi em produção** quantos ciclos estão nesse estado.

## Por que importa
Pouco, hoje: o estado é raro e a tela já o trata. O que se perde enquanto o ciclo não sela:

- **Custo.** Ele é recalculado a cada leitura (`focus()` do Painel e varredura do Acompanhamento), em vez de sair de uma consulta ao snapshot. É o mesmo custo do item 093.
- **Histórico.** A aba Histórico lista só ciclos com `settled_at`, então o ciclo quitado e não selado fica no Acompanhamento e não chega lá.
- **Aviso tardio.** `Notifier::cycleSettled` só dispara na transição para selado, dentro de `sealCycleIfSettled`. Para esse ciclo, o aviso de "ciclo quitado" não sai na hora da edição; sai quando alguém, mais tarde, abre o ciclo e o selo acontece — e a leitura passa a ter o efeito colateral de notificar o grupo.

## Como medir antes de decidir
Contar, por grupo, os itens do Acompanhamento com `awaiting_seal: true`: é `summaryIsFullySettled` verdadeiro e sem selo, a definição que interessa aqui. Os que ainda estão em carência (`cycle.in_grace`) podem mudar até `closes_at`, então convém separá-los na contagem. Só leitura, no `tinker`; em produção exige acesso de leitura, que é gate humano. Cada grupo custa da ordem de 150 consultas no pior caso (estimativa da seção 4 de `consultas-tela-relatorio.md`, ainda não medida), então vale amostrar ou rodar fora do pico:

```php
$svc = app(App\Services\CycleSummaryService::class);
App\Models\Group::where('deleted', false)->get()
    ->sum(fn ($g) => collect($svc->pendingCycles($g))->where('awaiting_seal', true)->count());
```

(Rodado só no banco de teste vazio, onde devolve 0; a sintaxe e a chamada foram validadas, não o resultado em produção.) Esse caminho não cobre o ciclo só com parcela `bornPaid`, que nunca sela por desenho e o Acompanhamento já deixa de fora.

## Decisões em aberto
A fechar no specify, **depois de medir**. Se a medição mostrar poucos casos, a opção C encerra o item.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde selar | A) chamar `sealCycleIfSettled` ao fim de `update()`, `destroy()` e da edição de `FIXED`<br>B) selar na virada para `closed`, fora da leitura (comando agendado, sem `Notifier` no retroativo), junto com a opção B do item 093<br>C) não selar e aceitar o estado "Quitado" sem selo, que o `awaiting_seal` já mostra | **B**, ou **C** se a medição mostrar poucos casos | A parece o caminho óbvio, mas a edição acontece **na carência**, enquanto o ciclo ainda é `open`; selar ali pode congelar um ciclo que a regra ainda trata como aberto (a verificar no specify), e faria uma edição disparar `Notifier::cycleSettled`. B também resolve o custo do 093 (caso 2) e o ciclo quitado antes da selagem existir, que A não alcança. C custa só o que a seção acima lista. |
| D2 | Mudar a regra de selagem ou o aviso | A) manter a regra e o `Notifier` como estão, só mudando quem chama<br>B) rever quando o aviso de "ciclo quitado" sai | **A** | Mudar o aviso é outra decisão de produto. Esta é só sobre garantir a foto. |

Relacionado: 093 (custo do `focus()` com os mesmos ciclos quitados e não selados; sua opção B atacaria esta origem), `docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md` §0.1 e §8.

Tipo sugerido: backend
