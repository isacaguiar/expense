# Foto de perfil: `AvatarStorage::delete` não confina o path e a troca de foto apaga a antiga antes de gravar a nova

ID: 159
Origem: feature `20261116-endurecer-upload-de-foto-de-perfil` (TASK-651, `security-reviewer`, 2 achados informativos); `backend/app/Support/AvatarStorage.php:53-60` e `backend/app/Http/Controllers/UserController.php:89-90`
Criado em: 2026-11-20
Prioridade: BAIXA
Status: Promovido para TASK-673

## Descrição
Dois achados informativos da revisão de segurança do delta da foto de perfil, sem exploração hoje:

1. **`AvatarStorage::delete` não confina o path a `avatares/`.** Apaga qualquer caminho que receber no disco `local`. Hoje só recebe `users.photo_path`, que o próprio servidor grava (`avatares/<userId>/<uuid>.<ext>`); se um dia esse campo vier de outra origem (importação, edição manual, bug), o `delete` poderia remover arquivo de outra área. Defesa em profundidade: recusar (no-op) caminho que não comece por `avatares/` ou que contenha `..`.
2. **Em `uploadPhoto`, o `delete` da foto antiga roda antes do `store` da nova** (linhas 89-90). Se o `store` falhar (disco cheio, erro de I/O), o usuário fica sem a foto antiga e com `photo_path` apontando para um arquivo que não existe (o `save` não chega a rodar, mas o arquivo já foi apagado). Ordem mais segura: gravar a nova, salvar o `photo_path`, e só então apagar a antiga.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Confinar o `delete` | A) só `AvatarStorage`<br>B) também `ProofStorage::delete` | **A** agora; B vira item próprio se a revisão achar o mesmo | Escopo mínimo; o comprovante tem layout de path diferente |
| D2 | Ordem na troca de foto | A) store, save, delete da antiga<br>B) manter | **A** | Falha no meio não deixa o usuário sem foto; custo: em falha no `delete` sobra um arquivo órfão (inofensivo, varrível) |

## Por que importa
Nenhum dos dois é explorável hoje; são robustez: o primeiro impede que um dado ruim em `photo_path` apague arquivo alheio, o segundo evita perder a foto em falha de gravação.

Tipo sugerido: backend (sem mudança de contrato nem migration)
