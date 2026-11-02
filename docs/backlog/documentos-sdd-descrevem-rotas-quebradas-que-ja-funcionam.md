# Documentos do SDD descrevem como quebradas rotas que já funcionam e como aberto um IDOR já corrigido

ID: 131
Origem: TASK-559 da feature `docs/feature/20261030-saida-e-remocao-de-membro/` (leitura de `docs/sdd/01-specify.md`, `docs/sdd/00-constitution.md` e das rotas ao atualizar o §3.3)
Criado em: 2026-11-02
Prioridade: BAIXA
Status: Aberto

## Descrição
- `docs/sdd/01-specify.md` §3.3, linha 52: diz que `GroupController@show/update/destroy` "não verificam se o usuário autenticado é membro do grupo". Falso desde o Épico B (`docs/feature/concluidas/202608/20260817-seguranca-api/`): os três chamam `authorizeMembership` e devolvem 404 a quem não é membro.
- `docs/sdd/01-specify.md` §3.4, linha 58: diz que `index`, `show`, `update` e `destroy` do `apiResource('expenses', …)` "estão registradas na rota mas não implementadas". `show`, `update` e `destroy` existem e têm testes; só o `index` falta (item 132).
- `docs/sdd/00-constitution.md` §2.4 (linha 23): cita como rotas sem método `GET/PUT/PATCH/DELETE /expenses/{id}` e `DELETE /groups/{groupId}/members/{userId}}` (com uma chave sobrando). As duas funcionam hoje (a segunda desde a feature `20261030-saida-e-remocao-de-membro`). O que segue sem método é `GET /api/expenses` (`ExpenseController@index`), que a frase não menciona.
- `docs/sdd/00-constitution.md` §5.3 (linha 94): lista `GroupController@show/update/destroy` sem checagem de membro como achado que aguarda decisão humana; já corrigido pelo Épico B.
- **A Constitution só muda por decisão humana (§5.2)**: a IA não a edita. Este item registra o que está desatualizado para o usuário decidir a redação.

## Por que importa
O `01-specify.md` é o baseline de leitura (ADR-002) e a Constitution é a regra normativa. Hoje elas mandam quem chega ao projeto acreditar numa falha de segurança aberta (o IDOR de grupos) e em rotas quebradas que já funcionam, e deixam de avisar da rota que de fato está quebrada.

Tipo sugerido: doc. As linhas do `01-specify.md` podem ser corrigidas por uma tarefa de documentação; a Constitution exige aprovação do usuário.
