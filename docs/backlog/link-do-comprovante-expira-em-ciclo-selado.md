# Link "Ver comprovante" do credor expira em ciclo selado: o snapshot congela a URL assinada de 30 minutos

ID: 096
Origem: docs/feature/concluidas/202610/20261022-editar-comprovante-depois-de-paga/specify.md §2.5 (achado incidental); reproduzido em 2026-10-23 na TASK-459 da mesma feature; `backend/app/Models/Quota.php` (`getPaymentProofUrlAttribute`), `backend/app/Http/Controllers/ExpenseController.php` (`summary`, `cycleHistory`, `sealCycleIfSettled`, `collectCycleEntries`)
Criado em: 2026-10-23
Prioridade: MEDIA
Status: Aberto

## Descrição
O comprovante que o credor anexa em `pay()` é baixado por uma **URL assinada de 30 minutos**, gerada pelo accessor `Quota::payment_proof_url` (`Quota.php:70-76`, `URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), …)`; rota com `->middleware('signed')` em `routes/api.php:83-85`). Isso é o desenho do ADR-005 (itens 2 e 3, e o custo aceito na linha 33).

O problema é que essa URL entra no **snapshot de ciclo selado**:

- `collectCycleEntries` põe `'paymentProofUrl' => $quota->payment_proof_url` em cada entrada do ciclo (`ExpenseController.php:1762`, `:1789` e `:1833`);
- `sealCycleIfSettled` (`:1661`) grava `'expenses' => $summary['expenses']` em `GroupCycleSnapshot` (`:1692`), então o texto da URL, com a assinatura e o `expires` daquele instante, fica guardado em JSON (`GroupCycleSnapshot.php`, cast `'expenses' => 'array'`);
- `summary()` de um ciclo selado devolve essa cópia **sem recalcular** (`:683-691`, `'expenses' => $snapshot->expenses` em `:688`), e `cycleHistory()` faz o mesmo (`:925`).

Resultado: trinta minutos depois da selagem, o "Ver comprovante" das despesas desse ciclo aponta para uma assinatura vencida e a rota responde **403**. O link nunca mais volta a funcionar enquanto o ciclo estiver selado.

O comprovante do **acerto do devedor** não sofre isso: `summary()` decora `settlements` ao vivo com `attachSettlementConfirmations` (`:722`, definida em `:1591`, `confirmedProofUrl` em `:1602`), então o link do acerto é gerado a cada leitura.

### Reprodução (2026-10-23, teste PHPUnit descartável, não commitado)
Ciclo de junho com uma quota paga com foto e o acerto confirmado, relógio em 2026-08-19 10:00, `Storage::fake('local')`:

| Instante | O que foi feito | Resultado |
|---|---|---|
| 10:00 | 1ª leitura de `summary?cycles_ago=2` (sela o ciclo); abrir o `paymentProofUrl` devolvido | **200** |
| 10:31 | 2ª leitura do mesmo ciclo; o `paymentProofUrl` é idêntico ao de 10:00 (congelado) | idêntico |
| 10:31 | abrir o `paymentProofUrl` vindo do snapshot | **403** |
| 10:31 | abrir um link gerado agora pelo accessor da mesma quota (controle) | **200** |
| 10:31 | abrir o `confirmedProofUrl` do acerto na 2ª leitura (decorado ao vivo) | **200** |

### Quem é afetado hoje
- `frontend/src/pages/ExpenseManager.tsx` e `frontend/src/pages/Payments.tsx` mostram "Ver comprovante" a partir de `paymentProofUrl` do `summary()`. Em ciclo selado, o link está morto.
- `GroupReports` (Relatórios) **não** usa o link, então o defeito do `cycleHistory()` (`:925`) está latente: nenhum cliente o consome, mas o app Flutter ou uma tela de histórico podem passar a consumir.
- O arquivo continua no disco privado; só some o caminho de abri-lo pela tela.

### Como a feature `20261022-editar-comprovante-depois-de-paga` se relaciona
Ela bloqueia a edição em ciclo selado (422, decisão D4) e manda o credor desfazer o pagamento para corrigir. Desfazer **apaga a foto** (`unpay`) e dessela o ciclo, então hoje, num ciclo selado, o credor nem consegue abrir o comprovante que já tem, nem trocá-lo sem destruí-lo. Esta feature não mexe no snapshot (fora do escopo, specify §2.5 e §3).

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | O prazo de 30 minutos da URL **não muda**: o ADR-005 continua valendo como está. |
| R2 | O snapshot continua sendo a fonte dos valores, saldos e acertos do ciclo selado (imutável). Só o **link** é refeito ao servir. |
| R3 | A URL só é emitida dentro de `jwt.auth`, depois de `authorizeGroupMembership` (ADR-005 item 3). `summary()` e `cycleHistory()` já são assim. |
| R4 | Contrato aditivo (Constitution §4.1): `paymentProofUrl` continua `string \| null`, nos mesmos lugares da resposta. |
| R5 | Despesa sem foto continua com `paymentProofUrl` nulo. |

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como obter um link novo ao servir o snapshot | A) refazer a URL ao servir, achando a quota por `expense_id` e pelas datas do ciclo<br>B) guardar no snapshot só o caminho ou o id da quota e resolver na leitura<br>C) aumentar o prazo da URL (por exemplo 24 h)<br>D) o front pede o link na hora do clique (endpoint novo) | **A** | Já existe precedente no código: `attachSettlementConfirmations` decora o acerto ao vivo sobre o snapshot. Não exige migration nem reescrever snapshots antigos (os que já têm URL vencida passam a funcionar na próxima leitura), não muda o ADR-005 e não muda o contrato. B muda o formato do JSON e deixa os snapshots antigos sem conserto. C só adia o problema e contradiz o ADR-005. D espalha a mudança por todas as telas e cria rota nova. Como a entrada do snapshot não guarda o id da quota (só `id` da despesa, `date` e `quotaNumber`), A resolve a quota pelo mesmo critério com que a entrada foi montada: `Quota::where('expense_id', …)->whereBetween('date_expected', [início, fim])`, numa consulta só por ciclo. |
| D2 | `cycleHistory()` também | A) sim, a mesma função decora nas duas leituras<br>B) só `summary()`, que é o que o front usa | **A** | Nenhum cliente usa o link do histórico hoje, mas deixar o `:925` com o defeito faria ele reaparecer quando uma tela de histórico (web ou Flutter) mostrar "Ver comprovante". Custa a mesma função. |
| D3 | Qual fluxo | A) BFF (`/novo-bug`)<br>B) feature pequena via `/promover-backlog`, com uma task de backend | **B** | A Triagem do BFF (`docs/bugfix/README.md`) tem o gatilho 1 ("toca dado sensível: despesas"): o comprovante é dado financeiro e a correção mexe no `ExpenseController`. Provavelmente marca, então o caminho certo já é a feature. Se a Triagem não marcar, A serve. |
| D4 | Teste de regressão | A) o teste da reprodução acima, virado permanente: sela, avança o relógio em 31 minutos, abre o `paymentProofUrl` do `summary()` e espera 200<br>B) só conferir que a URL muda entre as leituras | **A** | B passaria com uma URL nova e inválida; abrir o link é a prova de que funciona. |

## Por que importa
Todo ciclo que fecha e quita vira histórico selado, e daí em diante o comprovante do credor fica inalcançável pela tela: o arquivo existe, mas o link está sempre vencido. O comprovante serve exatamente para conferir depois, e o custo cresce a cada ciclo que sela. Quem quiser ver a foto de um mês fechado só consegue desfazendo o pagamento, o que **apaga** a foto. O defeito é silencioso (o link aparece normal e só falha ao clicar).

Tipo sugerido: backend
