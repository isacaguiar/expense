# Endurecer o upload e o download de comprovante: SVG aceito, extensão vinda do cliente, download sem cabeçalhos de proteção, caminho interno nas respostas e `cycles_ago` sem teto

ID: 097
Origem: docs/feature/concluidas/202610/20261022-editar-comprovante-depois-de-paga/implementation.md (TASK-451, revisão do agent `security-reviewer`, achados B1, B4, B5 e B6); reproduzido em 2026-10-23 na TASK-464 da mesma feature; `backend/app/Support/ProofStorage.php`, `backend/app/Http/Controllers/ProofDownloadController.php`, `backend/app/Http/Controllers/ExpenseController.php` (`pay`, `confirmSettlement`, `updatePaymentProof`, `summary`)
Criado em: 2026-10-23
Prioridade: MEDIA
Status: Aberto

## Descrição
Quatro pontos do mesmo caminho (`ProofStorage::store` na entrada e `ProofDownloadController::show` na saída). Todos são **anteriores** à feature de edição: `pay()` e `confirmSettlement()` já usavam o mesmo código. Cada um foi conferido no código e reproduzido em 2026-10-23 com testes PHPUnit descartáveis (não commitados), contra o MySQL da 3307 e `Storage::fake('local')`.

### B1. SVG é aceito e servido inline, sem `nosniff` nem CSP
- A regra `image` do Laravel instalado (v10.48.29) inclui `svg`: `vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:1315` (`['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp']`). As três rotas de upload usam `image`: `pay` (`ExpenseController.php:1052`), `updatePaymentProof` (`:1210`) e `confirmSettlement` (`:1328`).
- `ProofDownloadController::show` responde com `Storage::disk($disk)->response($path)` (`:30`). Não há `X-Content-Type-Options` nem `Content-Security-Policy` em lugar nenhum do backend (busca em `backend/app`, `backend/bootstrap`, `backend/config` e `backend/public/.htaccess`: vazia). Se o servidor web de produção acrescenta algum, não foi verificado ao vivo.
- **Reprodução:** `pay()` com um `comprovante.svg` contendo `<script>alert(document.domain)</script>` → **HTTP 200**; abrir o `payment_proof_url` → **200**, `Content-Type: image/svg+xml`, `Content-Disposition: inline`, `X-Content-Type-Options` ausente, `Content-Security-Policy` ausente.
- **Efeito:** o script do SVG roda quando o link é aberto, na origem da API. O impacto prático hoje é baixo, porque a API autentica por header `Authorization: Bearer` (não por cookie) e o SPA fica em outra origem (o token do SPA não é legível dali). Sobe para **ALTA** se o SPA passar a ser servido da mesma origem da API.
- Como a mudança só de upload não cobre o que já está gravado, qualquer SVG já enviado continua sendo servido assim.

### B4. A extensão do arquivo vem do cliente e estoura a coluna de 255
- `ProofStorage.php:40-41` usa `$file->getClientOriginalExtension()` (e só cai para `guessExtension()` se vier vazia). A regra `image` valida pelo **conteúdo**, então um PNG verdadeiro com nome `a.<muitos caracteres>` passa.
- `payment_proof_path` é `string(255)` (`2026_08_22_200000_add_payment_proof_path_to_ex_quotas_table.php:12`) e `ex_settlement_confirmations.proof_path` também (`2026_08_25_220000_create_ex_settlement_confirmations_table.php:30`). O caminho é `comprovantes/<groupId>/<uuid>.<ext>`: com id de 4 dígitos, extensões de 201 a 218 caracteres estouram a coluna e passam o limite de 255 bytes do nome no sistema de arquivos; acima de 218 a gravação falha e cai no 500 da TASK-463.
- **Reprodução** (PNG real, nome do cliente com extensão de 210 caracteres): `pay()` → **HTTP 500** `SQLSTATE[22001] … Data too long for column 'payment_proof_path'`, a quota **continua não paga** e **1 arquivo órfão** fica no disco; a rota nova de edição também responde **500**, mas deixa **0** arquivos (o `catch` apaga o novo). `confirmSettlement` usa o mesmo `store` e a mesma largura de coluna; não foi reproduzido à parte.
- **Efeito:** 500 em vez de 422, e, em `pay()`, um arquivo órfão. Não encontrei como explorar além disso: o nome do cliente é reduzido ao trecho depois da última barra (`vendor/symfony/http-foundation/File/File.php:133-140`, chamado no construtor de `UploadedFile`) e o caminho é montado pelo servidor.

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
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | SVG no upload (B1) | A) trocar `image` por `mimes:jpg,jpeg,png,gif,webp` nas três rotas<br>B) manter `image` e proteger só o download (D2)<br>C) aceitar SVG e sanitizá-lo | **A**, junto de D2 | SVG não é foto de comprovante, e A fecha a porta para arquivos novos. B deixa o script executável no arquivo e só o contém no navegador. C exige uma biblioteca de sanitização e mantém superfície sem ganho para o usuário. Mas A passa a responder 422 para um `.svg` que hoje dá 200: é mudança de contrato (R5), por isso a decisão é humana. D2 é necessária mesmo com A, porque não corrige os SVGs já gravados. |
| D2 | Cabeçalhos no download (B1) | A) `X-Content-Type-Options: nosniff` e `Content-Security-Policy: default-src 'none'; sandbox`, mantendo `inline`<br>B) trocar para `Content-Disposition: attachment`<br>C) as duas coisas | **A** | O "Ver comprovante" abre numa aba; `attachment` forçaria download e pioraria a experiência (B). O CSP com `sandbox` impede script e acesso à origem mesmo num SVG antigo, e `nosniff` impede o navegador de reinterpretar o tipo. Aditivo, sem mudar contrato. Cobre também os arquivos já gravados. |
| D3 | Extensão do arquivo (B4) | A) usar `guessExtension()` (pelo conteúdo), com `dat` de reserva<br>B) validar `getClientOriginalExtension()` por lista e tamanho<br>C) alargar a coluna com migration | **A** | A regra `image` já valida pelo conteúdo, então a extensão do cliente é irrelevante e o nome fica coerente com o arquivo. Muda só o nome interno do arquivo novo, não o contrato. C é migration destrutiva (R6). B duplica uma validação que o conteúdo já faz. Arquivos antigos não são afetados. |
| D4 | Caminho interno nas respostas (B5) | A) esconder `payment_proof_path` (em `Quota`) e `proof_path` (em `SettlementConfirmation`) com `$hidden`<br>B) manter | **A**, depois de confirmar que ninguém lê | Nenhum cliente lê hoje (web: `grep` vazio; Flutter: ainda não existe), então esconder agora custa o mínimo e depois de um cliente consumir vira mudança de contrato. Continua sendo remoção de campo (R5), então a decisão é humana. O `payment_proof_url` (assinado) segue sendo o caminho de acesso e não muda. |
| D5 | Teto de `cycles_ago` (B6) | A) um teto único (por exemplo 600 meses) em `summary`, `grossDebts` e nas rotas de pagamento, com 422 acima dele<br>B) só em `summary`<br>C) deixar como está | **A** | A reprodução mostra um 500 evitável em `summary`, e `grossDebts` tem a mesma validação. Um número único evita três limites diferentes. O valor exato é detalhe do specify: precisa caber na navegação "Próxima competência" da tela, que usa valores negativos em `summary`. |
| D6 | Qual fluxo e em que ordem | A) uma feature via `/promover-backlog`, na ordem D2, D3, D5, depois D1 e D4<br>B) BFF (`/novo-bug`) para cada ponto | **A** | A Triagem de `docs/bugfix/README.md` tem o gatilho 1 ("toca dado sensível": upload e download de comprovante, `ExpenseController`) e, para D1 e D4, o gatilho 2 (contrato). D2 e D3 são aditivos e protegem logo, inclusive o que já está gravado; D1 e D4 esperam a decisão humana. |
| D7 | Testes de regressão | A) as reproduções acima viram testes permanentes: SVG recusado (se D1=A), cabeçalhos presentes no download, extensão de 210 caracteres respondendo 2xx sem erro, ausência do caminho nas respostas (se D4=A), `summary` com `cycles_ago` gigante respondendo 422<br>B) só os de cabeçalhos | **A** | Cada ponto foi reproduzido com um teste curto; o custo de mantê-los é baixo e é o que impede a volta do defeito. |

## Por que importa
O download hoje serve qualquer arquivo que a regra `image` deixou passar na origem da API, sem as proteções que o navegador pede para conteúdo de usuário. O risco é baixo enquanto API e SPA ficam em origens separadas e o token vai em header, mas **a segurança depende dessa arquitetura** e não do arquivo, e basta uma mudança de hospedagem para ele subir. Os outros três pontos são defeitos comuns: um 500 e um arquivo órfão com uma extensão grande (B4), o layout interno do disco vazando em quatro respostas (B5), e um 500 em `summary` com um parâmetro absurdo (B6).

Tipo sugerido: backend
