# Token de recuperação: `hash_equals`, guardar só o hash e revogar o que sobra depois do uso

ID: 120
Origem: docs/feature/20260930-recuperacao-acesso/specify.md §3 e revisão de segurança de 2026-10-30 (F3, F4 e F5); `backend/app/Http/Controllers/InvitationController.php` (linhas 30, 33, 45-57 e 80-82), `backend/app/Http/Controllers/GroupMemberController.php:63-65`, `docs/sdd/01-specify.md:42`
Criado em: 2026-10-30
Prioridade: BAIXA
Status: Aberto

## Descrição
- **`===` no token** (linhas 30 e 33): um ataque de tempo exigiria medir diferenças de nanossegundos pela rede, contra um token de 128 bits (`bin2hex(random_bytes(16))`), com janela de 60 minutos e limite global por IP; não é explorável de forma realista. A correção é `hash_equals`, uma linha em cada ponto.
- **Token em texto puro no cache** (linhas 80-82 e `GroupMemberController.php:63-65`): o `.env` do deploy não define `CACHE_DRIVER`, então vale o padrão `file` (`storage/framework/cache/data`; inferido, não visto em produção). Só é lido por quem já tem o sistema de arquivos da conta. O `docs/sdd/01-specify.md:42` diz que o token é "comparado por hash", o que o código não faz. Efeito colateral: `optimize:clear` e o `rsync --delete` do deploy apagam tokens pendentes e limites de taxa.
- **`verify` revoga pouco** (linhas 45-57): só o token usado é apagado, então um token de convite de 2 dias e um de recuperação podem coexistir para o mesmo e-mail; a troca de senha não invalida JWTs já emitidos (TTL de 60 minutos, sem rota de refresh); e o `Mail::send` de confirmação fica fora de `try/catch`: se falhar, a resposta é 500 **depois** de a senha já ter sido trocada e o token consumido.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | A mudança é interna: a rota, o corpo e a duração de 60 minutos do token não mudam. |
| R2 | O e-mail de confirmação pós-troca continua existindo (funciona como aviso de troca de senha). |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Guardar o token como hash | A) guardar `sha256` no cache e comparar com `hash_equals`<br>B) manter em claro e só trocar a comparação | **A** | Barato e alinha o código ao que o `01-specify.md` já afirma; o link do e-mail leva o token em claro, e o servidor só confere o hash. |
| D2 | Revogar sessões na troca de senha | A) deixar como está (TTL de 60 minutos)<br>B) invalidar os JWTs anteriores | **A** | B exige lista de revogação ou versão do token; o risco é limitado pelo TTL curto e pela ausência de refresh. |

## Por que importa
Nenhum dos três é explorável hoje de forma realista, mas os três são baratos e ficam no mesmo arquivo do item 118, então valem uma task só.

Tipo sugerido: backend (segurança)
