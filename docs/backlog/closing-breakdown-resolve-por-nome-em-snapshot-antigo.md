# `ClosingBreakdown` resolve credor e devedor por nome em snapshot antigo: com membros homônimos, o último vence

ID: 103
Origem: docs/feature/20261006-relatorios-acompanhamento-fechamento/implementation.md §3.2 (achado 7 da revisão de segurança da TASK-496); `backend/app/Support/ClosingBreakdown.php`
Criado em: 2026-10-25
Prioridade: BAIXA
Status: Aberto

## Descrição
O Relatório de Fechamento agrega "quem adiantou quanto" e "quem deve a quem" em `ClosingBreakdown::build()`. Para despesa de **snapshot antigo**, sem `userPayerId` ou sem `participantDetails` (campos que snapshots selados antes de certas features não têm), a identidade vem do **nome**: `$byName = $members->keyBy('name')` (`ClosingBreakdown.php:35`), usado em `creditorOf()` (`:101`) e na lista de participantes (`:129-137`).

`keyBy` sobrescreve quando a chave repete, então, se dois membros do mesmo grupo têm o mesmo nome, **o último vence** (conferido com `php -r` e `collect()->keyBy('name')` em 2026-10-25). Resultado possível: o `id` ou o `avatarUrl` de um é mostrado para o outro, e dois participantes homônimos viram uma linha só no mesmo devedor.

- **Só vale** para despesa de snapshot antigo (os dois campos ausentes). O snapshot novo e o cálculo ao vivo trazem o `id` e não passam por esse caminho.
- **Não vaza dado entre grupos:** os dois "homônimos" são membros do mesmo grupo, e `ClosingBreakdown` não consulta o banco.
- **Não reproduzido.** Montei só a leitura do código e a prova do `keyBy`; **não construí um snapshot antigo com homônimos**, então o efeito concreto na tela é inferido. `ClosingBreakdownTest` não tem caso de nomes repetidos (a nota da TASK-482 que dizia "o primeiro" estava errada e foi corrigida).

## Por que importa
Pouco: é erro de **exibição** (avatar ou identificador trocado, valores somados na pessoa errada dentro do mesmo grupo), raro (exige snapshot anterior aos campos novos **e** dois membros com o mesmo nome), e o Relatório de Fechamento é só de leitura. Vira problema quando alguém imprime o fechamento de um ciclo antigo de um grupo com homônimos e confia nele como comprovante.

## Opções
Registradas aqui; decidir só se alguém promover o item.

| # | Opção | Efeito |
|---|---|---|
| A | Nome ambíguo (mais de um membro com ele) **não resolve**: `id` e `avatarUrl` nulos, e o nome do snapshot é mantido | Some o erro de atribuição; o relatório mostra um devedor sem avatar. Não separa dois homônimos numa linha só, porque sem `id` a identidade continua sendo o nome |
| B | Deixar como está e documentar | Custo zero; o erro continua possível no caso raro |

Antes de qualquer correção, escrever o teste que reproduz: snapshot sem `userPayerId`/`participantDetails`, dois membros homônimos, e conferir o `id` e o `avatarUrl` devolvidos.

Relacionado: `plan.md` da feature (TASK-482), `ClosingBreakdownTest`.

Tipo sugerido: backend
