# `POST /api/expenses` devolve a mensagem da exceção no 500

ID: 080
Origem: docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/ (observação pré-existente da revisão de segurança da TASK-403); `backend/app/Http/Controllers/ExpenseController.php` (`store()`, no `catch`)
Criado em: 2026-10-09
Prioridade: MEDIA
Status: Aberto

## Descrição
O `catch (\Throwable)` do `store()` responde `500` com `['error' => 'Erro ao criar despesa', 'details' => $e->getMessage()]`. Numa falha de banco, a mensagem da exceção traz o SQL e os valores ligados, e vai para o cliente. É a única ocorrência de `details` nos controllers, e o frontend não lê esse campo.

O `.env.example` traz `APP_DEBUG=true`: vale confirmar `APP_DEBUG=false` em produção, porque o `Handler` padrão também detalharia exceções com debug ligado.

Proposta: registrar a exceção no log com os campos específicos (sem despejar o request inteiro) e devolver só a mensagem genérica.

Caminho provável: é defeito em comportamento existente, então vai por `/novo-bug` (BFF) com a Triagem; devolver menos campos num 500 não muda contrato que algum cliente use.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A resposta de erro continua `500` com `error: "Erro ao criar despesa"`. |
| R2 | O log não grava o array do request inteiro nem credenciais (regra 3 de `00-constitution.md` §5.3). |
| R3 | O rollback da transação do `store()` não muda. |

## Decisões em aberto
A fechar na Triagem.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que fazer com `details` | A) remover e logar a exceção<br>B) manter só com `config('app.debug')` | **A** | B depende de `APP_DEBUG` estar certo em cada ambiente; A não depende. |
| D2 | Confirmar `APP_DEBUG` em produção | A) conferir no servidor e registrar no bugfix<br>B) deixar para depois | **A** | É a causa mais provável de vazamento maior que este. A conferência é do usuário (acesso à produção). |

## Por que importa
Uma falha de banco expõe a estrutura das tabelas e valores de outros campos a quem acionar a rota. Não há exposição de dado de terceiro por si só, mas é informação útil para quem procura outra brecha.

Tipo sugerido: backend
