# 🔐 API de Autenticação - JWT

Esta API utiliza autenticação baseada em **JWT (JSON Web Token)**, oferecendo endpoints para **registro de usuário**, **login** e **renovação de token (refresh)**.

---

## 📌 Endpoints

### ✅ `POST /auth/register` – Registro de novo usuário

Cria uma nova conta de usuário.

#### 📥 Requisição:
```json
{
  "name": "João Silva",
  "email": "joao@email.com",
  "password": "senhaSegura123"
}
```

#### 📥 Resposta:
```json
{
  "id": "8f41c2c1-9df4-4ae8-8134-bbcf41093a9a",
  "name": "João Silva",
  "email": "joao@email.com"
}
```

### ✅ `POST /auth/login` – Autenticação

Autentica o usuário e retorna os tokens de acesso e refresh.

#### 📥 Requisição:
```json
{
  "email": "joao@email.com",
  "password": "senhaSegura123"
}
```

#### 📥 Resposta:
```json
{
  "accessToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refreshToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "tokenType": "Bearer",
  "expiresIn": 3600
}
```

### ✅ `POST /auth/refresh` – Renovar Access Token

Renova o token de acesso utilizando o token de refresh.

#### 📥 Requisição:
```json
{
  "refreshToken": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

#### 📥 Resposta:
```json
{
  "accessToken": "novoAccessToken...",
  "refreshToken": "novoRefreshToken...",
  "tokenType": "Bearer",
  "expiresIn": 3600
}
```

## 🔒 Como usar o token de acesso
Inclua o token JWT no header Authorization para acessar rotas protegidas:
```
Authorization: Bearer {accessToken}
```

