# Limite de grupos por usuário está duplicado em três lugares

ID: 053
Origem: docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/specify.md §3
Criado em: 2026-09-20
Prioridade: MEDIA
Status: Aberto

## Descrição
O número 3 aparece como constante independente em:

- `backend/app/Http/Controllers/GroupController.php:39` — `MAX_GROUPS_CREATED_PER_USER = 3`, a única que de fato é aplicada.
- `frontend/src/pages/Dashboard.tsx:47` — `MAX_GROUPS_CREATED_PER_USER = 3`, usada para desabilitar o botão de criar grupo.
- `site/src/config.php` — `free_groups_limit => 3`, usada na home e na página de Preços.

O backend não expõe esse valor por nenhum endpoint, então cliente e site não têm como lê-lo.

## Por que importa
Esse valor deixou de ser só uma regra anti-abuso: desde a feature `20260920-site-conteudo-e-precos` ele é **o limite anunciado do plano Gratuito**, publicado na página de Preços. Se alguém mudar o valor no backend sem mudar nos outros dois, o site passa a anunciar um preço-produto que não corresponde ao sistema — exatamente o defeito ("Grupos ilimitados" contra um teto de 3) que aquela feature veio corrigir.

A correção provável é expor o limite numa resposta da API (`/me` ou um endpoint de configuração pública) e o frontend consumir de lá. O site, por ser um deploy estático separado, provavelmente continuará com a cópia — mas aí com apenas uma duplicação, e documentada.

Tipo sugerido: backend
