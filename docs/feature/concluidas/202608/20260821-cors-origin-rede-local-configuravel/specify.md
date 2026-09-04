# Specify — CORS origin de rede local configurável

> Feature: tornar a origem CORS usada para acesso via rede local configurável por variável de ambiente, em vez de um IP hardcoded em `backend/config/cors.php`. Promovida do item 029 do backlog (`docs/backlog/cors-origin-rede-local-hardcoded-backend.md`), a partir de uma mudança já feita localmente (não commitada) para permitir acessar o frontend/backend de outra máquina na mesma rede.

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

Para acessar o frontend (Vite, porta 3000) e o backend (`php artisan serve`, porta 8000) a partir de outra máquina na rede local, `backend/config/cors.php:9` recebeu a origem `http://192.168.0.23:3000` hardcoded em `allowed_origins`, ao lado de `http://localhost:3000`:

```php
'allowed_origins' => ['http://localhost:3000', 'http://192.168.0.23:3000'],
```

Esse IP é o da máquina de desenvolvimento de quem fez a mudança, atribuído por DHCP — pode mudar a qualquer momento e é específico da rede de quem editou o arquivo. Se commitado assim, outro desenvolvedor herda uma origem CORS sem sentido para a rede dele, e a mudança de IP por DHCP quebra o acesso via rede silenciosamente (sem erro óbvio — só o preflight CORS passa a falhar).

Mudança relacionada, também local e não commitada: `frontend/vite.config.js:12` ganhou `host: true` no bloco `server`, para o Vite aceitar conexões de qualquer interface de rede (não só `localhost`) — esse valor não é específico de IP/máquina, então não tem o mesmo problema de hardcoding; falta só commitar.

## 2. Requisitos

### 2.1 Origem de rede via variável de ambiente

`backend/config/cors.php` passa a montar `allowed_origins` combinando o valor fixo `http://localhost:3000` com uma origem opcional lida de `env('FRONTEND_NETWORK_URL')` — só incluída no array quando a variável estiver definida. `backend/.env` (gitignored, já confirmado em `backend/.gitignore:8`) recebe `FRONTEND_NETWORK_URL=http://192.168.0.23:3000` — mesmo padrão já usado em `frontend/.env`/`frontend/.env.example` para `VITE_API_BASE_URL` (`frontend/.env.example:1`).

### 2.2 Commitar `host: true` no Vite

`frontend/vite.config.js` mantém `host: true` no bloco `server` (já presente localmente, não commitado) — habilita o Vite a aceitar conexões de qualquer interface de rede. Sem valor específico de máquina, então sem o problema de drift do item 029; só falta versionar.

## 3. Fora de escopo desta feature

- Documentar o passo de liberar as portas 3000/8000 no firewall do Windows (`New-NetFirewallRule`) — decisão explícita do usuário nesta promoção: portas já liberadas manualmente, não precisa entrar na feature.
- Criar `backend/.env.example` do zero (o backend não tem um hoje, diferente do frontend) — fora do escopo deste item pontual; se quiser, vira um novo item de backlog.
