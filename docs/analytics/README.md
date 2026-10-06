# Analytics — eventos, campanhas e relatório por canal

> Documento vivo: o que o Expense mede no Google Analytics 4, como nomear os links de campanha e como ler o resultado por canal. Origem: item 066 do backlog (`docs/backlog/metricas-aquisicao-ativacao.md`), construído na feature `20261004-metricas-aquisicao-ativacao` (a pasta vai para `docs/feature/concluidas/` quando o PR mergeia em `dev`). A propriedade GA4 e o consentimento vêm da feature concluída `20260920-analytics-app-e-consentimento`.

Versão: 1.0 · Última atualização: 2026-10-04

---

## 1. Como a medição funciona

- Uma propriedade GA4 só (`G-RNQM4DT19G`) para o site institucional (raiz do domínio) e para o app (`/app`). É a mesma origem, então o cookie de consentimento `scd_consent` e os cookies `_ga*` valem para os dois.
- **Sem consentimento não há medição.** O script do Google só é carregado depois de "Aceitar" no banner; enquanto isso, nada sai do navegador. Revogar em "Preferências de cookies" interrompe o envio na hora.
- No app, tudo passa por `frontend/src/analytics/`: `trackPageView` (uma vez por tela) e `trackEvent` (ações, seção 2). No site, o `page_view` é o automático do gtag (`site/public/assets/consent.js`).
- Nenhum envio leva e-mail, nome, valor financeiro, token, `user_id` nem identificador de grupo ou de despesa. O endereço da tela vai sempre sanitizado (ids numéricos viram `:id`, a query é descartada), com uma única exceção: as quatro etiquetas de campanha da seção 3.

## 2. Eventos de ação (app)

| Evento | Quando dispara | Parâmetro | Código (em `frontend/src/pages/`) |
|---|---|---|---|
| `sign_up` | Cadastro concluído: confirmação do código do cadastro por e-mail; troca do código do login Google **quando a conta acabou de ser criada**; ativação da conta de quem foi convidado | `method`: `email`, `google` ou `invite` | `RegisterPage.tsx`, `LoginPage.tsx`, `AcceptInvitePage.tsx` |
| `group_created` | Grupo criado (edição não conta) | nenhum | `GroupForm.tsx` |
| `expense_created` | Despesa registrada | nenhum | `ExpenseForm.tsx` |

Regras que valem para todos:

- Disparam **depois** da resposta de sucesso da API e dentro do handler da ação, nunca em efeito de montagem. Falha da API, validação local que bloqueia o envio e recarregar a tela não medem.
- Todos levam `page_location` sanitizado (`pageLocation.ts`). O gtag, sozinho, usaria o endereço real, que na tela do convite traz e-mail e token.
- `login` não é medido; adicionar um membro ao grupo (`member_invited`) fica para o item 065 do backlog; edição, exclusão e Pix não entram.
- Quem é convidado **não** gera `group_joined`: a pessoa já é membro do grupo no momento do convite (feito no servidor por quem convidou), então não existe um momento de "entrar" no navegador.
- Quem já tinha conta e é adicionado a um grupo não gera `sign_up`: não é um cadastro novo.

**Acrescentar um evento novo**, nesta ordem: (1) incluir o evento e seus parâmetros em `PARAMETROS_PERMITIDOS` e na assinatura de `frontend/src/analytics/trackEvent.ts`; (2) teste em `trackEvent.test.ts` e na página que dispara; (3) esta tabela; (4) o texto da política de privacidade (`site/src/legal/privacidade.php`), se a ação ainda não estiver descrita; (5) marcar como evento-chave no GA4, se for uma conversão.

## 3. Campanhas (UTM)

Todo link que divulga o Expense leva etiquetas de campanha, para o relatório da seção 5 separar os canais.

| Parâmetro | Responde | Exemplos |
|---|---|---|
| `utm_source` | De onde veio o link | `facebook`, `instagram`, `whatsapp`, `reddit`, `newsletter` |
| `utm_medium` | Que tipo de canal | `comunidade`, `social`, `email`, `video` |
| `utm_campaign` | Qual iniciativa | `piloto-30d` |
| `utm_content` | Qual peça | `post-01`, `video-02` |

Formato dos valores: **minúsculas, dígitos, ponto, sublinhado e hífen, de 1 a 64 caracteres**, sem espaços nem acentos. No app, um valor fora desse formato é descartado sozinho, sem afetar os outros, e qualquer outro parâmetro da query (inclusive `utm_term`) nunca é lido.

Exemplo de link para o cadastro:

```
https://expense.novemax.com.br/app/cadastro?utm_source=facebook&utm_medium=comunidade&utm_campaign=piloto-30d&utm_content=post-01
```

Os nomes de campanha do piloto (`utm_campaign`, `utm_content` de cada peça) são definidos junto do kit de divulgação (item 063 do backlog); este documento só fixa o formato. O destino do link pode ser uma página do site ou `/app/cadastro`.

Como o app trata a campanha: lê as quatro etiquetas na hora em que o app carrega e as guarda **só em memória** (nada em `localStorage`, `sessionStorage` ou cookie). O primeiro envio medido da sessão, `page_view` ou evento, leva as etiquetas no `page_location` e as consome; os seguintes não. Isso cobre quem chega por um link de campanha e só aceita o banner depois. Recarregar a página sem as etiquetas na URL perde a campanha pendente. No site, o `page_view` automático já usa o endereço com a query, então a mesma convenção funciona sem código.

**A validar** (item de validação da feature): se o GA4 deriva a campanha da sessão a partir de `page_location`. Se não derivar, a alternativa é enviar `campaign_source`, `campaign_medium`, `campaign_name` e `campaign_content` no mesmo envio.

## 4. Ativação

Uma pessoa está **ativada** quando registrou ao menos uma despesa (`expense_created`) em até **7 dias** depois do cadastro (`sign_up`).

- Não exige criar grupo: quem foi convidado já é membro desde o convite. Quem se cadastra sozinho precisa de um grupo para lançar despesa, e isso entra na conta sem evento próprio.
- Ativação é uma **definição de leitura**, não um evento: ela sai do cruzamento de `sign_up` e `expense_created` no relatório. Os 7 dias são uma convenção; se a exploração de funil do GA4 não permitir impor a janela, leia por coorte semanal de cadastro e anote no relatório o que o GA4 não conseguiu impor.

## 5. Relatório por canal

Use a dimensão de **primeira origem/mídia do usuário** em todas as colunas, para os números serem comparáveis (o nome exato varia com o idioma do console).

| Canal (origem / mídia) | Campanha | Visitantes | Cadastros (`sign_up`) | Ativados (≤ 7 dias) | Cadastro / visitante | Ativação / cadastro |
|---|---|---|---|---|---|---|
| _exemplo:_ facebook / comunidade | piloto-30d | | | | | |
| (direto) / (nenhum) | | | | | | |
| **Total no GA4** | | | | | | |
| **Contas reais no banco** | | | | | | |

A linha de referência vem do banco, com um `SELECT` somente leitura:

```sql
-- Troque as datas (exemplo) pelo período do relatório.
SELECT COUNT(*) FROM ex_users
WHERE email_verified_at >= '2026-10-05 00:00:00' AND email_verified_at < '2026-11-04 00:00:00';
```

Usa-se `email_verified_at` e não `created_at` porque a conta de quem é convidado é criada **na hora do convite**, antes de a pessoa ativá-la; `email_verified_at` é preenchido na ativação, na confirmação do código e no primeiro login Google. É uma referência aproximada, não exata: uma conta antiga que ainda não tinha o e-mail verificado também recebe a data ao vincular o Google ou redefinir a senha, e entraria na contagem sem ser um cadastro novo. A comparação entre as duas últimas linhas mostra quanto a medição deixa de ver.

## 6. Limites conhecidos

O relatório precisa declarar estes limites sempre que for compartilhado:

1. **Quem recusa o banner não aparece.** Sem consentimento o script não carrega, então o GA4 subconta visitantes, cadastros e ativações. Por isso a linha "Contas reais no banco".
2. **Sem atribuição entre dispositivos.** Quem vê o link no celular e conclui o cadastro no computador aparece como direto.
3. **Convidados aparecem como tráfego direto**, a menos que o link do e-mail leve etiquetas; o link de convite não leva.
4. **Cadastros por Google só são medidos com o backend novo no ar** (o sinal de conta nova vem da troca do código). Antes disso, aparecem sem `sign_up`.
5. **A campanha vale a partir do carregamento do app**: recarregar sem as etiquetas na URL a perde.
6. **Atraso de relatório.** Tempo real e DebugView mostram na hora; os relatórios padrão podem levar horas para refletir o dia.

## 7. Checklist do GA4

Feito uma vez, por quem tem acesso de edição à propriedade, e registrado abaixo com data e responsável. Os nomes dos menus podem variar com o idioma.

- [ ] Marcar `sign_up` e `expense_created` como **eventos-chave** (o evento só aparece na lista depois da primeira ocorrência).
- [ ] Registrar `method` como **dimensão personalizada** com escopo de evento.
- [ ] Criar o filtro de **tráfego interno** (tráfego da equipe e de testes).
- [ ] Conferir a **medição aprimorada** (Admin → Fluxos de dados → o fluxo da web). Rolagem, cliques de saída e interações de formulário usam o endereço real da página e, em `/app/aceitar-convite?email=…&token=…`, levariam e-mail e token. Desligar esses eventos ou garantir a redação do próximo item.
- [ ] Configurar a **redação de dados** (Admin → Fluxos de dados → Redigir dados) para as chaves de query `email`, `token`, `google_code` e `code`.
- [ ] No **DebugView**, abrir um link com UTM (seção 3) e conferir que a campanha chega à sessão; se não chegar, aplicar a alternativa `campaign_*`.
- [ ] Montar a **exploração de funil** `sign_up` → `expense_created` por primeira origem/mídia do usuário (seção 5).

Registro de execução:

| Item | Data | Quem | Observação |
|---|---|---|---|
| | | | |

## 8. Desenvolvimento local

- **App:** `VITE_GA_MEASUREMENT_ID` vem vazio em desenvolvimento (`frontend/.env.example`) e nada é carregado. Não defina o ID real ao rodar localmente: a visita contaria na propriedade de produção.
- **Site:** `analytics_id()` (`site/src/helpers.php`) só publica o ID no host de `site_url` (e no `www.` dele); em `localhost` o ID é `null` e o banner não carrega nada.
