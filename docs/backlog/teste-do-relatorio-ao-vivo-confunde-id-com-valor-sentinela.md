# Teste do relatório ao vivo falha de vez em quando: os valores "777" e "999" aparecem dentro de ids autoincrementados

ID: 145
Origem: docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/ (TASK-603; achado ao rodar a suíte completa do backend); `backend/tests/Feature/ExpenseControllerClosingReportTest.php` (`test_a_live_report_never_mixes_in_data_from_another_group_of_the_same_members`, a lista de `$foreign` na linha 531)
Criado em: 2026-11-11
Prioridade: MEDIA
Status: Aberto

## Descrição
O teste monta um segundo grupo com os mesmos dois membros e confere que nada dele vaza para o relatório do primeiro. Para isso procura, no JSON do relatório, textos e **números** do outro grupo: `'Viagem do outro grupo'`, `'nota do outro grupo'`, `'Parcelada do outro grupo'`, `'Fixa do outro grupo'`, `'999'` (o valor da despesa do outro grupo) e `'777'` (o total do snapshot do outro grupo). Os textos não têm como colidir. Os números têm: `assertStringNotContainsString('777', $raw)` olha o JSON inteiro, que traz ids de grupo, despesa e usuário, e esses ids crescem a cada rodada da suíte (o `DatabaseTransactions` desfaz as linhas, mas não volta o autoincremento).

Evidência (2026-11-11, suíte completa `./vendor/bin/phpunit`, `Tests: 1305, Failures: 1`): a mensagem foi `'{"group":{"id":17908,...,"expenses":[{"id":11777,...}]...}' does not contain "777"`. O relatório estava certo; o `11777` era o id da própria despesa do grupo A. Isolado (`--filter=ExpenseControllerClosingReportTest`) passa 3 de 3, e a rodada seguinte da suíte passou, porque os ids já tinham saído da faixa.

Quão frequente: um id de 5 dígitos contém `777` ou `999` em cerca de 0,2% dos casos cada, e o relatório carrega uns seis ids, então algo em torno de 2% a 3% das rodadas, e piora quando o autoincremento atravessa as faixas `x777` e `x999` (11777, 12777, ..., 11999, ...). É o único teste da suíte com esse padrão (conferido por busca em `backend/tests`).

## Por que importa
Vermelho que não é regressão: quem vê a falha pensa em vazamento entre grupos (o nome do teste diz exatamente isso) e perde tempo, e cada rodada completa custa uns 2,5 minutos. Também é um teste de **segurança** (isolamento entre grupos), o último que se quer ver intermitente e ignorado.

## Proposta
1. Trocar os dois números por valores que não aparecem em ids: com centavos e distintos o bastante, como `8123.45` para a despesa do outro grupo e `7654.32` para o total do snapshot, e procurar por esses textos. Conferir que o JSON do relatório escreve os valores desse jeito (`json_encode` de float imprime a menor representação), para o teste continuar capaz de falhar se o dado vazar.
2. Ou, melhor, deixar de procurar por substring no JSON cru e comparar o que importa por estrutura (os ids das despesas, os acertos e os totais já são conferidos acima), mantendo a busca só pelos textos.
3. Provar que o teste continua pegando o vazamento: tirar o filtro de `group_id` de uma das consultas do relatório e ver o teste ficar vermelho, antes e depois da troca.

Tipo sugerido: backend (só teste)
