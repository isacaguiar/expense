# Plan — CI (verificação) para o backend

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. Novo workflow `.github/workflows/ci-backend.yml`

- **Decisão**: arquivo novo, dispara em `pull_request` contra `dev`/`main` (mesmo gatilho de `ci-frontend.yml:3-7`), com um `services: mysql` (specify §2.1) rodando `mysql:8.0.17` — a mesma imagem de `docker-compose.yml:5`, já validada com a suíte atual:

  ```yaml
  name: CI Backend

  on:
    pull_request:
      branches:
        - dev
        - main

  jobs:
    test:
      name: Pint + PHPUnit (backend)
      runs-on: ubuntu-latest

      services:
        mysql:
          image: mysql:8.0.17
          env:
            MYSQL_ROOT_PASSWORD: 3xp3ns1v3
            MYSQL_DATABASE: ex-db
          ports:
            - 3306:3306
          options: >-
            --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -p3xp3ns1v3"
            --health-interval=5s
            --health-timeout=5s
            --health-retries=10

      steps:
        - name: 📥 Clonar o repositório
          uses: actions/checkout@v4

        - name: 🧰 Configurar PHP com Composer
          uses: shivammathur/setup-php@v2
          with:
            php-version: '8.2'
            extensions: mbstring, bcmath, ctype, fileinfo, json, openssl, pdo, tokenizer, xml, curl, gd
            coverage: none

        - name: 📦 Instalar dependências
          working-directory: backend
          run: composer install --prefer-dist --no-interaction

        - name: 🔐 Gerar .env de teste
          working-directory: backend
          run: |
            echo "APP_NAME=SCD" > .env
            echo "APP_ENV=testing" >> .env
            echo "APP_DEBUG=true" >> .env
            echo "APP_URL=http://127.0.0.1:8000" >> .env
            echo "FRONTEND_URL=http://localhost:5173" >> .env

            echo "DB_CONNECTION=mysql" >> .env
            echo "DB_HOST=127.0.0.1" >> .env
            echo "DB_PORT=3306" >> .env
            echo "DB_DATABASE=ex-db" >> .env
            echo "DB_USERNAME=root" >> .env
            echo "DB_PASSWORD=3xp3ns1v3" >> .env

            echo "JWT_SECRET=ci-test-secret-nao-e-credencial-real" >> .env

        - name: 🔑 Gerar chave do aplicativo
          working-directory: backend
          run: php artisan key:generate

        - name: 🗄️ Rodar migrations
          working-directory: backend
          run: php artisan migrate --force

        - name: 🎨 Pint (estilo)
          working-directory: backend
          run: ./vendor/bin/pint --test

        - name: 🧪 PHPUnit
          working-directory: backend
          run: php artisan test
  ```

- **Por que essa abordagem e não outra**:
  - `services: mysql` (serviço nativo do GitHub Actions, não um `docker run` manual) é o jeito padrão de subir um banco descartável por job — mesma imagem/versão do `docker-compose.yml` local, então o schema criado por `php artisan migrate` (validado do zero na promoção, specify §2.1) se comporta igual ao ambiente local.
  - `MYSQL_ROOT_PASSWORD`/`JWT_SECRET` aqui são valores fixos de teste, não segredos: o banco só existe durante o job, é descartado ao final, e não há nenhum dado real envolvido — não precisam (e não devem) virar `secrets.*` do GitHub, que é reservado para credencial de verdade (`deploy-backend.yml` usa `secrets.*` porque lá são credenciais de produção).
  - `composer install` sem `--no-dev` (diferente de `deploy-backend.yml:28`) porque o CI precisa de `laravel/pint` e `phpunit/phpunit`, que são `require-dev` (`backend/composer.json`) — o deploy exclui dev deps de propósito (não vão pro build de produção), o CI depende exatamente delas.
  - `php artisan key:generate` roda no próprio job (chave nova a cada execução) em vez de vir de secret — não há sessão/cookie persistente para quebrar num ambiente que morre ao final do job, ao contrário da produção (`deploy-backend.yml:35`, que usa `secrets.ENV_APP_KEY` porque lá a chave precisa ser estável entre deploys).
  - `.env` gerado inline via `echo` (mesmo padrão de `deploy-backend.yml:32-69`) em vez de copiar `.env.example`: o arquivo está gitignorado (item de backlog 056, specify §3) e não existe no checkout do runner — gerar inline evita criar uma dependência nova entre esta feature e aquele item de backlog.
  - `APP_URL=http://127.0.0.1:8000` e `FRONTEND_URL=http://localhost:5173`: portas diferentes, nenhuma é prefixo da outra — evita a colisão de `str_starts_with` encontrada e comprovada na promoção (specify §2.3). Não são endpoints reais (nada no CI escuta nessas portas); os testes que os usam só comparam a URL montada no e-mail (mockada), nunca fazem requisição HTTP de verdade para elas.
  - Nenhum `MAIL_*`/`CACHE_DRIVER`/`SESSION_DRIVER`/`QUEUE_CONNECTION` no `.env` gerado — `phpunit.xml:20-28` já força os valores corretos (`array`/`sync`) independentemente do `.env`, então repetir aqui seria redundante.
  - Passos de Pint e PHPUnit separados (não um só `run` com `&&`) para o resumo do GitHub Actions apontar exatamente qual dos dois falhou, sem precisar abrir o log.

- **Arquivos afetados**: `.github/workflows/ci-backend.yml` (novo).

## 2. Ordem de execução

Item único (o workflow inteiro é uma peça só) — sem dependência com outra parte do sistema. `tasks.md` divide em: criar o workflow, e validar que ele de fato roda e falha/passa quando deveria.

**Decisão de validação**: `act` (runner local de GitHub Actions) não está instalado nesta máquina (`which act` não encontrou o binário) — instalar só para esta validação seria ferramenta nova para um caso de uso único. A validação real acontece no próprio PR desta feature contra `dev` (que já vai existir de qualquer forma, por `04-implementation.md` §1): abrir o PR já dispara `ci-backend.yml` de verdade — isso prova o caminho "passa quando está tudo certo". Para provar o caminho "falha quando deveria", a task de validação inclui um commit temporário que quebra Pint ou PHPUnit de propósito, confirma o check ficando vermelho no PR, e depois um commit revertendo a quebra, confirmando o check ficando verde — os dois commits temporários (quebra + reversão) ficam registrados no histórico da task, não são posteriormente apagados por rebase.
