# Plan — Endurecer o upload de comprovante (SVG e extensão do cliente)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261116

Decisões do specify aprovadas por ele em 2026-11-16: D1-A, D2-A, D3-A, D4-A, D5-A, D6-A.

---

## 1. Cabeçalhos no download (specify §2.3, D3-A)

**Arquivo:** `backend/app/Http/Controllers/ProofDownloadController.php`, método `show`.

`Storage::disk($disk)->response($path)` devolve uma `StreamedResponse`; o plan acrescenta, na própria resposta, `X-Content-Type-Options: nosniff` e `Content-Security-Policy: default-src 'none'; sandbox` (`$response->headers->set(...)`), mantendo `Content-Disposition: inline`. Vale para os três tipos (`quota`, `settlement`, `settlement_history`), pois todos passam por `show` (R4: só aqui, nenhum middleware global).

**Por que primeiro e por que assim:** é aditivo, não muda contrato e protege inclusive os SVG já gravados (R6). `sandbox` sem `allow-scripts` impede script e acesso à origem num SVG aberto em aba; `nosniff` impede o navegador de reinterpretar o tipo. `attachment` (D3-B) foi descartado porque forçaria download em "Ver comprovante".

**Testes:** estender `ProofDownloadControllerTest` e `ProofDownloadHistoryTest` com asserção dos dois cabeçalhos nos três tipos; um teste com um SVG com `<script>` gravado direto no disco (simula arquivo antigo) conferindo os cabeçalhos e o `Content-Disposition: inline`.

## 2. Extensão pelo conteúdo (specify §2.2, D4-A)

**Arquivo:** `backend/app/Support/ProofStorage.php`, método `store`.

Trocar a linha da extensão para `$file->guessExtension() ?: 'dat'` (nunca `getClientOriginalExtension()`). `guessExtension()` usa o MIME detectado no conteúdo. Muda só o nome interno do arquivo novo; os já gravados ficam (R6). Como o upload passa a aceitar só jpg/jpeg/png/gif/webp (§3), a extensão resultante é uma dessas (`jpg` para JPEG) e o `dat` fica só como reserva.

**Testes:** (a) PNG real com nome de cliente `a.<210 caracteres>` grava como `.png`, responde 2xx e não deixa arquivo órfão (B4 do 097); (b) JPEG com nome `foto.exe` grava como `.jpg`; (c) o teste existente de caminho (`comprovantes/<grupo>/<uuid>.<ext>`) segue verde. A decisão desta task precisa de um número: verificar antes que o `UploadedFile::fake()->image('x.jpg')` dos testes antigos devolve `jpg` em `guessExtension()` (o plan assume que sim; a task confirma).

## 3. Regra de upload única, sem SVG (specify §2.1, D2-A)

**Arquivos:** `backend/app/Support/ProofStorage.php` (nova constante) e os cinco pontos de validação: `ExpenseController.php` linhas 564, 1429, 1627 e 1764, e `SettlementDecisionController.php:164`.

Criar `ProofStorage::UPLOAD_RULE = 'mimes:jpg,jpeg,png,gif,webp|max:5120'` e usá-la nos cinco pontos no lugar de `image|max:5120` (`nullable|` ou `required|` continuam na frente, como hoje). Uma constante só evita cinco listas que divergem. `mimes` valida pelo conteúdo (extensão adivinhada), então um `.png` que na verdade é SVG é recusado. A recusa é 422, antes de gravar em disco (R3) porque é validação de request.

**Mensagem em português:** conferir `backend/lang/pt*/validation.php` (ou o `messages()` do projeto) para a chave `mimes`; se a mensagem não estiver traduzida, a task a acrescenta (ou passa mensagem própria: "O comprovante deve ser uma imagem JPG, PNG, GIF ou WEBP.").

**Testes:** por ponto de upload (cinco), um teste com `UploadedFile::fake()->createWithContent('x.svg', '<svg ...><script>…</script></svg>')` esperando 422 e nenhum arquivo gravado; e um com um SVG renomeado `x.png`, também 422. Conferir que os 101 usos de `image(` nos testes antigos seguem verdes (o fake gera JPEG); se algum usar tipo fora da lista, editar e declarar no `implementation.md`. Ordem: **esta task só depois das §1 e §2**, porque a contenção do download e a extensão protegem mesmo se a regra falhar.

## 4. Front (specify D6-A)

`frontend/src/components/PaymentProofFields.tsx:49` usa `accept="image/*"`, que **deixa o seletor mostrar SVG**. A recusa chega como 422; a task conferirá (a) se o erro de `comprovante` aparece na tela em cada fluxo (cadastro com pagamento, pagar, editar, enviar comprovante, complemento) e (b) se vale trocar o `accept` por `image/jpeg,image/png,image/gif,image/webp` (esconde SVG no seletor; é conveniência, não segurança). **Só muda o front se a conferência mostrar mensagem ausente ou ilegível**, e a troca do `accept` entra se for barata (um componente reaproveitado pelos cinco fluxos). `Profile.tsx:212` (avatar) não muda (D5-A).

## 5. Backlog e documentação (D1-A, D5-A)

- **Item 097:** acrescentar uma nota no topo e reduzir a descrição a B5 e B6 (B1 e B4 viram "resolvidos pelo 154", com o PR), sem mudar o ID nem mover o arquivo. Atualizar o título e a linha do README.
- **Novo item de backlog** para a foto de perfil (mesmo padrão em `UserController:80` e `AvatarStorage::store`); a task confere antes se o avatar tem download próprio e se o risco é o mesmo.
- **Item 154:** `Resolução` e mover para `concluidos/` no fechamento (skill `promover-backlog`, passo 6).
- `docs/sdd/01-specify.md`: acrescentar o que o upload aceita e os cabeçalhos do download (onde o glossário/seção de comprovante já cita `image`; a task localiza).

## 6. Revisão de segurança e fechamento

Rodar o agent `security-reviewer` só no delta (download, `ProofStorage`, as cinco regras) e o `pr-readiness-checker` no HEAD final; suíte cheia do backend, front só se a §4 mexer nele. Validação local dele (stack isolada, ver memória): subir um SVG pelo front e ver o 422; abrir um comprovante antigo e conferir os cabeçalhos. Um PR único contra `dev`.

## 7. Riscos

- **Contrato:** um `.svg` que dava 200 passa a dar 422 (D2, decisão dele). Nenhum cliente envia SVG de propósito (o `accept` é `image/*` de foto); o app Flutter não existe ainda.
- **iPhone/HEIC:** já era recusado por `image` (não está na lista); sem mudança.
- **`mimes` e a validação do `guessExtension`:** depende de `fileinfo` do PHP; em produção já é usado por `image`, sem mudança de dependência.
- **Sem migration, sem coluna nova:** R5 respeitada; deploy comum do backend.
