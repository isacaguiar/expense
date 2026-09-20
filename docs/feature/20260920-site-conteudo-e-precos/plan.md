# Plan — Conteúdo e Preços do Site Institucional

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260920

---

## 0. Restrições herdadas do site

Convenções fixadas em `docs/feature/concluidas/202608/20260824-site-institucional-publico/plan.md` §1 e mantidas aqui: PHP puro com `declare(strict_types=1)`, sem framework e **sem build step**; todo texto dinâmico sai por `e()`; includes por `__DIR__`; HTML5 semântico; **sem `style` ou `script` inline**; document root em `site/public/`.

Consequência prática: nada de npm, bundler ou CSS framework. O que for JavaScript vira arquivo externo em `site/public/assets/`.

## 1. Menu funciona em todas as páginas (specify §2.1)

- Os `href` em `config.php` passam a ser **absolutos** (`/recursos.php`, `/#como-funciona`). O site é servido na raiz do domínio (`docs/feature/concluidas/202608/20260829-deploy-topologia-unificada/`, app em `/app`), e `php -S -t site/public` também serve na raiz — então o caminho absoluto se comporta igual em dev e produção. Caminho relativo é justamente o que quebra hoje em `termos.php`.
- `nav.php` marca a página corrente comparando `basename($_SERVER['PHP_SELF'])` com o `href` do item, e aplica `aria-current="page"`. Comparação por basename evita depender de query string.

## 2. Menu no celular (specify §2.2)

- Botão `<button class="nav-toggle">` em `nav.php`, com `aria-expanded` e `aria-controls` apontando para o `<ul>` da navegação.
- Comportamento em `site/public/assets/nav.js` (arquivo novo, ~15 linhas, carregado com `defer`). Não pode ser inline pela convenção §0.
- **Progressive enhancement, e essa é a decisão que importa:** o CSS só colapsa a navegação quando o JS marcou `document.documentElement.dataset.nav = 'collapsed'`. Se o JS falhar ou não carregar, a lista aparece empilhada e visível. Hoje o `display: none` é incondicional — ou seja, o site já falha fechado. Inverter isso é o ponto.
- O `@media (max-width: 640px)` existente (`style.css:633`) deixa de esconder o `ul` sem alternativa e passa a governar o layout colapsado.

## 3. Estrutura de navegação (specify §2.3)

- `config.php` `nav` passa a: Recursos `/recursos.php` · Como funciona `/#como-funciona` · Preços `/precos.php` · Ajuda `/manual.php` · Contato `/contato.php`.
- Novo array `footer_nav` em `config.php`: Política de Privacidade · Termos de Serviço · Ajuda · Contato · Sugestões. `footer.php` itera sobre ele em vez de repetir `<a>` fixos, mantendo intactos o bloco do logo Novemax e o `&copy;` já entregues pelo item 041.
- `Sugestões` fica fora do menu principal de propósito (5 itens no topo é o teto confortável) e é alcançável pelo rodapé e por um link dentro de `contato.php`.

## 4. Página de Preços (specify §2.4 e §2.5)

- Novo array `plans` em `config.php`, fonte única: por plano, `name`, `price`, `period`, `badge` (ex.: `em breve`), `highlight` (bool), `features[]`, `cta_label`, `cta_href`.
- `precos.php` renderiza os dois planos lado a lado reusando `.container`, `.section`, `.feature-grid` e `.btn` — classes que já existem em `style.css`. Só os estilos realmente novos (cartão de plano, selo "em breve") entram no CSS.
- **Gate §2.5 resolvido pelo usuário:** publicam-se apenas os dois eixos sem regressão — **nº de grupos** e **cobrança automática Pix/WhatsApp**. Membros por grupo e janela de histórico **não aparecem** na página.
- O card do Pro é visualmente secundário e **não tem link de compra**: o CTA leva ao cadastro gratuito. Um botão "Assinar" que não assina seria exatamente o link morto que esta feature veio consertar.

## 5. O número 3 e sua fonte da verdade (specify §2.6)

- O limite de grupos é uma constante do backend (`backend/app/Http/Controllers/GroupController.php:39`), e o site é um deploy separado que não consegue lê-la em runtime.
- Decisão: `config.php` guarda `'free_groups_limit' => 3` com um comentário apontando o arquivo:linha de origem, e **tanto `index.php` quanto `precos.php` leem daí** — nunca escrevem "3" no meio do texto. Assim a divergência fica em um lugar só, e não em três.
- O card "Grupos ilimitados" (`index.php:11`) vira algo como "Até N grupos", interpolando o valor.
- Os selos do CTA (`index.php:133-135`) perdem "Sem cartão de crédito" e "Cancelamento fácil" — ambos pressupõem cobrança — e passam a afirmar o que é verdade: grátis para começar, sem cobrança hoje.
- O risco residual (constante duplicada entre backend, frontend React e site) vira item de backlog; não é resolvível dentro desta feature.

## 6. Página de Recursos (specify §2.7)

- `recursos.php` usa `.container`/`.section`, não `.page` (que tem `max-width: 760px`, estreito demais para um grid).
- Conteúdo derivado exclusivamente de `docs/sdd/01-specify.md` §3. Regra de redação: nenhuma capacidade entra sem que exista rota ou tela correspondente.
- Reusa `icon()` de `helpers.php`, que já tem 24 ícones — incluindo `credit-card`, `bell`, `settings` e `shopping-cart`, hoje sem uso em nenhuma página.

## 7. Manual de uso (specify §2.8)

- `manual.php` usa `.page` (coluna estreita é o formato certo para leitura corrida) com índice de âncoras no topo, uma `<section id="...">` por etapa e um bloco de perguntas frequentes ao final.
- Cada passo descreve a tela real do app. Onde houver dúvida sobre o comportamento atual, a fonte é `01-specify.md` §3 — não a memória.

## 8. Contato e Sugestões (specify §2.9 e §2.10)

- `contato.php` e `sugestoes.php` usam `.page`. Novo array `contact_channels` em `config.php`.
- **Zero formulário**: nenhum `<form>`, nenhum campo, nenhum endpoint. O site continua sem coletar dado pessoal algum, o que mantém a Política de Privacidade vigente válida sem alteração.
- `contact_email` continua vindo de `config.php` (já existe) e o item `Contato` do menu deixa de ser `mailto:`, virando página — o `mailto:` passa a ser um dos canais *dentro* dela, que é onde ele faz sentido.

## 9. Compartilhamento e indexação (specify §2.11)

- Novo `'site_url' => 'https://expense.novemax.com.br'` em `config.php`.
- Cada página define `$pagePath` (ex.: `/precos.php`); `header.php` monta `og:url` e `<link rel="canonical">` como `site_url . $pagePath`. Explícito por página em vez de derivar de `$_SERVER['REQUEST_URI']`, que traria query string para dentro da canonical.
- `header.php` ganha `og:image` (absoluta), `og:image:width`/`height`, `twitter:card = summary_large_image`.
- `robots.txt` e `sitemap.xml` estáticos em `site/public/`, listando as 7 páginas públicas. `sitemap.xml` deve excluir `/app`.
- **Desvio consciente do specify §2.11:** em vez de bloquear a feature por um asset 1200×630 que não existe, reusa-se `assets/app-home.png` (1349×592, proporção próxima) como imagem interina, e o asset dedicado vira item de backlog. Produzir uma imagem de compartilhamento boa é trabalho de design, não de código, e a alternativa hoje é não ter preview nenhum.

## 10. `config.php` como fonte única (specify §2.12)

Ao final, `config.php` concentra: `brand_name`, `legal_name`, `tagline`, `description`, `contact_email`, `updated_at`, `app_login_url`, `app_signup_url`, `nav`, **`footer_nav`**, **`plans`**, **`free_groups_limit`**, **`contact_channels`**, **`site_url`**. Nenhuma página nova repete string de menu, preço, e-mail ou URL.

## 11. Ordem de execução

Há dependência técnica real, então a ordem não é livre:

1. **`config.php` primeiro** — `nav`, `footer_nav`, `plans`, `free_groups_limit`, `contact_channels`, `site_url`. Todas as páginas novas leem daqui; construí-las antes significaria reescrevê-las depois.
2. **Navegação** (âncoras absolutas → `nav.php`/`nav.js`/CSS do hambúrguer → `footer.php`) — precisa existir antes das páginas novas, senão elas nascem inalcançáveis no celular e com menu quebrado.
3. **Páginas de conteúdo**, em qualquer ordem entre si: `precos.php`, `recursos.php`, `manual.php`, `contato.php`, `sugestoes.php`. Sem dependência mútua.
4. **Correção da home** (`index.php`) — depende de `free_groups_limit` (§1) e da página de Preços já existir, para que os selos possam apontar para algo real.
5. **SEO por último** (`header.php`, `robots.txt`, `sitemap.xml`) — o `sitemap.xml` precisa da lista final de páginas, e o `$pagePath` precisa que todas já existam.
