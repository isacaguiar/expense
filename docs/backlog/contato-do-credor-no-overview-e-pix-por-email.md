# Minimizar o contato do credor no `/me/overview` e trocar o lookup de `GET /pix/generate` de e-mail para `user_id`

ID: 091
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/implementation.md §8 (achado S6 e decisão D11 da revisão de segurança da TASK-414); `backend/app/Services/PersonalOverviewService.php` (`person()`), `backend/app/Http/Controllers/PixController.php` (`gerarPix()`), `frontend/src/components/PixPaymentDialog.tsx`
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
**O que o overview devolve.** Em `to_pay[]`, o credor (a quem o usuário vai pagar) leva `id`, `name`, `avatar_url`, **`email` e `pix`** (`PersonalOverviewService::person(..., withContact: true)`). Isso vale para toda linha, inclusive quando o acerto já está confirmado ou não pode ser confirmado (`can_confirm = false`), quando o contato não é mais necessário. O devedor, em `to_receive[]`, não leva contato.

**Por que o e-mail está lá.** O `PixPaymentDialog` chama `GET /api/pix/generate?email=…&valor=…`, e `PixController::gerarPix` localiza a pessoa por e-mail (`User::where('email', $request->email)->firstOrFail()`). Sem o e-mail no payload, o cliente não consegue pedir o QR.

**Decisão já tomada (D11, 2026-10-21):** manter o contato do credor como está no Painel. É o mesmo dado que `gross-debts` já mostra a qualquer membro e que `gerarPix` aceita de quem tem grupo em comum. Este item é a minimização que ficou para depois.

**Achado lateral, reproduzido em 2026-10-22** com uma sonda descartável (apagada, nada versionado): `GET /api/pix/generate` com o token de um usuário autenticado responde **`403`** para um e-mail que existe mas não compartilha grupo e **`404`** para um e-mail que não existe. Qualquer conta autenticada consegue, portanto, testar se um e-mail está cadastrado. É da mesma classe da enumeração de e-mail já aceita em `POST /register` (item 045), então **não eleva o risco**; some junto com a troca do lookup.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Mudança **aditiva** (Constitution §4.1): `gerarPix` passa a aceitar `user_id` **além de** `email`; o web atual e qualquer cliente que mande só `email` seguem funcionando até a migração. |
| R2 | A regra de autorização de `gerarPix` (próprio usuário ou quem compartilha grupo) não muda; só muda a chave de busca. Para `user_id` inexistente ou sem grupo em comum, **a mesma resposta** (`403`), para não confirmar existência. |
| R3 | O overview só envia ao cliente o que a tela usa naquele estado. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Alcance da minimização | A) só `gerarPix` aceitar `user_id`; overview e web seguem como estão<br>B) A + o web passa a pedir o Pix por `user_id` e o overview deixa de enviar `email`<br>C) B + `pix` vira um booleano `has_pix` no overview (a chave só sai de `gerarPix`) | **A agora, B/C junto do app (074)** | A não muda contrato nem tela e fecha o oráculo. B e C dependem de o cliente migrar, e o app Flutter nasce já no contrato novo (menos retrabalho). C exige conferir o que `PixPaymentDialog` mostra da chave. |
| D2 | `gerarPix` para `user_id` fora do grupo em comum | A) `403` (como hoje para e-mail existente)<br>B) `404` | **A** | Mantém um único código para "não existe" e "não pode", que é o ponto da mudança. |

## Por que importa
Baixo risco (o dado já é visível a membros), mas é minimização de dado pessoal e de cobrança (LGPD) e remove uma assimetria de resposta. Tem custo menor se feito antes do app, que consumiria o contrato novo desde o início.

Relacionado: 085 (`GET /groups/{groupId}/members` devolve e-mail, Pix e WhatsApp a qualquer autenticado, o que é bem mais grave que este), 074 (app), 045.

Tipo sugerido: backend (+ frontend)
