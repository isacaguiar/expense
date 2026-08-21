# Plan — CORS origin de rede local configurável

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Origem de rede via variável de ambiente (specify §2.1)

- `backend/config/cors.php:9`: trocar o array fixo por uma expressão que filtra valores vazios:
  ```php
  'allowed_origins' => array_filter([
      'http://localhost:3000',
      env('FRONTEND_NETWORK_URL'),
  ]),
  ```
  `array_filter` sem callback remove entradas falsy (`null`/`''`), então a origem de rede só entra no array quando `FRONTEND_NETWORK_URL` está definida — sem `env('FRONTEND_NETWORK_URL')` no `.env`, o comportamento é idêntico ao atual antes do hardcode (só `localhost:3000`).
  Ler `env()` direto em `config/cors.php` é o padrão correto do Laravel (arquivos de `config/` são o único lugar onde `env()` deve ser chamado — `php artisan config:cache` congela o valor resolvido; chamar `env()` fora de `config/` depois de cachear retorna `null`). Não introduz risco novo.
- `backend/.env` (arquivo local, gitignored — `backend/.gitignore:8`, confirmado que não está versionado): adicionar a linha `FRONTEND_NETWORK_URL=http://192.168.0.23:3000`, reaproveitando o IP que já estava hardcoded em `cors.php`, agora só na máquina de quem precisa dele.
- Sem `.env.example` novo (fora de escopo, specify §3) — a variável fica só documentada no `## 2` do `implementation.md` desta feature e no próprio nome autoexplicativo.

## 2. Commitar `host: true` no Vite (specify §2.2)

- `frontend/vite.config.js`: nenhuma mudança de código — a linha `host: true` já está presente localmente (não commitada). Esta task só formaliza o commit dela junto da mudança de CORS, já que fazem parte da mesma história de habilitar acesso via rede local.

## 3. Ordem de execução

Sem dependência técnica entre os dois itens (arquivos diferentes, um é config de env, outro é uma linha já pronta no Vite). Cabem numa task só por serem uma única entrega coesa ("habilitar acesso via rede local sem hardcode de IP"), commitados juntos — ver `tasks.md`.
