# Endurecer as respostas e o `cycles_ago` do comprovante: caminho interno nas respostas (B5) e `cycles_ago` sem teto (B6)

ID: 097
Origem: docs/feature/concluidas/202610/20261022-editar-comprovante-depois-de-paga/implementation.md (TASK-451, revisão do agent `security-reviewer`, achados B1, B4, B5 e B6); reproduzido em 2026-10-23 na TASK-464 da mesma feature; `backend/app/Support/ProofStorage.php`, `backend/app/Http/Controllers/ProofDownloadController.php`, `backend/app/Http/Controllers/ExpenseController.php` (`pay`, `confirmSettlement`, `updatePaymentProof`, `summary`)
Criado em: 2026-10-23
Prioridade: MEDIA
Status: Aberto

## Descrição
Dois pontos que sobraram (B5 e B6; B1 e B4 foram para o item 154). Todos são **anteriores** à feature de edição: `pay()` e `confirmSettlement()` já usavam o mesmo código. Cada um foi conferido no código e reproduzido em 2026-10-23 com testes PHPUnit descartáveis (não commitados), contra o MySQL da 3307 e `Storage::fake('local')`.

### B1 e B4: resolvidos pelo item 154
O SVG aceito e servido inline (B1) e a extensão vinda do cliente que estoura a coluna (B4) foram entregues em 2026-11-16 pela feature `docs/feature/20261116-endurecer-upload-de-comprovante/` (item 154, TASKs 641 a 645): `mimes:jpg,jpeg,png,gif,webp` nos cinco pontos de upload, extensão pelo conteúdo (`guessExtension()`), e `nosniff` + CSP `sandbox` no download. A descrição original, com as reproduções, continua no histórico do git deste arquivo. Sobram aqui só B5 e B6.

### B5. O caminho interno do arquivo vai nas respostas
- `Quota::$hidden` só esconde `expense` (`Quota.php:46-48`); `payment_proof_path` é serializado. `pay()` devolve `$quota->fresh()->load('expense')` (`ExpenseController.php:1122`), `unpay()` devolve `$quota->fresh()` (`:1176`), a rota nova também (`:1300`). `confirmSettlement()` devolve `$confirmation->fresh()` (`:1412`) e `SettlementConfirmation` não tem `$hidden` (só `$appends = ['proof_url']`, `:31`), então `proof_path` sai junto.
- **Reprodução:** `pay()` devolve `payment_proof_path: "comprovantes/<groupId>/<uuid>.svg"`; `unpay()` devolve a chave (com `null`); a rota nova devolve o caminho da foto nova.
- **Efeito:** revela o layout do disco privado. Sem exploração direta: o download exige URL assinada e o controlador resolve o arquivo pelo banco. Nenhum cliente lê o campo: `grep` em `frontend/src` (fora de testes) → vazio, e o app Flutter (`app/`) ainda não existe neste checkout.

### B6. `cycles_ago` não tem teto
- As rotas de pagamento validam `nullable|integer|min:0` (`pay` `:1056`, `unpay` `:1150`, `updatePaymentProof` `:1215`, `confirmSettlement` `:1331`); `summary` (`:670`) e `grossDebts` (`:947`) validam só `nullable|integer`, sem limite nenhum.
- **Reprodução:** nas rotas de pagamento, `cycles_ago=999999999` responde **422 em ~46 ms** ("Esta despesa não está paga nessa competência" / "não tem ocorrência nessa competência"): nada quebra. Em **`summary`**, `cycles_ago=1000` responde 200 para um ciclo de **1943-04-01**, e **`cycles_ago=999999999` responde HTTP 500**: `SQLSTATE[HY000]: General error: 1525 Incorrect DATE value: '-83331307-05-01'` numa consulta a `ex_group_cycle_snapshots`.
- **Efeito:** o veredito da revisão ("aceito") vale para as rotas de pagamento, mas `summary` devolve 500 para qualquer membro autenticado que mande um valor absurdo, e o texto do SQL aparece na resposta quando `APP_DEBUG=true`. Não é negação de serviço (a falha é imediata), mas é um 500 evitável. `grossDebts` tem a mesma validação; não foi testado.

### Precondição de infraestrutura
A segurança do disco privado (`storage/app/comprovantes/...`) depende de o docroot do subdomínio de produção ser `.../api/public` e não a raiz do Laravel. A memória do deploy (2026-09-01) registra `docroot do subdomínio é .../api/public`; isso **não foi reverificado ao vivo** nesta feature. Conferência sugerida, de fora: pedir `https://<api>/storage/app/comprovantes/` e `https://<api>/.env` e esperar 404 ou 403 nos dois.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | O download continua por URL assinada de 30 minutos (ADR-005). Nada aqui o troca por URL pública ou sem expiração. |
| R2 | Os tipos que o cliente envia hoje (jpg, jpeg, png, gif, webp) continuam aceitos sem mudança de comportamento. |
| R3 | Qualquer recusa nova de upload responde 422 com mensagem em português, **antes** de gravar em disco, como as demais recusas de `pay()` (a ordem "todas as recusas antes do primeiro acesso ao disco" é a da feature `20261022-editar-comprovante-depois-de-paga`). |
| R4 | Os cabeçalhos de proteção valem para o download de comprovante, não são globais. |
| R5 | Mudar o que uma resposta devolve (B5) ou o que um upload aceita (B1) toca o contrato público (Constitution §4 item 1: nada de alterar contrato de endpoint existente em produção sem depreciação assistida), então é **decisão humana**, não detalhe de implementação. |
| R6 | Nenhuma coluna muda de tipo: alterar `string(255)` é migration destrutiva e tem gate humano (Constitution §4 item 2). |

## Decisões em aberto
A fechar no specify, se o item for promovido. D1, D2 e D3 (SVG, cabeçalhos e extensão) foram decididas e entregues pelo item 154; a numeração abaixo mantém a original.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D4 | Caminho interno nas respostas (B5) | A) esconder `payment_proof_path` (em `Quota`) e `proof_path` (em `SettlementConfirmation`) com `$hidden`<br>B) manter | **A**, depois de confirmar que ninguém lê | Nenhum cliente lê hoje (web: `grep` vazio; Flutter: ainda não existe), então esconder agora custa o mínimo e depois de um cliente consumir vira mudança de contrato. Continua sendo remoção de campo (R5), então a decisão é humana. O `payment_proof_url` (assinado) segue sendo o caminho de acesso e não muda. |
| D5 | Teto de `cycles_ago` (B6) | A) um teto único (por exemplo 600 meses) em `summary`, `grossDebts` e nas rotas de pagamento, com 422 acima dele<br>B) só em `summary`<br>C) deixar como está | **A** | A reprodução mostra um 500 evitável em `summary`, e `grossDebts` tem a mesma validação. Um número único evita três limites diferentes. O valor exato é detalhe do specify: precisa caber na navegação "Próxima competência" da tela, que usa valores negativos em `summary`. |
| D6 | Qual fluxo e em que ordem | A) uma feature via `/promover-backlog` (D4 e D5)<br>B) BFF (`/novo-bug`) para cada ponto | **A** | A Triagem de `docs/bugfix/README.md` tem o gatilho 1 ("toca dado sensível": upload e download de comprovante, `ExpenseController`) e, para D1 e D4, o gatilho 2 (contrato). D2 e D3 são aditivos e protegem logo, inclusive o que já está gravado; D1 e D4 esperam a decisão humana. |
| D7 | Testes de regressão | A) as reproduções acima viram testes permanentes: SVG recusado (se D1=A), cabeçalhos presentes no download, extensão de 210 caracteres respondendo 2xx sem erro, ausência do caminho nas respostas (se D4=A), `summary` com `cycles_ago` gigante respondendo 422<br>B) só os de cabeçalhos | **A** | Cada ponto foi reproduzido com um teste curto; o custo de mantê-los é baixo e é o que impede a volta do defeito. |

## Por que importa
O download hoje serve qualquer arquivo que a regra `image` deixou passar na origem da API, sem as proteções que o navegador pede para conteúdo de usuário. O risco é baixo enquanto API e SPA ficam em origens separadas e o token vai em header, mas **a segurança depende dessa arquitetura** e não do arquivo, e basta uma mudança de hospedagem para ele subir. Os outros três pontos são defeitos comuns: um 500 e um arquivo órfão com uma extensão grande (B4), o layout interno do disco vazando em quatro respostas (B5), e um 500 em `summary` com um parâmetro absurdo (B6).

Tipo sugerido: backend
