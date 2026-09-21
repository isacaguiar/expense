# Imagem de compartilhamento do site é uma screenshot reaproveitada

ID: 054
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/plan.md §9
Criado em: 2026-09-20
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature `20260920-site-conteudo-e-precos` ligou `og:image` e `twitter:image` em `site/src/templates/header.php`, mas apontando para `assets/app-home.png` (1349×592) — a screenshot do app já usada no hero da landing. O formato recomendado para prévia de compartilhamento é 1200×630, e um asset dedicado não existe.

## Por que importa
A proporção é próxima o bastante para funcionar, mas o WhatsApp e o LinkedIn recortam a imagem, e uma screenshot de interface fica ilegível em miniatura. Uma imagem pensada para o formato — logo, uma frase e um fundo limpo — converte bem melhor quando o link circula. É trabalho de design, não de código: por isso não bloqueou a feature, que preferiu ter uma prévia imperfeita a não ter prévia nenhuma.

Ao criar o asset, basta apontar `$ogImage` em `header.php` para ele e corrigir `og:image:width`/`og:image:height`.

Tipo sugerido: frontend
