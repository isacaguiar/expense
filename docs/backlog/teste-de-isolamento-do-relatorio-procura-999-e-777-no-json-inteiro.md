# Teste de isolamento do relatório de fechamento procura "999" e "777" no JSON inteiro e falha quando um id contém esses dígitos

ID: 130
Origem: TASK-563 (suíte inteira do backend, 1 falha intermitente) da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/`; `backend/tests/Feature/ExpenseControllerClosingReportTest.php` (`:489-534`, escrito na TASK-496, commit `5711368187`)
Criado em: 2026-10-31
Prioridade: BAIXA
Status: Aberto

## Descrição
- `test_a_live_report_never_mixes_in_data_from_another_group_of_the_same_members` termina com um laço (`:531-533`) que procura `'Viagem do outro grupo'`, `'nota do outro grupo'`, `'Parcelada do outro grupo'`, `'Fixa do outro grupo'`, **`'999'`** e **`'777'`** como substring no JSON inteiro da resposta (`assertStringNotContainsString`). Os dois últimos são os valores das despesas e do snapshot do outro grupo.
- O JSON traz ids reais (`group.id`, `expenses[].id`, `userPayerId`, `participantDetails[].id`, `balances[].user_id` …). Quando algum id tem esses dígitos em sequência (grupo 77700, despesa 12777, usuário 25999), o teste falha **sem nenhum vazamento**.
- **Reproduzido de forma determinística** no MySQL de teste: com o `AUTO_INCREMENT` de `ex_groups` em 77700, o teste falhou com `does not contain "777"` apontando para `"group":{"id":77700,…`; o contador foi devolvido ao valor original. **Ocorrência real:** 1 falha em 3 rodadas da suíte inteira em 2026-10-31 (passa isolado e passou na rodada seguinte, 960 passed). Os contadores do banco de teste só crescem (usuários 25878, grupos 19680, despesas 12489 naquele dia), então a chance sobe com o uso.
- Outros testes com o mesmo padrão: nenhum. Busca por `assertStringNotContainsString` em `backend/tests`: os demais procuram textos, e-mails, números de telefone de 13 dígitos ou datas.

## Por que importa
Falha intermitente na suíte inteira, que é o checklist pré-integração e pré-PR. O risco real é o hábito de "rodar de novo até passar": uma falha intermitente conhecida esconde o dia em que o mesmo teste falhar por um vazamento verdadeiro entre grupos, que é exatamente o que ele existe para pegar.

Tipo sugerido: correção só de teste, sem mudança de comportamento (bugfix pelo BFF, `/novo-bug`). Trocar os marcadores numéricos por textos que não colidam com ids (por exemplo `'Despesa de 999 do outro grupo'` na descrição, ou valores de várias casas como `4999.37`) e manter o resto do laço.
