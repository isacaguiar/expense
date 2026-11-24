# Foto de perfil: confinar `AvatarStorage::delete` ao diretório do dono (`avatares/<userId>/`)

ID: 162
Origem: feature `20261123-avatar-delete-confinado-e-troca-de-foto-segura` (revisão do `security-reviewer`, achado B1, baixo); `backend/app/Support/AvatarStorage.php:76-80` e chamadores em `backend/app/Http/Controllers/UserController.php:94` e `:107`
Criado em: 2026-11-23
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature 159 confinou `AvatarStorage::delete` a `avatares/` (sem segmento `..`). O `security-reviewer` notou que o confinamento é à pasta inteira, não ao diretório do dono: um `photo_path` adulterado para `avatares/<outro>/x.png` apagaria a foto de **outro usuário**. Hoje não é explorável: nenhum endpoint escreve `photo_path` além de `uploadPhoto` (valor vindo de `store`) e `deletePhoto` (`null`).

Defesa em profundidade proposta: `delete` receber o `userId` e exigir o prefixo `avatares/{$userId}/`. Muda a assinatura e os dois chamadores.

Achado informativo relacionado (B2, não é item próprio): um `photo_path` com byte nulo (`\0`) passa pelo filtro e o Flysystem lança exceção, então o `delete` deixa de ser no-op e `deletePhoto` responde 500; só ocorre com dado corrompido no banco. Pode ser resolvido junto, rejeitando `\0` no mesmo filtro.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como confinar ao dono | A) `delete(?string $path, int $userId)` com prefixo `avatares/{$userId}/`<br>B) manter só `avatares/` | **A** | Fecha o caso do `photo_path` adulterado apontando para outro usuário; custo: assinatura e 2 chamadores. |
| D2 | Byte nulo | A) rejeitar `\0` no filtro (no-op)<br>B) aceitar o risco | **A** | Custa uma linha e mantém o `delete` sempre silencioso. |

## Por que importa
Nenhum dos dois é explorável hoje; é a segunda camada de defesa de uma rota que apaga arquivo, caso um dia `photo_path` venha de outra origem (importação, edição manual, bug).

Tipo sugerido: backend (sem mudança de contrato nem migration)
