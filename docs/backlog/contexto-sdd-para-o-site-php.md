# Site institucional não tem documento de contexto no SDD

ID: 052
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Aberto

## Descrição
`docs/sdd/05-context-frontend.md` cobre `expense/frontend` (React) e `expense/app` (Expo); `06-context-backend.md` cobre o Laravel. O site institucional (`site/`, PHP puro) não tem equivalente — suas convenções vivem apenas no `plan.md` §1 de uma feature já arquivada (`docs/feature/concluidas/202608/20260824-site-institucional-publico/`).

Essas convenções são reais e não óbvias: `declare(strict_types=1)`, todo texto dinâmico por `e()`, includes por `__DIR__`, sem framework, **sem build step**, sem `style`/`script` inline, document root em `site/public/`, tudo que é dado centralizado em `src/config.php`.

## Por que importa
Quem for mexer no site precisa hoje caçar essas regras dentro de uma pasta de feature concluída, ou descobri-las por leitura do código. A feature `20260920-site-conteudo-e-precos` acrescentou mais convenções (navegação absoluta, `$pagePath` para a canonical, progressive enhancement no `nav.js`), aumentando o que está implícito. A solução seria um `07-context-site.md` no mesmo formato portátil dos outros dois — e, se valer, uma skill adaptadora como `expense-frontend`/`expense-backend` já têm.

Tipo sugerido: doc
