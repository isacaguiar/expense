# Specify — Endurecer o upload de comprovante (SVG e extensão do cliente)

> Feature: fechar o único caminho que serve conteúdo ativo (SVG com script) pela origem da API e parar de nomear o arquivo pela extensão que o cliente mandou. Promove o item 154 do backlog (achado B1 do `security-reviewer` na feature `docs/feature/concluidas/202611/20261114-historico-de-comprovantes-e-justificativa/`, TASK-628); o item 097 (de 2026-10-23) já descrevia o mesmo B1 e o B4 e fica de fora, ver §3 e D1.

Versão: 1.0 · Criado em: 20261116

---

## 1. Problema

Todo upload de comprovante valida com `image|max:5120`, grava com a extensão que o cliente mandou e o download serve o arquivo como ele é. Como a regra `image` do Laravel 10.48 inclui `svg`, um SVG com `<script>` é aceito, gravado como `<uuid>.svg` e, aberto pelo link assinado (credor e devedor abrem em outra aba), executa **na origem da API**. Hoje o impacto é limitado: o disco `local` não é servido direto, e o JWT fica no `localStorage` do site web, que é outra origem. Mas é o único caminho para conteúdo ativo servido pela API e basta uma mudança de hospedagem para virar XSS de verdade. O conserto é pequeno e centralizado.

## 2. Achados confirmados

Conferidos no código em 2026-11-16.

### 2.1 `image` aceita SVG nos cinco pontos de upload de comprovante

`'comprovante' => ...|image|max:5120` em `ExpenseController.php:564` (cadastro de despesa com pagamento), `:1429` (`pay`), `:1627` (`updatePaymentProof`), `:1764` (`confirmSettlement`) e `SettlementDecisionController.php:164` (`complement`). A mesma regra existe em `UserController.php:80` (foto de perfil), que **não** é comprovante (§3).

### 2.2 A extensão vem do cliente

`ProofStorage::store` (`backend/app/Support/ProofStorage.php:43-44`) usa `getClientOriginalExtension()` e só cai em `guessExtension()` se vier vazia. Um SVG válido pode ser gravado como `uuid.svg`; e a extensão do cliente é arbitrária (o item 097/B4 mostrou que 210 caracteres estouram a coluna `string(255)` e dão 500, com arquivo órfão em `pay()`).

### 2.3 O download serve o arquivo como ele é

`ProofDownloadController::show` responde com `Storage::disk($disk)->response($path)` (`:30`), sem `X-Content-Type-Options` nem `Content-Security-Policy`. Qualquer SVG já gravado continua sendo servido assim: mudar só o upload não cobre o que já existe.

### 2.4 Regras adotadas (não dependem de decisão)

| # | Regra |
|---|---|
| R1 | O download continua por URL assinada de 30 minutos (ADR-005); nada o troca. |
| R2 | Seguem aceitos os tipos de foto de hoje: jpg, jpeg, png, gif e webp. |
| R3 | Qualquer recusa nova responde 422 em português, **antes** de gravar em disco. |
| R4 | Os cabeçalhos de proteção valem só para o download de comprovante, não são globais. |
| R5 | Nenhuma coluna muda de tipo (migration destrutiva é gate humano, Constitution §4 item 2). |
| R6 | Os arquivos já gravados não são renomeados nem apagados. |

### 2.5 Decisões abertas

Recomendações a aprovar; as marcadas "dele" só ele decide.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que fazer com o item 097 (B1 e B4 coincidem com este; B5 caminho interno nas respostas e B6 `cycles_ago` sem teto não) | A) esta feature fecha B1 e B4 e eu **reduzo o 097** a B5 e B6<br>B) absorver o 097 inteiro aqui<br>C) deixar o 097 como está, com duplicidade | **A** (dele) | B1 e B4 são o mesmo código que o 154; deixar o 097 igual duplica. B5 e B6 mexem em contrato e em `summary`, outro risco e outro teste. |
| D2 | SVG no upload | A) `mimes:jpg,jpeg,png,gif,webp` nos cinco pontos<br>B) manter `image` e proteger só o download<br>C) aceitar e sanitizar | **A**, junto de D3 | SVG não é foto de comprovante; C exige biblioteca. É mudança de contrato (um `.svg` que hoje dá 200 passa a dar 422), por isso a decisão é dele. |
| D3 | Cabeçalhos no download | A) `X-Content-Type-Options: nosniff` e `Content-Security-Policy: default-src 'none'; sandbox`, mantendo `inline`<br>B) `Content-Disposition: attachment`<br>C) as duas | **A** | "Ver comprovante" abre numa aba; `attachment` forçaria download. O CSP com `sandbox` neutraliza também o SVG já gravado. Aditivo, sem mudar contrato. |
| D4 | Extensão do arquivo | A) `guessExtension()` (pelo conteúdo) com `dat` de reserva<br>B) validar a do cliente por lista | **A** | `mimes` já valida pelo conteúdo; muda só o nome interno do arquivo novo. |
| D5 | Foto de perfil (`UserController:80`, `AvatarStorage`, mesmo padrão) | A) fora desta feature, vira item novo no backlog<br>B) entra aqui | **A** | O pedido e o item 154 são do comprovante; o avatar tem rota e download próprios (conferir no plan antes de decidir se o risco é o mesmo). |
| D6 | O front mostra o erro 422 de formato | A) conferir se o `accept` do seletor já barra SVG e se a mensagem do 422 aparece; só então decidir mudança no front<br>B) ajustar o front desde já | **A** | Pode não haver mudança de front: o 422 já chega como mensagem. |

## 3. Fora de escopo desta feature

- **Item 097, pontos B5** (esconder `payment_proof_path` e `proof_path` nas respostas) **e B6** (teto do `cycles_ago`): contrato e `summary`, ficam no 097 (D1-A).
- **Foto de perfil** (`UserController`, `AvatarStorage`): D5-A.
- **Item 155** (4 pontos informativos do histórico): fica no backlog.
- Renomear ou apagar arquivos já gravados (R6); sanitizar SVG.
- Conferir ao vivo o docroot do subdomínio da API (precondição de infraestrutura do 097): gate dele, não código.
