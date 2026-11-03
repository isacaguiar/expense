# Hipótese: a saída de membro (`destroy`) pode correr com `ExpenseController::store` e `GroupMemberController::store`, que não pegam o lock do grupo

ID: 129
Origem: TASK-556 (revisão de segurança) da feature `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/`; `backend/app/Http/Controllers/GroupMemberController.php` (`destroy`, `:127-145`)
Criado em: 2026-10-31
Prioridade: BAIXA
Status: Aberto

## Descrição
- O `destroy` trava a linha do grupo (`Group::lockForUpdate()`, `:128`) e recalcula tudo depois do lock, mas isso só serializa **outras saídas**. `ExpenseController::store` (que valida o credor como membro antes de gravar) e `GroupMemberController::store` não pegam o mesmo lock.
- Entrelaçamentos possíveis: (a) uma despesa cadastrada com credor que acabou de sair, contornando a D3-B; (b) um grupo marcado `deleted = true` pela saída do último membro enquanto outro é adicionado no mesmo instante.
- **Hipótese, não reproduzida.** Exige temporização precisa de duas requisições; (b) só pode ser provocado pelo último membro contra si mesmo. As duas saídas simultâneas, inclusive dos dois últimos membros, estão corretas.

## Por que importa
Baixo risco e baixa probabilidade, mas é o tipo de invariante (credor com parcela aberta não sai, grupo sem membro fica excluído) que se quebra sem ninguém perceber.

Tipo sugerido: se a triagem decidir tratar, pegar o mesmo lock (`lockForUpdate` ou `sharedLock` na linha do grupo) nas duas escritas, com teste de ordem; senão, fechar como risco aceito.
