# `confirmSettlement` confere o vínculo "quem paga por quem" fora da gravação: janela de milissegundos

ID: 142
Origem: TASK-589 (revisão de segurança, BAIXA-2) da feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/`; `backend/app/Http/Controllers/ExpenseController.php` (`confirmSettlement`: a checagem de `canPayFor` vem logo depois da validação e a gravação é o `updateOrCreate` mais abaixo); specify F5
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
A conferência do vínculo (`MemberResponsibleService::canPayFor`) e a gravação da confirmação não estão na mesma transação nem sob lock. Se o devedor desfaz o vínculo exatamente entre as duas, quem acabou de perder o direito ainda conclui aquela confirmação: o comprovante fica gravado com `confirmed_by` dele.

## Por que importa
O efeito é pequeno e contornável: o devedor reenvia o comprovante (o último a enviar vence, R8) e o vínculo já foi desfeito para as próximas. Exige acertar uma janela de milissegundos com a requisição de outra pessoa. Registrado para não ficar só na conversa.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Fechar a janela | A) aceitar (já documentada na specify F5)<br>B) reconferir o vínculo dentro de uma transação, com `lockForUpdate` do grupo, logo antes da gravação | **A** | B mexe na rota financeira (`confirmSettlement`, que já tem outra janela anterior à feature, o `updateOrCreate` não atômico, INFO-7) e pede teste de concorrência; o ganho não compensa o risco de regressão. Reavaliar se alguém vir isso na prática. |

Tipo sugerido: backend (só se a decisão for B).
