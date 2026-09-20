# Tasks — Conteúdo e Preços do Site Institucional

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260920

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-294 | Migrar a navegação do `config.php` para destinos absolutos e acrescentar `footer_nav` | frontend | plan.md §1, §3 | nenhum | Concluída |
| TASK-295 | Marcar a página corrente no menu com `aria-current` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-296 | Acrescentar botão hambúrguer com `nav.js` de falha aberta | frontend | plan.md §2 | nenhum | Concluída |
| TASK-297 | Reescrever o rodapé para iterar `footer_nav` | frontend | plan.md §3 | nenhum | Concluída |
| TASK-298 | Acrescentar `plans` e `free_groups_limit` ao `config.php` | frontend | plan.md §4, §5 | nenhum | Concluída |
| TASK-299 | Criar a página de Preços | frontend | plan.md §4 | nenhum | Concluída |
| TASK-300 | Criar a página de Recursos | frontend | plan.md §6 | nenhum | Concluída |
| TASK-301 | Criar a página de Manual de uso | frontend | plan.md §7 | nenhum | Concluída |
| TASK-302 | Criar a página de Contato com os canais vindos do `config.php` | frontend | plan.md §8 | nenhum | Concluída |
| TASK-303 | Criar a página de Sugestões | frontend | plan.md §8 | nenhum | Concluída |
| TASK-304 | Corrigir a afirmação "Grupos ilimitados" na home | frontend | plan.md §5 | nenhum | Concluída |
| TASK-305 | Realinhar os selos do CTA da home com os planos publicados | frontend | plan.md §5 | nenhum | Concluída |
| TASK-306 | Acrescentar `site_url`, `canonical` e metatags de compartilhamento ao `header.php` | frontend | plan.md §9 | nenhum | Concluída |
| TASK-307 | Publicar `robots.txt` e `sitemap.xml` | frontend | plan.md §9 | nenhum | Concluída |
| TASK-308 | Registrar no backlog os achados fora de escopo desta feature | doc | specify.md §3 | nenhum | Concluída |

## Critérios de aceite

- **TASK-294**: `grep -n "href" site/src/config.php` não devolve nenhum `href` começando com `#`. Abrir `/termos.php`, clicar em "Como funciona" e cair na home na seção correta — hoje esse clique não faz nada. O array `footer_nav` existe com os 5 links previstos.
- **TASK-295**: em `/precos.php`, o `<a>` de "Preços" no menu traz `aria-current="page"`; nos demais itens o atributo está ausente. `read_page` confirma.
- **TASK-296**: em viewport de 375px, o botão hambúrguer está visível, `aria-expanded="false"` no estado inicial, vira `"true"` ao abrir, e os 5 itens ficam alcançáveis. Com o JS desabilitado, a lista aparece empilhada e visível — **nunca oculta sem alternativa**, que é o defeito atual de `style.css:633`.
- **TASK-297**: o rodapé exibe os 5 links de `footer_nav`, e o bloco do logo Novemax (item 041, TASK-292/293) continua intacto e apontando para `novemax.com.br` com `target="_blank"` e `rel="noopener"`.
- **TASK-298**: `config.php` expõe `plans` com exatamente dois planos (Gratuito e Pro), o Pro com `badge` de "em breve" e `price` de R$ 4,90, e `free_groups_limit => 3` com comentário citando `backend/app/Http/Controllers/GroupController.php:39` como origem do número.
- **TASK-299**: `/precos.php` responde 200 e exibe os dois planos. O Gratuito cita o limite de grupos interpolado de `free_groups_limit`, nunca um "3" escrito no texto. O card do Pro está marcado como "em breve" e **nenhum `href` dele leva a checkout** — `grep -iE "assinar|checkout|pagamento" site/public/precos.php` não devolve link de compra. A página não menciona limite de membros nem de histórico (gate do specify §2.5).
- **TASK-300**: `/recursos.php` responde 200. Toda capacidade descrita tem contrapartida em `docs/sdd/01-specify.md` §3 — nenhuma funcionalidade inventada.
- **TASK-301**: `/manual.php` responde 200, tem índice de âncoras no topo em que todo link resolve para uma `<section id="...">` existente na própria página, e uma seção de perguntas frequentes.
- **TASK-302**: `/contato.php` responde 200 e lista os canais vindos de `contact_channels`. `grep -c "<form" site/public/contato.php` devolve 0. O item "Contato" do menu não é mais `mailto:`.
- **TASK-303**: `/sugestoes.php` responde 200, sem `<form>`, alcançável pelo rodapé e por um link dentro de `/contato.php`.
- **TASK-304**: `grep -rn "ilimitado" site/` não devolve nenhuma ocorrência que contradiga `MAX_GROUPS_CREATED_PER_USER = 3`. O card da home cita o limite interpolado de `free_groups_limit`.
- **TASK-305**: os selos do CTA não afirmam "Sem cartão de crédito" nem "Cancelamento fácil", e o que afirmam é verificável na página de Preços.
- **TASK-306**: o HTML servido de `/`, `/precos.php` e `/manual.php` traz `og:image` absoluta, `og:url` absoluta, `twitter:card` e `<link rel="canonical">`, cada página com o seu próprio caminho. A URL de `og:image` responde 200.
- **TASK-307**: `/robots.txt` e `/sitemap.xml` respondem 200; o sitemap lista as 8 páginas públicas, não inclui `/app`, e o robots aponta para o sitemap. São uma entrega só porque o `robots.txt` referencia o `sitemap.xml` — separá-los deixaria um dos dois quebrado.
- **TASK-308**: existe um arquivo em `docs/backlog/` para cada achado de `specify.md` §3 marcado como "vai para `docs/backlog/`" (consentimento LGPD do gtag, página 404, três nomes do produto, `updated_at` fixo, ausência de contexto SDD para o site PHP, constante de limite duplicada, asset og:image dedicado), cada um indexado no `docs/backlog/README.md` com ID sequencial a partir do próximo livre.

## Observações

- **Ordem**: seguir `plan.md` §11. TASK-294 a TASK-297 (navegação) antes das páginas, senão elas nascem inalcançáveis no celular. TASK-304/305 depois de TASK-299, porque os selos passam a apontar para a página de Preços. TASK-306/307 por último, porque o sitemap precisa da lista final de páginas.
- **Gate de feature**: merge do PR único em `dev` é gate humano (`00-constitution.md` §5.2). Nenhuma task individual tem gate próprio.
- **Publicação**: `.github/workflows/deploy-site.yml` já publica `site/public/` → `/www/` e `site/src/` → `/src/` a cada push em `main`. As páginas novas e o `nav.js` entram nesse fluxo sem alteração no workflow.
