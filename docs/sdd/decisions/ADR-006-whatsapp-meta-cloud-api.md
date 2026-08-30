# ADR-006: WhatsApp via Meta Cloud API como canal de mensageria

Status: Aceita
Data: 2026-08-29

## Contexto

A feature `docs/feature/20260829-whatsapp-comprovante-pagamento/` precisa enviar
uma mensagem de WhatsApp para a contraparte quando alguém anexa um comprovante
de pagamento (credor em `ExpenseController@pay`, devedor em
`@confirmSettlement`). O projeto já tinha preparado o terreno — `ex_users.whatsapp`
e `ex_users.notify_whatsapp` existem desde a migration `2026_08_22_193236` — mas
não havia **nenhuma** integração de mensageria: sem provider, sem SDK, sem
`app/Jobs`, `QUEUE_CONNECTION=sync`.

Escolher o canal de envio é decisão de stack (`00-constitution.md` §3 / §5): trava
uma dependência externa nova, um segredo novo (`WHATSAPP_TOKEN`) e o formato das
mensagens.

Restrições do ambiente:

- Hospedagem compartilhada (plano M, **sem SSH**) — não roda worker de fila
  persistente nem `schedule:run` confiável. Entrega tem que caber no ciclo da
  request.
- Só `guzzlehttp/guzzle` disponível; qualquer coisa via `Illuminate\Support\Facades\Http`.
- Mensagens são sempre **iniciadas pelo negócio** (o usuário não abre conversa
  com o número) → em qualquer provider oficial isso exige *template* pré-aprovado.

## Decisão

Adotar a **Meta WhatsApp Cloud API** (`graph.facebook.com/{version}/{phone_number_id}/messages`)
como canal de mensageria do projeto, chamada por uma única requisição HTTP com
`Http::` — sem SDK de terceiros.

- Cliente isolado em `App\Support\WhatsApp\MetaCloudClient` (só faz o POST do
  template, lança em não-2xx).
- Domínio (quem recebe, o que a mensagem diz) em `App\Support\WhatsApp\WhatsAppNotifier`.
- Configuração em `config/services.php` → bloco `whatsapp`, com flag
  `WHATSAPP_ENABLED` (default `false`): a feature sobe desligada e só liga em
  produção depois dos templates aprovados na Meta e dos secrets configurados
  (gate humano — expor segredo + deploy, `00-constitution.md` §5.2).
- Entrega **best-effort pós-resposta**: `dispatch(Closure)->afterResponse()` nos
  call sites; falha de envio só vira `Log::warning` e nunca altera o resultado
  da ação que disparou. Sem retry.
- v1 manda só **texto + botão de link** para o app; a imagem do comprovante não
  trafega pelo WhatsApp.

## Consequências

- Custo zero até o volume gratuito da Meta (1.000 conversas de serviço/mês na
  faixa atual); acima disso, tabela de preço por conversa iniciada pelo negócio.
- Dependência operacional nova, fora do código, antes de `WHATSAPP_ENABLED=true`:
  app na Meta, número verificado, token permanente de System User com
  `whatsapp_business_messaging`, e **dois templates aprovados** (categoria
  UTILITY, `pt_BR`) — aprovação leva de horas a dias. Detalhe em
  `docs/feature/20260829-whatsapp-comprovante-pagamento/implementation.md`.
- `WHATSAPP_TOKEN` entra na lista de segredos do projeto (`deploy-backend.yml` +
  secrets do repositório). Rotação/exposição = gate humano.
- Entrega não é garantida: sem worker, uma indisponibilidade da Meta no instante
  do envio perde a notificação (fica só no log). Aceito para v1 — o dado
  canônico (pagamento/comprovante) já está gravado e visível no app; a
  notificação é conveniência. **Migrar para fila durável com retry** (tabela
  `jobs` + `queue:work` disparado por cron do cPanel, ou outra hospedagem) fica
  como evolução futura, e provavelmente vira ADR própria por mexer em infra.
- O canal fica genérico o suficiente (`MetaCloudClient::sendTemplate`) para
  outros eventos reusarem depois (ex.: item 020 do backlog — sistema de
  notificações), sem novo ADR se continuar sendo Meta Cloud API + template.

## Alternativas consideradas

- **Twilio (WhatsApp)**: setup rápido, mas custo por mensagem desde a primeira e
  ainda assim exige template aprovado para mensagem fora da janela de 24h — não
  remove a principal fricção (aprovação de template) e adiciona custo fixo.
  Rejeitada.
- **Gateways BR não-oficiais (Z-API, Evolution API)**: mais baratos e aceitam
  mídia livre sem template, mas operam contra os Termos do WhatsApp e expõem o
  número a ban. Inaceitável para um canal que o projeto pretende manter.
  Rejeitada.
- **Zenvia / outro BSP oficial**: equivalente à Cloud API em capacidade, com
  custo e intermediário a mais. Sem ganho que justifique. Rejeitada.
- **Camada de abstração de provider (interface + drivers)**: `WhatsAppNotifier`
  falando com uma `WhatsAppGateway` e um driver por provider. YAGNI — há um único
  provider decidido; a separação `MetaCloudClient` (transporte) × `WhatsAppNotifier`
  (domínio) já permite trocar o transporte depois sem tocar no domínio.
  Rejeitada para agora.
- **Canal `Notification` do Laravel com driver custom**: sem driver oficial de
  WhatsApp/Meta; usar `Notifiable` + canal custom traria classe `Notification`,
  `via()`, `toWhatsApp()` para dois métodos de envio. Cerimônia sem retorno.
  Rejeitada — reconsiderar se e-mail/SMS passarem a sair no mesmo evento.
- **Fila `database` + `queue:work` já nesta feature**: a hospedagem atual não
  roda worker; `sync` executaria o envio dentro da request (latência + risco).
  `afterResponse` é o melhor disponível sem infra nova. Adiado.

## Referências

- `docs/feature/20260829-whatsapp-comprovante-pagamento/` — `specify.md` §2,
  `plan.md` §1/§4/§6, `implementation.md` (checklist Meta + rascunho dos templates).
- `00-constitution.md` §3 (Stack), §5.2 (gates: expor segredo, deploy).
- `backend/config/services.php` → `whatsapp`; `backend/app/Support/WhatsApp/`.
- Backlog item 020 (`docs/backlog/sistema-notificacoes-frontend.md`) — futuro
  consumidor do mesmo canal.
