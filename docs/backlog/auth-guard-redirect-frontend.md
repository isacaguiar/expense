# Auth guard / redirect automático

ID: 004
Origem: docs/feature/20260817-migracao-frontend-expo/specify.md §2.3
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Aberto

## Descrição

Páginas que exigem autenticação hoje apenas exibem um texto de erro ("Usuário não autenticado") quando o token está ausente/inválido, sem redirecionar automaticamente para a tela de login (ex.: `GroupList.tsx:47-49`). Um guard de rota resolveria isso — no web, um wrapper de rota; no app (`expense/app`), uma verificação no layout do Expo Router.

## Por que importa

Não bloqueia nenhuma task da migração, mas melhora a experiência em ambas as plataformas e evita reimplementar essa checagem página a página conforme novas telas forem criadas.

Tipo sugerido: frontend
