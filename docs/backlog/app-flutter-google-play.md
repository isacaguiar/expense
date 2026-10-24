# App Flutter do Shared Expense na Google Play

ID: 074
Origem: análise do app na Google Play (conversa de 2026-10-06); substitui o Épico A (`docs/feature/20260817-migracao-frontend-expo/`, ADR-001)
Criado em: 2026-10-06
Prioridade: ALTA
Status: Aberto

## Descrição
Criar o app Android do Shared Expense em Flutter e publicá-lo na Google Play, na conta do Play Console do Disfagia APP. A pasta `app/` nunca existiu e o Épico A (Expo) está todo pendente; a stack passa a Flutter depois do ADR-010, que substitui o ADR-001.

Esboço para o specify (a validar):

- **Escopo v1:** auth (login, Google por App Link, cadastro com código, aceitar convite, recuperação de acesso quando entrar em `dev`), grupos e membros, despesas (lista por ciclo, criar, editar e ver, sem regra de parcelas, que vem do backend), pagamentos (marcar paga com comprovante; acerto com Pix e comprovante; pagar uma despesa individualmente, do 075), resumo, perfil (foto, Pix, WhatsApp, senha), notificações por polling e exclusão de conta. Relatórios ficam para a v1.1.
- **Arquitetura proposta para o plan:** projeto em `app/`, Material 3 espelhando `frontend/src/theme.ts`, `go_router`, Riverpod (ou a preferência do usuário), cliente HTTP único com `Authorization: Bearer`, `flutter_secure_storage` para o JWT, `image_picker` (seletor do sistema), `qr_flutter` para o Pix, `app_links` para o retorno do login Google, `intl` pt-BR. Sem push (FCM) nem analytics na v1.
- **Login Google:** o fluxo atual é OAuth no servidor (`GoogleAuthController`) e termina em `redirect()->away("{$frontendUrl}?google_code=…")`; o app reaproveita o fluxo com **App Link** em `https://expense.novemax.com.br/app/…`, o que exige `assetlinks.json` no site. Sem o app instalado, o mesmo link abre o web.
- **Publicação:** `targetSdk` 36 (exigido para apps novos desde 31/08/2026), AAB com nova chave de upload e Play App Signing, `applicationId` proposto `novemax.sharedexpense` (convenção do Disfagia; permanente, a confirmar), ficha em pt-BR, e as declarações do Console: Data safety, Financial features, classificação etária, política de privacidade (revisar `privacidade.php` para citar o app), URL de exclusão de conta e conta de teste para o revisor.
- **Tasks próprias:** `assetlinks.json` no site, workflow de CI do app (`flutter analyze` e `flutter test`) e validação em aparelho Android real.

Ordem e pré-condições (trilha **070 → 071 → 075 → 074**): depende de **070, 071 e 075 em produção** e do **ADR-010 aprovado** (troca de stack Expo → Flutter e ajuste do SDD, gate humano). Os itens 072 (exclusão de conta) e 073 (versão mínima da API) precisam estar prontos **antes do release**, não do início. A 076 (quitação antecipada da parcelada) é recomendada antes do release, mas não bloqueia o início.

Ações do usuário no Play Console: confirmar o tipo da conta (organização dispensa o teste fechado de 12 testadores por 14 dias; conta pessoal exige), criar o app, reservar o `applicationId` e conferir o registro na verificação de desenvolvedor Android (vale desde 30/09/2026, Brasil primeiro). Chaves e credenciais de publicação são do usuário, nunca em chat nem no repositório.

## Nota (2026-10-22, feature `20261010-painel-financeiro-pessoal`)
O Painel financeiro pessoal criou o contrato que a tela inicial do app precisa e o esboço de escopo v1 acima não cita: **`GET /api/me/overview`**. Devolve, por grupo do usuário e no ciclo em foco, o que ele deve e a quem, o que tem a receber e de quem, os itens de que participa e as flags de ação (`can_confirm`, `can_pay`, `can_unpay`), tudo calculado no servidor, com `cycle.cycles_ago` para o cliente nunca calcular ciclo. Limites: no máximo 50 grupos por chamada (`truncated: true` quando corta) e 30 chamadas por minuto por usuário. O mockup mobile "Meus grupos" (`assets/images/screen/movel.png`) já desenha "A pagar" e "A receber" por grupo, que é exatamente este payload.

Proposta para o specify do app: tratar `/me/overview` como a **fonte da tela "Meus grupos"** (sem rateio nem ciclo no cliente) e como pré-condição na trilha, junto de 070, 071 e 075, **quando a feature do Painel estiver em produção**. Consumir também as ações que ele já usa (`pay`, `unpay`, `settlements/confirm`, com `descricao` opcional). Antes do app consumir, ver os itens 090 (`cycle_start` de conferência nas ações), 091 (contato do credor por `user_id`) e 093 (custo com muitos grupos), que mexem nesse contrato de forma aditiva.

## Por que importa
É o objetivo do produto: disponibilizar o Shared Expense na Google Play. A ordem 070 → 071 → 075 → 074 foi escolhida para reduzir risco: o contrato de parcelas (070, 071) e o de pagamento por participante (075) são provados em produção pelo web antes de o app depender deles, e o app nasce sem nenhuma regra de dinheiro no cliente.

Tipo sugerido: frontend (app Flutter novo, em `app/`)
