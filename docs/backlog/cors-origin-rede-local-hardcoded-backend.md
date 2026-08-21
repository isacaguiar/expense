# CORS do backend com IP de rede local hardcoded

ID: 029
Origem: (solicitação direta) — habilitar acesso ao frontend dev a partir de outras máquinas na rede
Criado em: 2026-08-21
Prioridade: BAIXA
Status: Promovido para TASK-130

## Descrição

Para permitir que o frontend (Vite) e o backend (`php artisan serve`) fossem acessados por outras máquinas na mesma rede local, `backend/config/cors.php` recebeu a origem `http://192.168.0.23:3000` hardcoded em `allowed_origins`, junto de `http://localhost:3000`. Esse IP é o da máquina de desenvolvimento no momento (Wi-Fi, `192.168.0.23`) e pode mudar por DHCP, além de ser específico da rede/máquina de quem fez a alteração — não deveria ser committado como está.

## Por que importa

Não bloqueia nenhuma task, mas se esse `cors.php` for commitado tal como está, outro desenvolvedor herda uma origem CORS que não faz sentido para a rede dele (ruído/confusão), e se o IP mudar (DHCP) o acesso via rede volta a quebrar silenciosamente. O ideal é tornar a origem de rede configurável via variável de ambiente (ex.: `FRONTEND_NETWORK_URL` no `.env` do backend, lida em `cors.php` e adicionada a `allowed_origins` só quando definida), em vez de hardcoded no array. Também vale documentar o passo de liberar as portas 3000/8000 no firewall do Windows (`New-NetFirewallRule`), que é manual e não foi automatizado.

Tipo sugerido: infra
