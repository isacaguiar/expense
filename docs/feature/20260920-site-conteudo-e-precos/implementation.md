# Implementation — Conteúdo e Preços do Site Institucional

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260920

---

## 1. Desvios do fluxo padrão

**Tasks agrupadas por bloco, não uma sub-branch por task.** As 15 tasks são majoritariamente conteúdo estático sem dependência mútua dentro de cada bloco. Foram agrupadas em commits por bloco (navegação, planos e páginas, home, SEO, docs), no mesmo espírito do `Merge TASK-290 e TASK-291 na branch da feature` já praticado no projeto. Nenhuma task ficou sem commit rastreável.

**Item de backlog 041 saiu do escopo durante o planejamento.** O plano original previa `/promover-backlog 041` para trazer o logo da Novemax ao rodapé. Ao sincronizar com `dev` descobriu-se que o item já havia sido concluído em paralelo pelo PR #165 (TASK-292/293). A task foi removida antes de existir; `footer.php` foi alterado preservando o bloco do logo intacto.

**Desvio consciente do `specify.md` §2.11**, aprovado no gate do plan: `og:image` reusa `assets/app-home.png` (1349×592) em vez de um asset dedicado 1200×630, que não existe e é trabalho de design. Registrado como item de backlog 054.

**Correção incidental no índice do backlog.** O item 041 constava simultaneamente como "Aberto" na tabela Índice e como concluído na tabela de concluídos, e o arquivo existia nos dois lugares (`docs/backlog/` e `docs/backlog/concluidos/`) — foi cópia, não movimentação. Como a TASK-308 editava exatamente essa tabela, a linha duplicada e o arquivo residual da raiz foram removidos. A cópia canônica em `concluidos/` (com `Status: Promovido` e seção `## Resolução`) foi preservada.

## 2. Log de implementação

| Task | O que foi feito | Validação |
|---|---|---|
| — | Sincronizar com `dev` e criar `feature/20260920-site-conteudo-e-precos` | `git merge --ff-only origin/dev` → `a18ac0b2cc`; branch criada limpa |
| — | Scaffold da feature e redação de specify/plan/tasks | commit `96829aa3eb` |
| TASK-294 | `nav` com destinos absolutos + `footer_nav` em `config.php` | `grep -n "'href' => '#" site/src/config.php` → vazio |
| TASK-295 | `aria-current="page"` via `basename($_SERVER['PHP_SELF'])` em `nav.php` | `curl -s .../manual.php \| grep -c 'aria-current="page"'` → `1` |
| TASK-296 | Botão hambúrguer + `assets/nav.js` + CSS condicionado a `data-nav` | `read_page` em 375px: botão vira "Fechar menu de navegação" e os 5 links aparecem |
| TASK-297 | `footer.php` itera `footer_nav`, logo Novemax preservado | `php -l` OK; rodapé renderiza os 5 links + logo |
| TASK-298 | `plans` (Gratuito/Pro) e `free_groups_limit => 3` em `config.php` | `php -l` OK |
| TASK-299 | `site/public/precos.php` + CSS de cartões de plano | `curl -o /dev/null -w "%{http_code}"` → `200`; sem link de checkout |
| TASK-300 | `site/public/recursos.php`, conteúdo derivado de `01-specify.md` §3 | `200` |
| TASK-301 | `site/public/manual.php` com índice de âncoras e FAQ | `200`; 9 âncoras resolvem para `<section id>` existentes |
| TASK-302 | `contact_channels` em `config.php` + `site/public/contato.php` | `200`; `grep -c "<form"` → `0` |
| TASK-303 | `site/public/sugestoes.php` | `200`; `grep -c "<form"` → `0` |
| TASK-304 | "Grupos ilimitados" → "Até N grupos, de graça", N vindo do config | `grep -rn "ilimitad" site/` → nenhuma ocorrência contradiz o teto de 3 |
| TASK-305 | Selos do CTA: saíram "Sem cartão" e "Cancelamento fácil" | Inspeção do HTML servido de `/` |
| TASK-306 | `site_url`, `$pagePath`, canonical, `og:url`/`og:image`, `twitter:card` | `curl .../precos.php` → canonical e `og:url` absolutos e distintos por página |
| TASK-307 | `robots.txt` + `sitemap.xml` com 8 URLs, `Disallow: /app/` | `simplexml_load_file` → válido; `grep -c "<loc>"` → `8`; ambos `200` |
| TASK-308 | 7 itens de backlog (048–054) + índice atualizado | Arquivos criados e indexados; duplicata do 041 removida |

### Verificação funcional do defeito original

O defeito central da feature — o menu não funcionar em páginas internas — foi verificado ponta a ponta no navegador, em viewport de 375px:

1. Abrir `/manual.php`; `read_page` confirma que os links de navegação **não estão no acessibility tree** (colapsados).
2. Clicar no hambúrguer; o botão passa a "Fechar menu de navegação" e os 5 links aparecem com `href` absolutos.
3. Clicar em "Como funciona" (`/#como-funciona`); o navegador sai de `/manual.php`, carrega a home e ancora na seção "Como funciona em 3 passos simples".

Antes desta feature, o passo 1 era impossível (não havia hambúrguer, e a lista tinha `display: none` incondicional) e o passo 3 não fazia nada (a âncora `#como-funciona` não existe em `/manual.php`).

### Checklist pré-PR

| Item | Resultado |
|---|---|
| `php -l` em todos os `.php` de `site/` | Sem erros de sintaxe (12 arquivos) |
| Todas as rotas respondem 200 | 8 páginas + `robots.txt` + `sitemap.xml` + `assets/nav.js` + `assets/app-home.png` |
| `sitemap.xml` é XML válido | `simplexml_load_file` retorna objeto |
| Console do navegador | Sem erros |
| Diff sem segredos | Nenhuma credencial, chave ou token |
| Backend/frontend tocados? | Não — a feature é inteiramente `site/` e `docs/` |

> Pint, PHPUnit, `tsc` e Vitest não se aplicam: nenhum arquivo de `backend/`, `frontend/` ou `app/` foi alterado.

## 3. Gates pendentes

- **Merge do PR em `dev`** — revisão humana (`00-constitution.md` §5.2).
  PR: https://github.com/isacaguiar/expense/pull/168
- **Publicação em produção** — `.github/workflows/deploy-site.yml` continua com `local-dir: ./` e `server-dir` comentado. Enquanto o destino não for definido pelo dono, nada desta feature chega ao ar. Fora do escopo, registrado em `specify.md` §3.
