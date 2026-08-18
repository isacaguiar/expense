# Despesa Compartilhada (Backend)

API RESTful em Laravel para gerenciar despesas compartilhadas em grupos de usuários.

---

## 📋 Sumário

- [Pré-requisitos](#-pré-requisitos)  
- [Instalação](#-instalação)  
- [Configuração do Ambiente](#-configuração-do-ambiente)  
- [Banco de Dados](#-banco-de-dados)  
- [Executando em Desenvolvimento](#-executando-em-desenvolvimento)  
- [Rotas Principais](#-rotas-principais)  
- [Exemplos de Requisições](#-exemplos-de-requisições)  
- [Testes](#-testes)  
- [Contribuindo](#-contribuindo)  
- [Licença](#-licença)  

---

## 🔧 Pré-requisitos

- PHP ≥ 8.1  
- Composer  
- MySQL (ou outro banco suportado pelo Laravel)  
- Git  

---

## ⚙️ Instalação

1. Clone o repositório  

```bash
git clone https://github.com/SEU_USUARIO/despesa-compartilhada-backend.git
cd despesa-compartilhada-backend
```

2. Instale dependências PHP
```bash
composer install
```

3. Copie o arquivo de ambiente e gere uma chave de aplicação
```bash
cp .env.example .env
php artisan key:generate
```

4. Configure as variáveis no .env (veja seção abaixo).

---

### 📝 Configuração do Ambiente
```dotenv
No arquivo .env, ajuste as seguintes variáveis:
APP_NAME="Despesa Compartilhada"
APP_ENV=local
APP_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=desa_compartilhada
DB_USERNAME=root
DB_PASSWORD=

# Outras variáveis (Mail, Cache, Queue, etc.)
```

---

### 💾 Banco de Dados

---

### 🚀 Executando em Desenvolvimento

Inicie o servidor embutido do Laravel:
```bash
php artisan serve
```
Por padrão, a aplicação ficará disponível em http://127.0.0.1:8000.

---

### 📚 Rotas Principais

Todas as rotas estão prefixadas por /api

| Recurso   | Método | Endpoint                  | Descrição                       |
| --------- | ------ | ------------------------- | ------------------------------- |
| Login     | POST   | `/api/login`              | Acessar o aplicativo            |
| Logout    | POST   | `/api/logout`             | Desconectar do aplicativo       |
| DashBoard | GET    | `/api/dashboard`          | Abrir o dashboard               |
| Grupos    | GET    | `/api/groups`             | Listar todos os grupos          |
|           | POST   | `/api/groups`             | Criar novo grupo                |
| Grupo     | GET    | `/api/groups/{id}`        | Obter detalhes de um grupo      |
| Despesas  | GET    | `/api/expenses`           | Listar todas as despesas        |
|           | POST   | `/api/expenses`           | Criar nova despesa              |
| Despesa   | GET    | `/api/expenses/{id}`      | Obter detalhes de uma despesa   |
| Relatório | GET    | `/api/groups/{id}/report` | Gerar relatório mensal do grupo |

---

### 🔍 Exemplos de Requisições

Autenticação