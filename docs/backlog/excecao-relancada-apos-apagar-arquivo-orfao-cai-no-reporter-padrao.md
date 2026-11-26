# Exceção relançada depois de apagar o arquivo órfão cai no reporter padrão do Laravel (pode logar SQL com bindings)

ID: 166
Origem: feature `20261125-log-sem-mensagem-da-excecao-restantes` (itens 164 e 165; fora do escopo daquela feature, decisão de registrar tomada por ele em 2026-11-26); `backend/app/Http/Controllers/ExpenseController.php:1698` e `:1850`, `backend/app/Http/Controllers/SettlementDecisionController.php:198`
Criado em: 2026-11-26
Prioridade: BAIXA
Status: Aberto

## Descrição
Três pontos fazem `catch (\Throwable $e)`, apagam o arquivo recém-gravado (`ProofStorage::delete`) para não deixar órfão e **relançam** (`throw $e`):

- `ExpenseController.php:1698` (troca do comprovante da parcela, falha do `$quota->update`);
- `ExpenseController.php:1850` (`confirmSettlement`, falha ao gravar a confirmação);
- `SettlementDecisionController.php:198` (`complement`, falha de `addComplement` que não seja `SettlementDecisionRefused`).

Como a exceção sobe, quem a registra é o reporter padrão do Laravel (`App\Exceptions\Handler`, que hoje não tem `reportable` próprio). Quando for uma `QueryException`, a mensagem traz o **SQL com os bindings** (valores do acerto, nomes, ids), o mesmo tipo de dado que as features 155 (ponto 2), 163, 164 e 165 tiraram dos `Log::*` explícitos. Os pontos acima não têm `Log::*` próprio: a exposição vem do relançamento e é a do próprio framework.

Não é um bug de comportamento (o arquivo órfão é apagado e o cliente recebe o 500 de sempre); é a mesma classe de exposição de dado em log, só que por um caminho que as features anteriores não cobriram.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde tratar | A) um `reportable` no `Handler` que, para `QueryException`, registra só a classe e o código SQLSTATE (cobre todo o app, não só os 3 pontos)<br>B) nos 3 pontos: logar só a classe e relançar sem passar pelo reporter<br>C) aceitar e documentar como comportamento do framework | **A** | Um ponto só, cobre também qualquer `QueryException` não prevista. Custo: o log perde o SQL que ajuda a depurar; precisa de uma forma de depurar (trace, ids) e de rever o que o Handler padrão já achata. B repete a lógica em 3 lugares e deixa os demais caminhos expostos. |
| D2 | Escopo do `reportable` | A) só `QueryException`<br>B) toda exceção (só a classe)<br>C) só em produção (`APP_ENV`) | **A** | O vazamento relevante é o SQL com bindings; B esconde demais para depurar e C faz o ambiente de teste divergir do de produção. |
| D3 | Trace | A) manter o stack trace no log<br>B) tirar | **A** | O trace aponta o arquivo e a linha e não carrega os bindings; é o que sobra para depurar. |

## Por que importa
É a mesma preocupação das features 155 (ponto 2), 163, 164 e 165: não gravar em log valores de negócio (o SQL com bindings). É defesa em profundidade: nenhum dos três pontos é explorável por um cliente, e só grava se o banco falhar no meio da operação.

## Fora deste item
- `AuthController.php` (linhas 20-22, 31, 37, 45-48 e 72-75) grava o e-mail em claro em logs de login e logout: é outro achado, ainda sem item.
- `GroupController::store` loga o model `User` inteiro: já registrado no item 060.

Tipo sugerido: backend (sem mudança de contrato nem migration)
