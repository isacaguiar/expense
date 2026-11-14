# Achados informativos da revisão de segurança da feature "histórico de comprovantes e justificativa"

ID: 155
Origem: docs/feature/20261114-historico-de-comprovantes-e-justificativa/ (TASK-628, revisão do `security-reviewer`, achados INFO); nenhum bloqueia o PR
Criado em: 2026-11-14
Prioridade: BAIXA
Status: Aberto

## Descrição
Pontos menores, cada um com o arquivo e a sugestão; quem pegar o item decide quais valem uma task própria. O oráculo de existência de usuário em `exists:ex_users,id` antes do 403 e o `cycles_ago` sem teto, que o revisor também listou, **já estão no item 149** (pontos 1 e 2) e não se repetem aqui.

1. **O complemento não aponta para a versão do comprovante que ele justifica** (`ex_settlement_proof_entries`). Se o devedor substituir o dele depois, a justificativa passa a aparecer no histórico ao lado de outro arquivo. Não é exposição, é coerência do histórico. Sugestão: guardar na entrada do complemento a referência ao comprovante (`proof_path` ou o `sent_at`) a que ela se refere, ou mostrar a data do comprovante ao lado de cada complemento.
2. **`Notifier::guard` registra `$e->getMessage()`** (`Notifier.php`, `Log::warning`). Uma `QueryException` traz o SQL com os bindings, que podem incluir nomes de usuário e do grupo. O aviso novo não põe a justificativa em `data`, então ela não chega ao log por esse caminho. Sugestão: registrar só a classe da exceção e o tipo do aviso, ou mascarar os bindings.
3. **Limites do ADR-005** (URL assinada de 30 minutos, sem vínculo com o usuário): a URL não é presa a quem a recebeu, e uma URL `settlement` emitida antes de uma recusa continua baixando o arquivo até expirar. É o desenho aceito; vale registrar na seção de consequências do ADR se um dia o prazo ou a emissão mudarem.

## Por que importa
Nenhum é exploração hoje; são coerência do histórico, higiene de log e limites já conhecidos de um desenho que o ADR-005 aceitou.
