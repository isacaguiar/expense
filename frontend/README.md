# Despesa Compartilhada — Frontend

Repositório do **frontend** do sistema de Controle de Despesas Compartilhadas, baseado em **Vite + React + TypeScript**.

---

## 📋 Sumário

- [Pré-requisitos](#-pré-requisitos)  
- [Instalação](#-instalação)  
- [Desenvolvimento](#-desenvolvimento)  
- [Preview](#-preview)  
- [Build para Produção](#-build-para-produção)  
- [Variáveis de Ambiente](#-variáveis-de-ambiente)  
- [Estrutura do Projeto](#-estrutura-do-projeto)  
- [Dependências Principais](#-dependências-principais)  
- [Contato](#-contato)  

---

## 🔧 Pré-requisitos

- **Node.js** (>= v16)  
- **npm** (>= 8) ou **Yarn**  

---

## ⚙️ Instalação

1. Clone este repositório e acesse a pasta do frontend:
   ```bash
   git clone <URL_DO_REPO>
   cd expense/frontend
   ```
2. Instale as dependências:
   ```bash
   npm install
   # ou
   yarn
   ```

---

## Selecionar a versão do node
Lista as versões instaladas
```bash
nvm list
```
Seleciona a versão do node
```bash
nvm use <version>
```

---

## 🚀 Desenvolvimento

Para subir o servidor de dev com hot-reload:
```bash
npm run dev
```
O app pode ser acessado em http://localhost:3000 por padrão.

---

## 🔍 Preview

Para simular o build de produção localmente:
```bash
npm run preview
```
Também no http://localhost:5173, mas rodando os arquivos já empacotados.

---

## 📦 Build para Produção

Gera os assets otimizados em `dist/`:
```bash
npm run build
```

---

## 🛠️ Variáveis de Ambiente

Crie um arquivo `.env` na raiz (não versionar!) com, por exemplo:
```env
VITE_API_BASE_URL=https://api.seudominio.com
```
> Todos os vars expostos ao browser precisam do prefixo `VITE_`.

---

## 🗂️ Estrutura do Projeto

```
frontend/
├─ public/           # index.html, favicon e assets estáticos
├─ src/
│  ├─ assets/        # imagens, fontes, estilos globais
│  ├─ components/    # componentes React reutilizáveis
│  ├─ pages/         # rotas/páginas
│  ├─ services/      # instância Axios, APIs
│  ├─ App.tsx        # root component
│  ├─ main.tsx       # entrypoint (ReactDOM.render)
│  └─ vite-env.d.ts  # tipos globais Vite
├─ tsconfig.json     # config TypeScript
├─ .env              # variáveis de ambiente (excluído do Git)
└─ package.json      # scripts e dependências
```

---

## 📦 Dependências Principais

- **React 18**  
- **Vite**  
- **TypeScript**  
- **MUI (Material UI) + Emotion**  
- **React Router v6**  
- **Axios**  

Veja o `package.json` completo para todas as versões.

---

## 📞 Contato

**Isac Aguiar** – isac.aguiar@gmail.com  
