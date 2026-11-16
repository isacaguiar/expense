# Tasks — Endurecer o upload de comprovante (SVG e extensão do cliente)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261116

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-641 | Acrescentar `nosniff` e CSP `sandbox` ao download de comprovante | backend | plan.md §1 | nenhum | Concluída |
| TASK-642 | Derivar a extensão do arquivo pelo conteúdo em `ProofStorage::store` | backend | plan.md §2 | nenhum | Concluída |
| TASK-643 | Trocar `image` por uma regra única sem SVG nos cinco pontos de upload | backend | plan.md §3 | antes do merge (muda contrato, D2) | Concluída |
| TASK-644 | Conferir o 422 de formato no front e ajustar o `accept` se for barato | frontend | plan.md §4 | nenhum | Concluída |
| TASK-645 | Rodar `security-reviewer` no delta e a suíte cheia do backend | backend | plan.md §6 | nenhum | Pendente |
| TASK-646 | Reduzir o item 097 a B5 e B6, abrir o item da foto de perfil e atualizar o `01-specify.md` | doc | plan.md §5 | nenhum | Pendente |

Ordem: 641, 642, 643, 644, 645, 646. A 643 só depois da 641 e da 642.

## Critérios de aceite

- **TASK-641**: `GET` de download assinado para `quota`, `settlement` e `settlement_history` responde com `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` e `Content-Disposition: inline`; um SVG com `<script>` gravado direto no disco (arquivo antigo) responde com os mesmos cabeçalhos; os testes de `ProofDownloadControllerTest` e `ProofDownloadHistoryTest` passam; mutante (tirar cada cabeçalho) derruba o teste.
- **TASK-642**: um PNG real enviado com nome de cliente de extensão de 210 caracteres responde 2xx, grava `comprovantes/<grupo>/<uuid>.png` e não deixa arquivo órfão; um JPEG chamado `foto.exe` grava `.jpg`; `UploadedFile::fake()->image('x.jpg')` segue gravando `.jpg`; os testes que conferem o formato do caminho passam; mutante (voltar a `getClientOriginalExtension`) derruba os testes.
- **TASK-643**: `ProofStorage::UPLOAD_RULE` existe e os cinco pontos (`ExpenseController` 564, 1429, 1627, 1764 e `SettlementDecisionController` 164) a usam, mantendo `nullable` ou `required` na frente; em cada um, um SVG com `<script>` e um SVG renomeado `.png` respondem 422 com mensagem em português, sem gravar arquivo; jpg, jpeg, png, gif e webp continuam aceitos; os testes antigos seguem verdes (qualquer edição declarada no `implementation.md`); mutante (voltar `image` em um ponto) derruba o teste daquele ponto.
- **TASK-644**: em cada fluxo (cadastro com pagamento, pagar, editar comprovante, enviar comprovante, complemento) o 422 de formato aparece como texto legível na tela, comprovado por teste do front ou conferência no navegador; se for trocado, `PaymentProofFields` usa `accept="image/jpeg,image/png,image/gif,image/webp"` e o teste dele cobre; `tsc --noEmit` limpo e vitest dos arquivos tocados verde; se nada precisar mudar, o `implementation.md` registra a conferência e a task fecha sem diff de código.
- **TASK-645**: `security-reviewer` rodado só sobre o delta (download, `ProofStorage`, as cinco regras) com 0 crítico/alto, e médios tratados ou levados ao backlog; `vendor/bin/phpunit` completo verde (1 worker, sem outra sessão no banco), Pint limpo.
- **TASK-646**: o item 097 e a linha dele no `docs/backlog/README.md` citam só B5 e B6, com a nota "B1 e B4 resolvidos pelo 154"; existe item novo do backlog para a foto de perfil (com a conferência do download do avatar), com linha no README; o `docs/sdd/01-specify.md` descreve os tipos aceitos e os cabeçalhos do download; links relativos conferidos.

## Fechamento (skill `promover-backlog`, passos 5.7 e 6)

Depois das tasks: `pr-readiness-checker` no HEAD final, validação local dele, `Resolução` no item 154 e movê-lo para `concluidos/`, um único PR contra `dev` (merge é gate dele).
