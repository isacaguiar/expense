# A janela de 24 h do aviso "quem paga por você" silencia a re-declaração depois que o devedor removeu o vínculo

ID: 148
Origem: docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/ (TASK-605, revisão do `security-reviewer`, achado 5); `backend/app/Support/Notifier.php` (`paymentResponsibleSelfDeclared`, `oncePer`, `linkNoticeKey`), specify R11
Criado em: 2026-11-11
Prioridade: BAIXA
Status: Aberto

## Descrição
O aviso `payment_responsible_self_declared` usa a janela de 24 h por par (grupo, devedor, responsável, tipo), contra o assédio por alternar a lista (item 141). Cenário: M se declara responsável de D e D é avisado; D remove M pelo diálogo "Quem paga por mim"; M se declara de novo antes de 24 h e **D não recebe aviso nenhum**. É design (a janela não sabe da remoção), mas a re-declaração depois de uma remoção é justamente o caso em que D mais precisa saber.

## Por que importa
Com a correção do achado 1 da mesma revisão, o vínculo só dá o poder de enviar comprovante que aguarda aceite, então o dano é pequeno. Ainda assim o devedor remove alguém e essa pessoa volta em silêncio.

Correção sugerida: esquecer a chave da janela (`Cache::forget` de `payment-responsible:{grupo}:{devedor}:{responsável}:*`) quando o vínculo é removido, por `MemberResponsibleService::replace` (o devedor) e por `declare` (o responsável que sai da lista). A janela passa a barrar só a repetição sem remoção no meio. Teste: declarar, remover, declarar de novo dentro de 24 h avisa de novo.
