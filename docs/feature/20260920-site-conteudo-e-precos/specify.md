# Specify — Conteúdo e Preços do Site Institucional

> Feature: o site público deixa de ser uma landing de página única com links mortos e passa a ter navegação funcional, páginas de conteúdo (Recursos, Ajuda, Contato, Sugestões) e uma tabela de planos oficial. Pedido novo, originado de uma revisão crítica do site solicitada pelo usuário em 2026-09-20.

Versão: 1.0 · Criado em: 20260920

---

## 1. Problema

O site institucional (`site/`, PHP puro) foi entregue em `docs/feature/concluidas/202608/20260824-site-institucional-publico/` como uma landing de página única, com dois itens de menu declarados explicitamente como placeholder naquele `specify.md` §3: `Preços` (`href="#"`) e `Contato` (`mailto:`). Desde então o produto ganhou Pix, fechamento de ciclo, acerto de contas, relatórios de histórico, comprovante via WhatsApp, notificações e auto-cadastro — e o site não acompanhou.

A revisão de 2026-09-20 encontrou três classes de problema:

1. **Navegação quebrada.** Os itens de menu são âncoras relativas (`#recursos`). Em `termos.php` e `privacidade.php` essas âncoras não existem, então o menu não leva a lugar nenhum. E abaixo de 640px o menu desaparece por completo (`site/public/assets/style.css:633`, `.main-nav ul { display: none }`) sem nenhum substituto — não há botão hambúrguer.
2. **Afirmações que o produto não sustenta.** A home anuncia "Grupos ilimitados" (`site/public/index.php:11`) enquanto o backend corta em 3 (`backend/app/Http/Controllers/GroupController.php:39`, `MAX_GROUPS_CREATED_PER_USER = 3`). E a faixa final exibe "Sem cartão de crédito" e "Cancelamento fácil" (`site/public/index.php:133-135`), que só fazem sentido diante de um plano pago — inexistente em todo o projeto.
3. **Conteúdo ausente.** Não há manual, ajuda, FAQ, página de contato nem canal de sugestão. O único suporte é o `mailto:` do menu.

Nada disso está coberto por `01-specify.md` (que descreve o produto, não o site) nem por task aberta em `03-tasks.md`.

## 2. Requisitos

### 2.1 O menu funciona em todas as páginas

Os `href` de âncora passam a ser absolutos (`/#como-funciona` em vez de `#como-funciona`), de modo que o menu opere igual na home e em qualquer página interna. A página atual recebe `aria-current="page"`.

Verificável: abrir `/termos.php` e clicar em "Como funciona" leva à home na seção correta.

### 2.2 O menu funciona no celular

Botão hambúrguer visível abaixo de 640px, que abre e fecha a lista de navegação, operável por teclado (`aria-expanded`, `aria-controls`). Hoje a navegação simplesmente some nessa faixa.

Verificável: em viewport de 375px, todos os itens do menu são alcançáveis.

### 2.3 Estrutura de navegação

Menu principal com 5 itens: `Recursos` (`/recursos.php`), `Como funciona` (`/#como-funciona`), `Preços` (`/precos.php`), `Ajuda` (`/manual.php`), `Contato` (`/contato.php`). `Sugestões` fica no rodapé e dentro de Contato — seis itens no topo é excesso.

O rodapé passa a ter: Política de Privacidade · Termos de Serviço · Ajuda · Contato · Sugestões, mantendo o logo da Novemax já entregue pelo item de backlog 041 (TASK-292/293).

### 2.4 Página de Preços — Gratuito e Pro

Substitui o `href="#"`. Dois planos, alimentados por um array em `site/src/config.php` (fonte única):

- **Gratuito** — descreve somente o que o sistema já aplica hoje. CTA leva ao cadastro.
- **Pro — R$ 4,90/mês, marcado como "em breve"** — descreve o que o plano vai adicionar. Nenhum CTA leva a checkout, porque não há cobrança nesta feature.

**Regra de honestidade (normativa para esta feature):** a coluna do Gratuito não pode anunciar limite que o sistema não aplica. Hoje só existe um limite real — 3 grupos criados por usuário.

### 2.5 Decisão pendente — os dois limites que ainda não existem

Os quatro eixos de diferenciação escolhidos não têm o mesmo custo. Dois são gratuitos de anunciar; dois criariam restrição nova para quem já usa o produto:

| Eixo | Situação hoje | Anunciar como diferencial do Pro |
|---|---|---|
| Nº de grupos | Gratuito já limitado a 3 (real, aplicado) | ✅ Sem regressão — o Pro remove um teto que já existe |
| Cobrança automática Pix/WhatsApp | Não existe para ninguém | ✅ Sem regressão — o Pro adiciona algo novo |
| Membros por grupo | **Ilimitado para todos** | ⚠️ Exigiria criar um teto que não existe |
| Histórico e relatórios | **Completo para todos** | ⚠️ Exigiria cortar histórico de quem já tem |

**Recomendação:** a página publica agora apenas os dois eixos sem regressão. Os outros dois só entram quando a cobrança for de fato construída, e aí com regra de manutenção de condições para contas existentes. Anunciar hoje um teto de membros ou de histórico seria prometer uma piora a usuários ativos em troca de nada — o Pro sequer é comprável.

**Este item é gate humano: sem decisão explícita em contrário, a página sai com os dois eixos sem regressão.**

### 2.6 Corrigir as afirmações falsas da home

- O card "Grupos ilimitados" (`site/public/index.php:11`) passa a refletir o limite real de 3 grupos no plano Gratuito.
- Os selos "Sem cartão de crédito" e "Cancelamento fácil" (`site/public/index.php:133-135`) são reescritos para corresponder ao que a página de Preços afirma.

Verificável: nenhuma ocorrência de "ilimitado" em `site/` contradiz `MAX_GROUPS_CREATED_PER_USER`.

### 2.7 Página de Recursos

Expõe o que o produto realmente faz e a landing não conta: grupos e convites, despesas fixas/parceladas/à vista, divisão entre pagadores, ciclo mensal e fechamento, acerto de contas, cobrança via Pix, comprovante via WhatsApp, relatórios de histórico, notificações, login Google.

Restrição: só pode descrever funcionalidade que existe. A fonte da verdade é `docs/sdd/01-specify.md` §3.

### 2.8 Página de Manual de uso / Ajuda

Guia passo a passo do primeiro uso — criar conta, criar grupo, convidar pessoas, lançar despesa, entender a divisão, fechar o ciclo, cobrar e registrar pagamento — com índice de âncoras e uma seção de perguntas frequentes.

### 2.9 Página de Contato

Substitui o `mailto:` do menu. Canais disponíveis, o que informar ao pedir suporte e expectativa de resposta. Sem formulário: nenhum campo, nenhum endpoint, nenhum dado pessoal coletado pelo site.

### 2.10 Página de Sugestões

Como propor melhoria ou reportar problema, e o que acontece com a sugestão depois. Também estática.

### 2.11 Compartilhamento e indexação

`og:image`, `og:url`, `twitter:card` e `canonical` no `header.php`, mais `robots.txt` e `sitemap.xml`. Exige um asset novo de 1200×630 que ainda não existe.

Verificável: o HTML servido de cada página traz as quatro tags, e a imagem responde 200.

### 2.12 `config.php` continua sendo a fonte única

Navegação, planos e canais de contato vivem em `site/src/config.php`, como o próprio arquivo determina ("Qualquer página deve ler daqui em vez de repetir strings soltas"). Nenhuma página nova repete string de menu, preço ou e-mail.

## 3. Fora de escopo desta feature

- **Cobrança de verdade** — gateway de pagamento, assinatura recorrente, tabela de assinaturas, bloqueio de funcionalidade por plano. Esta feature entrega a vitrine; cobrar é épico de backend próprio.
- **Formulário de contato ou de sugestões funcional** — envio real exigiria endpoint público, anti-spam e tratamento LGPD dos dados coletados.
- **Consentimento de cookies / LGPD do Google Analytics.** O gtag carrega em `site/src/templates/header.php:18` sem qualquer consentimento, num site que publica Política de Privacidade. É um achado real, mas não bloqueia nenhuma task acima — vai para `docs/backlog/`.
- **Página 404** — não existe hoje; vai para `docs/backlog/`.
- **Unificação dos três nomes do produto** — "Shared Expense" (landing), "Controle de Despesas Compartilhadas" (legal) e "SCD" (título do app). A divergência landing/legal é deliberada e registrada; o terceiro nome não. Vai para `docs/backlog/`.
- **Alterar o pipeline de publicação.** `.github/workflows/deploy-site.yml` já publica `site/public/` em `/www/` e `site/src/` em `/src/` a cada push em `main` (commit `03e077a855`, TASK-228/229/230). As páginas novas entram nesse fluxo sem mudança nenhuma no workflow. O gate continua sendo o merge em `main`, que dispara o deploy (`00-constitution.md` §5.2).
- **Tradução multi-idioma** e analytics de conversão.
