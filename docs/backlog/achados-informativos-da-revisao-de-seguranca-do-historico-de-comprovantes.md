# Achados informativos da revisão de segurança da feature "histórico de comprovantes e justificativa"

ID: 155
Origem: docs/feature/concluidas/202611/20261114-historico-de-comprovantes-e-justificativa/ (TASK-628, revisão do `security-reviewer`, achados INFO); nenhum bloqueia o PR
Criado em: 2026-11-14
Prioridade: BAIXA
Status: Aberto (pontos 2 e 4 resolvidos nas features das TASK-676 e TASK-687 a 689; pontos 1 e 3 seguem abertos)

## Descrição
Pontos menores, cada um com o arquivo e a sugestão; quem pegar o item decide quais valem uma task própria. O oráculo de existência de usuário em `exists:ex_users,id` antes do 403 e o `cycles_ago` sem teto, que o revisor também listou, **já estão no item 149** (pontos 1 e 2) e não se repetem aqui.

1. **O complemento não aponta para a versão do comprovante que ele justifica** (`ex_settlement_proof_entries`). Se o devedor substituir o dele depois, a justificativa passa a aparecer no histórico ao lado de outro arquivo. Não é exposição, é coerência do histórico. Sugestão: guardar na entrada do complemento a referência ao comprovante (`proof_path` ou o `sent_at`) a que ela se refere, ou mostrar a data do comprovante ao lado de cada complemento.
2. ~~**`Notifier::guard` registra `$e->getMessage()`**~~ **RESOLVIDO** na feature `docs/feature/concluidas/202611/20261124-notifier-nao-loga-sql-com-bindings/` (TASK-676; o log leva só o `type` e a classe da exceção): **`Notifier::guard` registra `$e->getMessage()`** (`Notifier.php`, `Log::warning`). Uma `QueryException` traz o SQL com os bindings, que podem incluir nomes de usuário e do grupo. O aviso novo não põe a justificativa em `data`, então ela não chega ao log por esse caminho. Sugestão: registrar só a classe da exceção e o tipo do aviso, ou mascarar os bindings.
3. **Limites do ADR-005** (URL assinada de 30 minutos, sem vínculo com o usuário): a URL não é presa a quem a recebeu, e uma URL `settlement` emitida antes de uma recusa continua baixando o arquivo até expirar. É o desenho aceito; vale registrar na seção de consequências do ADR se um dia o prazo ou a emissão mudarem.
4. ~~**O aviso do complemento não tem janela anti-assédio nem *throttle* por usuário**~~ **RESOLVIDO (janela, sem *throttle*)** na feature `docs/feature/concluidas/202611/20261125-janela-anti-assedio-aviso-do-complemento/` (TASK-687 a 689): o aviso sai no máximo uma vez por hora por autor, devedor, credor e ciclo (`Notifier::settlementComplementAdded`, chave `settlement-complement:…`); o complemento e o teto de 10 não mudam. O *throttle* por usuário foi descartado de propósito (D4-B do specify): a janela e o teto já cobrem o ruído, e um 429 chegaria ao front como erro genérico. Reavaliar só se aparecer abuso real. Texto original: **O aviso do complemento não tem janela anti-assédio nem *throttle* por usuário** (decisão D13: ele escolheu a opção A, sem a C). Quem paga pelo devedor pode gerar até **10 avisos por par e ciclo** (o teto próprio dos complementos) ao credor e ao devedor. Sugestão: uma janela como a `linkNoticeKey` do aviso de vínculo (`Notifier`) e `throttle` por usuário na rota; vale reavaliar se aparecer abuso real.

## Por que importa
Nenhum é exploração hoje; são coerência do histórico, higiene de log e limites já conhecidos de um desenho que o ADR-005 aceitou.
