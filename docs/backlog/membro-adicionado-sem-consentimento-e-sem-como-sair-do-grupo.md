# Usuário é adicionado a um grupo sem consentimento e não tem como sair (`GroupMemberController::destroy` não existe)

ID: 086
Origem: docs/feature/20261010-painel-financeiro-pessoal/implementation.md §8 (achados X2 e S1 da revisão de segurança da TASK-414); `backend/app/Http/Controllers/GroupMemberController.php` (`store()`); `backend/routes/api.php:57`; `docs/sdd/03-tasks.md` TASK-017
Criado em: 2026-10-21
Prioridade: ALTA
Status: Aberto

## Descrição
Duas pontas do mesmo problema:

1. **Entrada sem consentimento.** `GroupMemberController::store()` recebe um e-mail; se já existe `User` com ele, faz `attach` direto e notifica (`Notifier::groupMemberAdded`). Quem foi adicionado não aceita nem recusa. Para e-mail novo existe o fluxo de convite com token, mas para conta existente não.
2. **Sem saída.** A rota `DELETE /groups/{groupId}/members/{userId}` está registrada, mas o método `destroy` **não existe** no controller: a chamada quebra. Já está listado como `TASK-017` do Épico C de `docs/sdd/03-tasks.md` (épico antigo não migrado, ainda sem pasta em `docs/feature/`), na `01-specify.md` §3.3 e na Constitution §2.4.

Juntas, permitem que um membro de um grupo coloque qualquer usuário cadastrado nele, e a pessoa nunca consegue sair. O que limita o abuso hoje é só `GroupController::MAX_GROUPS_CREATED_PER_USER = 3`: cada conta cria no máximo 3 grupos, então levar uma vítima a N grupos exige cerca de N/3 contas com e-mail verificado. É mais caro para o atacante, mas **não há teto de grupos em que alguém participa**: nenhuma checagem no `store()` olha quantos grupos o usuário adicionado já tem. Efeito medido na revisão: o `GET /me/overview` custa ~54 consultas por grupo vazio (20 grupos vazios = 1.082 consultas e ~2,8 s), o que a feature do painel limita com otimização e teto (TASK-427 e 428), mas a causa raiz é esta.

Caminho provável: `destroy` ausente é endpoint novo e exige decisão de produto (quem remove quem, o que fazer com pendências), então vai direto como feature via `/promover-backlog`, não como BFF. Pode absorver a `TASK-017` do `03-tasks.md` ao criar a pasta em `docs/feature/`.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | `destroy` confere que quem chama é membro do grupo (`authorizeMembership`, 404 para não membro) e responde 404 em grupo excluído, como o `store()`. |
| R2 | Contrato aditivo (Constitution §4.1): a rota `DELETE` já existe; passa a funcionar. Sem migration, salvo se a D1 escolher o convite pendente. |
| R3 | Testes PHPUnit de autorização e de efeito: não membro 404; membro que sai some de `GET /groups` e do `GET /me/overview`; histórico de despesas e acertos do ciclo não é apagado. |
| R4 | Rodar o agent `security-reviewer` na rota. |

## Decisões em aberto
A fechar no Specify da feature.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como a pessoa entra num grupo que ainda não conhece | A) entra direto, como hoje, e passa a poder sair (`destroy`)<br>B) convite pendente que a pessoa aceita ou recusa | **A** na v1 | Resolve "não consigo sair" e limita o abuso sem tabela nova nem tela de convites. B é a correção completa do consentimento, mas exige migration, notificação de aceite e UI nos dois clientes. Pode vir depois, aditivo. |
| D2 | Quem pode remover quem | A) o próprio usuário sai; o criador do grupo remove outros<br>B) só a própria saída | **A** | Cobre o caso de convidado indevido sem depender de o convidado agir. |
| D3 | Saída de quem tem valores em aberto | A) bloquear (422) enquanto houver pendência no ciclo em foco ou ciclo fechado não selado<br>B) permitir e manter o histórico | **A** | O rateio e o acerto líquido dependem dos participantes; sair com dívida some o credor ou o devedor dos cálculos. O overview já trata credor que saiu como "Desconhecido" (TASK-432), mas sair sem deixar dívida é o caso seguro. |
| D4 | Saída do criador do grupo | A) permitir, como qualquer membro<br>B) bloquear até transferir ou excluir o grupo | **A** | `GroupController` autoriza `show`/`update`/`destroy` por **membro**, não por criador; `created_by` só alimenta o limite de 3 grupos criados por conta. Sair não deixa o grupo órfão. Conferir na Triagem se o grupo sem nenhum membro deve ser excluído logicamente. |

## Por que importa
É a causa raiz de qualquer rota que cresça com o número de grupos do usuário (como o `/me/overview`), além de ser um problema de produto e de LGPD (entrar numa lista sem aceitar, com e-mail e Pix visíveis aos demais membros). Sem saída, a única defesa do usuário é pedir a alguém para excluir o grupo.

Relacionado: 085 (leitura de membros sem checagem), 060 (log do `User` inteiro em `GroupController::store`) e 072 (exclusão de conta).

Tipo sugerido: backend
