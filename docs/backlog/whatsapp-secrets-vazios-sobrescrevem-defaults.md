# Secrets de WhatsApp ausentes viram string vazia e anulam os defaults de `config/services.php`

ID: 047
Origem: verificação do deploy durante docs/feature/20260919-cadastro-de-usuarios/ (ao conferir como o `.env` de produção é gerado)
Criado em: 2026-09-19
Prioridade: BAIXA
Status: Aberto

## Descrição

**O estado atual não é um defeito**: `ADR-006-whatsapp-meta-cloud-api.md` decidiu que a feature sobe
desligada e só liga em produção "depois dos templates aprovados na Meta e dos secrets configurados".
Hoje nenhum `ENV_WHATSAPP_*` existe (conferido em `gh secret list --env PROD`; só há o ambiente
`PROD`, não há secrets no nível do repositório e o dono é conta pessoal, então não há secrets de
organização) — e o notificador fica corretamente inerte, porque
`WhatsAppNotifier.php:42,72` retorna cedo quando `config('services.whatsapp.enabled')` é falsy.

O problema é como o `.github/workflows/deploy-backend.yml:66-70` escreve essas chaves: ele sempre
emite a linha, mesmo quando a secret não existe, produzindo **valor vazio** em vez de omitir a chave:

```
echo "WHATSAPP_TEMPLATE_EXPENSE_PROOF=${{ secrets.ENV_WHATSAPP_TEMPLATE_EXPENSE_PROOF }}" >> .env
```

Isso torna **inalcançáveis os defaults declarados** em `backend/config/services.php`:

```php
'expense_proof' => env('WHATSAPP_TEMPLATE_EXPENSE_PROOF', 'comprovante_despesa_pago'),
'settlement_proof' => env('WHATSAPP_TEMPLATE_SETTLEMENT_PROOF', 'comprovante_acerto_confirmado'),
```

O segundo argumento de `env()` só vale quando a chave **não existe**. Com `CHAVE=` presente e vazia,
`env()` devolve `''`, e o default nunca é usado. Vale também para `WHATSAPP_API_VERSION` e
`WHATSAPP_LOCALE`, embora essas duas nem sejam escritas pelo workflow hoje.

## Por que importa

É uma armadilha para o dia em que alguém ligar a feature. O caminho natural é cadastrar
`ENV_WHATSAPP_ENABLED=true`, `ENV_WHATSAPP_TOKEN` e `ENV_WHATSAPP_PHONE_NUMBER_ID`, confiando que
os nomes de template caem nos defaults documentados em `config/services.php` — e eles não caem.
`WhatsAppNotifier.php:58,86` passaria nome de template vazio para a Meta Cloud API, e a falha
apareceria como erro da API, longe da causa.

Correções possíveis: emitir a linha no workflow só quando a secret tiver valor; ou cadastrar as
duas secrets de template junto com as demais no momento de ligar; ou remover os defaults de
`config/services.php` para que a configuração tenha uma fonte só.

Tipo sugerido: infra

## Fora do escopo deste item

Ligar o WhatsApp em produção (cadastrar credenciais da Meta) é ação 100% humana —
`docs/sdd/00-constitution.md` §5.2. Este item é só sobre a forma como o workflow monta o `.env`.
