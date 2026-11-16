# O upload de comprovante aceita SVG e nomeia o arquivo pela extensão do cliente

ID: 154
Origem: docs/feature/20261114-historico-de-comprovantes-e-justificativa/ (TASK-628, revisão do `security-reviewer`, achado B1: **pré-existente**, só repetido pela rota nova de complemento); não bloqueia o PR
Criado em: 2026-11-14
Prioridade: BAIXA
Status: Aberto

## Descrição
Todo upload de comprovante (`pay`, `store` com pagamento, `updatePaymentProof`, `confirmSettlement` e, agora, `settlements/complement`) valida com `image|max:5120` e grava por `ProofStorage::store`:

- **`image` aceita SVG.** No Laravel 10.48 a regra `image` inclui `svg` (`validateImage`); o `shouldBlockPhpUpload` só barra extensões PHP.
- **A extensão do arquivo vem do cliente** (`ProofStorage::store`, `getClientOriginalExtension()`, com `guessExtension()` só como reserva). Um SVG válido pode ser gravado como `uuid.svg` (ou com outra extensão que o cliente escolher).
- **O download serve o arquivo como ele é** (`ProofDownloadController::show`, `Storage::response`): um SVG com `<script>` aberto pelo link assinado (credor e devedor abrem em outra aba) executa na **origem da API**.

Impacto atual, segundo o revisor: limitado. O disco `local` não é servido direto, e o JWT fica no `localStorage` do site web, que é outra origem (a API está em `expense-api.novemax.com.br`); então não há roubo de token, só conteúdo ativo num domínio confiável (phishing). **Conferir que o web não está servido do mesmo host da API** antes de rebaixar a prioridade.

Sugestão (task própria, mexe nos cinco pontos de upload): trocar `image` por `mimes:jpg,jpeg,png,webp` (ou `mimetypes`); derivar a extensão de `guessExtension()` e nunca do cliente; responder o download com `X-Content-Type-Options: nosniff` e `Content-Security-Policy: sandbox` (ou `Content-Disposition: attachment`). Os testes que enviam `UploadedFile::fake()->image('x.jpg')` seguem valendo (o fake gera JPEG).

## Por que importa
É o único caminho para conteúdo ativo servido pela API. Hoje cai num domínio separado do app, mas basta uma mudança de hospedagem para virar XSS de verdade, e o conserto é pequeno e centralizado em `ProofStorage` e no controller de download.
