# Tasks — Site Institucional Público

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.1 · Criado em: 20260824 · Revisado em: 20260824

Tasks da v1.0 (TASK-001 a TASK-004, HTML puro) foram substituídas pelas tasks abaixo — migração
para PHP + landing page completa a partir do mockup do usuário. Ver log da v1.0 preservado em
`implementation.md`.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-005 | Criar estrutura PHP base: `site/public/`, `site/src/config.php`, `helpers.php`, `templates/header.php`, `nav.php`, `footer.php` | frontend | plan.md §1 | nenhum | Concluída |
| TASK-006 | Recriar a landing page (`site/public/index.php`) reproduzindo o mockup `assets/images/site.png` | frontend | plan.md §2 | nenhum | Concluída |
| TASK-007 | Migrar Política de Privacidade para `site/public/privacidade.php` usando os templates novos | frontend | plan.md §3 | nenhum | Concluída |
| TASK-008 | Migrar Termos de Serviço para `site/public/termos.php` usando os templates novos | frontend | plan.md §4 | nenhum | Concluída |
| TASK-009 | Remover arquivos da v1.0 (`site/index.html`, `privacidade.html`, `termos.html`, `site/assets/*` antigos) e apontar `.claude/launch.json` pro servidor embutido do PHP | infra | plan.md §5 | nenhum | Concluída |
| TASK-010 | Passar `impeccable polish` em `site/public/index.php` (plugin de design instalado a pedido do usuário) contra o mockup e corrigir os defeitos reais encontrados | frontend | plan.md §2 | nenhum | Concluída |

## Critérios de aceite

- **TASK-005**: `php -S localhost:4173 -t site/public` sobe sem erro; `site/src/` não fica acessível por URL (só `site/public/` é document root).
- **TASK-006**: abrir `index.php` no navegador reproduz as 5 seções do mockup (header/nav, hero com cartão de dashboard, grid de 6 recursos, 3 passos, faixa final de CTA), responsivo (sem overflow horizontal em viewport mobile), sem erros no console.
- **TASK-007**: abrir `privacidade.php` mostra o mesmo texto de política já aprovado na v1.0, agora dentro do novo header/nav/footer, com o link de contato funcionando.
- **TASK-008**: abrir `termos.php` mostra o mesmo texto de termos já aprovado na v1.0, agora dentro do novo header/nav/footer, com o link de contato funcionando.
- **TASK-009**: `site/index.html`, `site/privacidade.html`, `site/termos.html` não existem mais no repo; `.claude/launch.json` inicia PHP em vez de `python -m http.server`.
- **TASK-010**: contraste de todos os pares texto/fundo (incluindo badges "Paga"/"Pendente" e valores "A pagar") ≥4.5:1 (WCAG AA), verificável por cálculo de luminância; `node .../scripts/detect.mjs --json site/public/index.php site/public/assets/style.css` retorna `[]`; sem overflow horizontal em viewport 375px.
