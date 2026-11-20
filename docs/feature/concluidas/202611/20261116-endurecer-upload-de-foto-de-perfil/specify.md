# Specify — Endurecer o upload da foto de perfil (SVG e extensão do cliente)

> Feature: aplicar à foto de perfil o que o item 154 fez com o comprovante: recusar SVG, nomear o arquivo pelo conteúdo e servir a foto com cabeçalhos de proteção. Promove o item 156 do backlog (achado médio do `security-reviewer` na TASK-645 da feature `docs/feature/concluidas/202611/20261116-endurecer-upload-de-comprovante/`, que deixou o avatar de fora pela decisão D5-A). Em produção desde a `v1.8.0` só o comprovante está protegido.

Versão: 1.0 · Criado em: 20261116

---

## 1. Problema

O comprovante deixou de aceitar SVG, de usar a extensão do cliente e de ser servido sem cabeçalhos (`v1.8.0`). A foto de perfil continua como o comprovante era: um usuário autenticado grava um SVG com `<script>`, ele fica armazenado e é servido na origem da API. Hoje o token vive em outra origem (o site web), então o dano é phishing e não roubo de token, mas a segurança depende dessa arquitetura. O conserto é pequeno e já tem receita.

## 2. Achados confirmados

Conferidos no código em 2026-11-16.

### 2.1 `image` aceita SVG

`UserController::uploadPhoto` (`backend/app/Http/Controllers/UserController.php:80`) valida `'foto' => 'required|image|max:5120'`. No Laravel 10.48 a regra `image` inclui `svg`. A resposta de erro é `{"errors": {...}}` com 422 (usa `Validator::make`, não `$request->validate`).

### 2.2 A extensão vem do cliente

`AvatarStorage::store` (`backend/app/Support/AvatarStorage.php:26-27`) usa `getClientOriginalExtension()` e só cai em `guessExtension()` se vier vazia. O cliente escolhe `.svg`, `.html` ou uma extensão longa que pode estourar `ex_users.photo_path` (coluna `string(255)`, migration `2026_09_03_000001`).

### 2.3 A foto é servida sem cabeçalhos

`UserPhotoController::show` responde `Storage::disk('local')->response(...)` sem `X-Content-Type-Options` nem `Content-Security-Policy`. A rota `GET /user/{userId}/photo` é por URL assinada (`routes/api.php:123`, `signed`), emitida pelo accessor `User::avatar_url`; a foto abre por `<img>` e também por link direto. Qualquer SVG já gravado continua sendo servido assim.

### 2.4 O Perfil não mostra o motivo da recusa

`Profile.tsx:handlePhotoSelected` captura o erro e mostra sempre "Não foi possível enviar a foto. Tente novamente.", sem ler a mensagem do servidor (mesmo defeito que o item 154 corrigiu nos fluxos de comprovante). O seletor usa `accept="image/*"`.

### 2.5 Regras adotadas (não dependem de decisão)

| # | Regra |
|---|---|
| R1 | A foto continua servida só por URL assinada (ADR-005); nada o troca. |
| R2 | Seguem aceitos jpg, jpeg, png, gif e webp, até 5 MB. |
| R3 | A recusa responde 422 em português, antes de gravar em disco. |
| R4 | Os cabeçalhos valem só para a rota da foto, não são globais. |
| R5 | Nenhuma coluna muda; arquivos já gravados não são renomeados nem apagados. |
| R6 | O formato do erro de `uploadPhoto` (`{errors: ...}`) não muda. |

### 2.6 Decisões abertas

Recomendações a aprovar; a D2 é só dele.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde moram a regra e a mensagem | A) constantes próprias em `AvatarStorage` (`UPLOAD_RULE`, `UPLOAD_MESSAGES`, chave `foto.mimes`)<br>B) reaproveitar as do `ProofStorage` | **A** | O comprovante e o avatar são domínios separados (campo `foto` ≠ `comprovante`, texto "A foto…"); um depender do outro acopla sem ganho. A duplicação é uma linha. |
| D2 | SVG no upload | A) `mimes:jpg,jpeg,png,gif,webp` (SVG passa a dar 422)<br>B) manter `image` e só proteger o download | **A** (dele) | Mesma decisão do 154. Muda contrato: um `.svg` que dá 200 passa a dar 422. |
| D3 | Extensão do arquivo | A) `guessExtension() ?: 'dat'`<br>B) validar a do cliente por lista | **A** | Igual ao 154; `mimes` já valida pelo conteúdo. |
| D4 | Cabeçalhos no `UserPhotoController::show` | A) `nosniff` + `Content-Security-Policy: default-src 'none'; sandbox`, mantendo `inline`<br>B) `attachment` | **A** | A foto é usada em `<img>`; o CSP `sandbox` não impede uma imagem em `<img>` de carregar e neutraliza o SVG aberto direto (a conferir no plan com um teste no navegador). `attachment` quebraria o link direto sem necessidade. |
| D5 | O Perfil mostra o motivo do servidor | A) sim: usa a mensagem de `errors.foto` com o texto atual como fallback<br>B) deixa o texto genérico | **A** | Sem isso a pessoa não saberia por que o SVG foi recusado (mesmo achado do 154/TASK-644). |
| D6 | `accept` do seletor do Perfil | A) manter `image/*`<br>B) listar os formatos | **A** | É só conveniência; o seletor de comprovante teve bug no iPhone por isso e o servidor é a autoridade. |

## 3. Fora de escopo desta feature

- **Item 157** (comprovantes legados no disco `public`), **155** (informativos) e **097** (B5 e B6): ficam no backlog.
- Renomear ou apagar fotos já gravadas (R5); sanitizar SVG.
- Foto do Google (`avatar_url` de login social): não é upload.
- Mudar a duração ou o vínculo da URL assinada (ADR-005).
