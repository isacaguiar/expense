# Alternar a lista de "quem paga por mim" avisa o mesmo responsável a cada volta

ID: 141
Origem: TASK-589 (revisão de segurança, BAIXA-1) da feature `docs/feature/concluidas/202611/20261109-responsavel-do-devedor/`; `backend/app/Http/Controllers/GroupMemberResponsibleController.php` (`update`) e `Notifier::paymentResponsibleAssigned`; specify R11 e RK4
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Concluído (PR #274)

## Descrição
Quem entra na lista de "quem paga por mim" recebe a notificação `payment_responsible_assigned`; quem sai não recebe, e quem já estava não é avisado de novo (R11). Mas o devedor pode mandar `PUT []` e depois `PUT [R]` em sequência: R sai e volta, e recebe um aviso novo a cada volta. R não pode recusar (RK4) e só o limiter global `api` (60 requisições por minuto por usuário) segura o ritmo. O alcance é só entre membros do mesmo grupo, porque o devedor só escolhe membros.

## Por que importa
Dá para incomodar um co-membro com avisos repetidos. É assédio de baixo custo e de baixa gravidade, visível ao grupo, e não expõe dado nem move dinheiro.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como limitar | A) não reavisar o mesmo par (grupo, devedor, responsável) por 24 h, guardando a marca no cache (`Cache::add` com TTL), sem migration<br>B) `throttle` próprio na rota<br>C) deixar como está | **A** | É a mesma ideia da janela de 24 h do convite (`notified_at`, item 133) e não cria coluna: a linha do vínculo é apagada ao sair (D10 da feature), então ela não pode guardar a marca. B limita o devedor, não o aviso. |

Tipo sugerido: backend, com teste de "sai e volta dentro da janela não avisa de novo".

## Resolução
Concluído em: 2026-11-12
Feature: docs/feature/concluidas/202611/20261110-quem-paga-se-declara-e-credor-recusa/
Tasks: TASK-603
PRs: https://github.com/isacaguiar/expense/pull/274

Entregue como a D11-A do specify aprovou: `Notifier::oncePer` guarda uma marca no cache (`Cache::add` com TTL de 24 h) por par grupo × devedor × responsável × tipo, e `payment_responsible_assigned` e o novo `payment_responsible_self_declared` passam por ela. `PUT []` seguido de `PUT [R]` em menos de 24 h avisa uma vez; depois de 24 h, avisa de novo. Cache fora do ar vira log e o aviso não sai (falha fechada, dentro do `guard`); falha no envio libera a chave para o próximo. Sem migration. Desdobramento: o item 148 (a janela de 24 h também silencia a re-declaração depois que o devedor remove o vínculo).
