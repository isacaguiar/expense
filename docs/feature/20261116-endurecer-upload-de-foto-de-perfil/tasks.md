# Tasks — Endurecer o upload da foto de perfil (SVG e extensão do cliente)

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261116

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-647 | Acrescentar `nosniff` e CSP `sandbox` à foto servida por `UserPhotoController` | backend | plan.md §1 | nenhum | Concluída |
| TASK-648 | Derivar a extensão da foto de perfil pelo conteúdo em `AvatarStorage::store` | backend | plan.md §2 | nenhum | Concluída |
| TASK-649 | Trocar `image` por `AvatarStorage::UPLOAD_RULE` sem SVG em `uploadPhoto` | backend | plan.md §3 | antes do merge (muda contrato, D2) | Concluída |
| TASK-650 | Mostrar no Perfil o motivo que o servidor deu ao recusar a foto | frontend | plan.md §4 | nenhum | Pendente |
| TASK-651 | Rodar `security-reviewer` no delta e a suíte cheia do backend | backend | plan.md §6 | nenhum | Pendente |
| TASK-652 | Atualizar o `01-specify.md` com a regra da foto de perfil | doc | plan.md §5 | nenhum | Pendente |

Ordem: 647, 648, 649, 650, 651, 652. A 649 só depois da 647 e da 648.

## Critérios de aceite

- **TASK-647**: `GET` da rota assinada `user.photo` responde com `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` e `Content-Disposition` com `inline`; um SVG com `<script>` gravado direto em `avatares/<id>/` e apontado por `photo_path` (foto antiga) sai com os mesmos cabeçalhos e o mesmo corpo; a rota sem assinatura segue 403; o `UserPhotoTest` existente passa sem edição; mutante (tirar cada cabeçalho e só o `sandbox`) derruba o teste do arquivo novo `UserPhotoHardeningTest`.
- **TASK-648**: um PNG real enviado em `POST /api/user/photo` com nome de cliente de extensão de 210 caracteres responde 200 e grava `avatares/<id>/<uuid>.png`; um JPEG chamado `foto.exe` grava `.jpg`; `UploadedFile::fake()->image('a.jpg')` segue gravando `.jpg`; o `UserPhotoTest` passa sem edição; mutante (voltar a `getClientOriginalExtension`) derruba os testes novos.
- **TASK-649**: `AvatarStorage::UPLOAD_RULE` (`mimes:jpg,jpeg,png,gif,webp|max:5120`) e `UPLOAD_MESSAGES` existem e `uploadPhoto` os usa mantendo `required` e o corpo `{errors: ...}` com 422; um SVG com `<script>` e um SVG renomeado `.png` dão 422 com "A foto deve ser uma imagem JPG, PNG, GIF ou WEBP.", sem gravar arquivo e sem alterar o `photo_path` do usuário; jpg, png, gif e webp (este com conteúdo real) são aceitos; os testes antigos de PDF e de 5 MB seguem verdes; mutante (voltar `image|max:5120`) derruba o teste.
- **TASK-650**: um 422 com `errors.foto` mostra no Perfil a mensagem do servidor; um erro sem corpo segue com "Não foi possível enviar a foto. Tente novamente."; o `accept="image/*"` não muda; testes novos em `Profile.test.tsx` passam, mutante (voltar ao texto fixo) derruba o teste de mensagem do servidor; `tsc --noEmit` limpo e `vitest` do arquivo verde.
- **TASK-651**: `security-reviewer` rodado só sobre o delta (`UserPhotoController`, `AvatarStorage`, `UserController::uploadPhoto`) com 0 crítico/alto, e médios tratados ou levados ao backlog; `vendor/bin/phpunit` completo verde (1 worker, sem outra sessão no banco), Pint limpo.
- **TASK-652**: `docs/sdd/01-specify.md` descreve a foto de perfil com os formatos aceitos, o 422 em português, a extensão pelo conteúdo e os cabeçalhos da rota da foto, sem citar mais o item 156 como pendente, e a versão do documento sobe; links relativos conferidos.

## Fechamento (skill `promover-backlog`, passos 5.7 e 6)

Depois das tasks: `pr-readiness-checker` no HEAD final, **validação local dele** (subir um SVG no Perfil e ver a mensagem; subir uma foto normal e ver o avatar aparecer, o que confirma que o CSP não atrapalha o `<img>`), `Resolução` no item 156 e movê-lo para `concluidos/`, um único PR contra `dev` (merge é dele).
