# Convite pendente com aceite ou recusa: quem é adicionado a um grupo continua entrando sem consentimento (D1-B do item 086)

ID: 133
Origem: feature `20261030-saida-e-remocao-de-membro` (PR #262, item 086 do backlog): decisão D1 e risco RK1 do `specify.md` e a lista de fora de escopo (§6), em `docs/feature/concluidas/202610/20261030-saida-e-remocao-de-membro/` (a pasta é arquivada pelo PR #264); `backend/app/Http/Controllers/GroupMemberController.php` (`store`, `:32-92`)
Criado em: 2026-11-02
Prioridade: MEDIA
Status: Aberto

## Descrição
- **Como a entrada funciona hoje** (lido em `GroupMemberController::store`): qualquer membro informa um e-mail. Se já existe `User` com ele, o `store()` faz `attach` direto em `ex_groups_members` e chama `Notifier::groupMemberAdded`. Se o e-mail é novo, cria o `User` com senha aleatória, grava um token de convite (cache, 2 dias), envia o `UserInvitedMail` e **também** faz o `attach` direto. Em nenhum dos dois casos a pessoa aceita ou recusa: ela já é membro quando fica sabendo.
- **O que a feature do item 086 resolveu e o que não resolveu.** Desde o PR #262 a pessoa consegue **sair** (`DELETE /groups/{groupId}/members/{userId}`) e o criador consegue **remover** outro membro. A **entrada** continua sem consentimento. Foi decisão consciente: o specify escolheu a D1-A ("entra direto, como hoje, e passa a poder sair") porque a D1-B "exige migration, notificação de aceite e UI nos dois clientes; pode vir depois, aditivo (candidato a backlog)". Este item é esse candidato.
- **O que sobra do problema original.** Quem é adicionado passa a aparecer para todos os membros (nome, e-mail, e o contato que os membros veem uns dos outros) e pode ser posto como participante de despesas por qualquer membro. A saída tem os bloqueios da D3-B: quem é **participante de despesa fixa ativa**, ou credor de parcela em aberto de outra pessoa, não sai enquanto isso valer. Logo, um membro mal-intencionado ainda consegue prender alguém: adiciona a pessoa, cadastra uma despesa fixa com ela como participante e ela só sai quando a fixa for interrompida. É o RK1 do specify ("vítima presa por bloqueio da D3"), registrado ali como limite conhecido, com a D1-B como correção completa.
- **Escopo provável** (decisões para o Specify de uma futura feature):
  1. Tabela nova de convites pendentes (prefixo `ex_`, migration aditiva): grupo, convidado (usuário ou e-mail), quem convidou, estado e validade.
  2. `store()` deixa de fazer `attach` e passa a criar o convite; endpoints para o convidado listar, aceitar e recusar (o aceite é que faz o `attach`).
  3. Notificações nos dois sentidos: convite recebido (hoje `group_member_added`) e convite aceito ou recusado, para quem convidou; texto novo em `frontend/src/components/notificationText.ts`.
  4. UI para listar e responder convites pendentes e para o convidante ver os pendentes em `GroupMembersForm` (hoje só lista "Membros atuais"); o app Flutter (`app/`, ainda não existe) deve nascer já com isso.
  5. E-mail novo: o `UserInvitedMail` e a `AcceptInvitePage` hoje criam a conta e a pessoa já está no grupo; o aceite do convite precisa conversar com esse fluxo (aceitar o convite ao definir a senha, ou aceitar depois).
  6. Pontos a decidir: validade do convite, reenvio, convite duplicado, convidar quem já saiu, e se o convite pendente conta de algum modo para o limite de grupos (`MAX_GROUPS_CREATED_PER_USER`, que conta grupos **criados**, D4-A).
  7. **Contrato (Constitution §4, item 1):** hoje `store()` responde 201 "Membro adicionado com sucesso" e quem chama espera o membro na lista de imediato (`GroupMembersForm` recarrega a lista). Passar a responder "convite enviado" muda o sentido da resposta; precisa de decisão (campo novo aditivo, como `pending: true`, ou `/api/v2`) e de atualizar web e, quando existir, o app juntos.

## Por que importa
É a correção completa do consentimento, que a saída do grupo só atenua: com o convite pendente ninguém vira membro, participante de despesa ou alvo de cobrança sem aceitar, e a armadilha do RK1 deixa de existir. Tem também o lado LGPD do item 086 (e-mail e dados expostos a um grupo que a pessoa nunca escolheu). A urgência é menor que a do item 086 original porque agora há saída e remoção, por isso `MEDIA`.

Relacionado: o item 128 (sem `throttle` em `POST /groups/{id}/members`) trata do abuso em volume e continua valendo mesmo com o convite pendente; o item 095 (qualquer membro apaga o grupo em cascata) e o 091 (oráculo de e-mail do `GET /pix/generate`) são independentes.

Tipo sugerido: feature, via `/promover-backlog 133`; exige migration aditiva (gate humano antes de qualquer banco compartilhado) e decisão de produto sobre a validade e o contrato.
