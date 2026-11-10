# Alternar a lista de "quem paga por mim" avisa o mesmo responsável a cada volta

ID: 141
Origem: TASK-589 (revisão de segurança, BAIXA-1) da feature `docs/feature/20261109-responsavel-do-devedor/`; `backend/app/Http/Controllers/GroupMemberResponsibleController.php` (`update`) e `Notifier::paymentResponsibleAssigned`; specify R11 e RK4
Criado em: 2026-11-10
Prioridade: BAIXA
Status: Aberto

## Descrição
Quem entra na lista de "quem paga por mim" recebe a notificação `payment_responsible_assigned`; quem sai não recebe, e quem já estava não é avisado de novo (R11). Mas o devedor pode mandar `PUT []` e depois `PUT [R]` em sequência: R sai e volta, e recebe um aviso novo a cada volta. R não pode recusar (RK4) e só o limiter global `api` (60 requisições por minuto por usuário) segura o ritmo. O alcance é só entre membros do mesmo grupo, porque o devedor só escolhe membros.

## Por que importa
Dá para incomodar um co-membro com avisos repetidos. É assédio de baixo custo e de baixa gravidade, visível ao grupo, e não expõe dado nem move dinheiro.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como limitar | A) não reavisar o mesmo par (grupo, devedor, responsável) por 24 h, guardando a marca no cache (`Cache::add` com TTL), sem migration<br>B) `throttle` próprio na rota<br>C) deixar como está | **A** | É a mesma ideia da janela de 24 h do convite (`notified_at`, item 133) e não cria coluna: a linha do vínculo é apagada ao sair (D10 da feature), então ela não pode guardar a marca. B limita o devedor, não o aviso. |

Tipo sugerido: backend, com teste de "sai e volta dentro da janela não avisa de novo".
