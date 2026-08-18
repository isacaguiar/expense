# Specify — Segurança da API

> Feature: correção dos achados de segurança confirmados no código durante a criação do SDD (`docs/sdd/00-constitution.md` §5.3 e §6). Antes desta feature, esses achados viviam soltos como Épico B em `docs/sdd/03-tasks.md`; a partir daqui, `docs/sdd/03-tasks.md` não recebe mais tasks novas deste grupo — este diretório é a fonte da verdade.

Versão: 1.0 · Criado em: 2026-08-17

---

## 1. Problema

Cinco achados de segurança/infra foram confirmados lendo o código real do backend (`backend/`), não a documentação antiga. Todos expõem dado financeiro/pessoal ou credencial sem controle adequado, ou colocam o deploy de produção em risco.

## 2. Achados confirmados

### 2.1 `GET /pix/generate` sem autenticação
Qualquer pessoa não autenticada pode gerar o QR Code/copia-e-cola Pix de **qualquer usuário do sistema**, informando só o e-mail dele — rota está fora do grupo de middleware `jwt.auth` em `backend/routes/api.php`. Não expõe a chave Pix diretamente na resposta, mas permite gerar uma cobrança em nome de qualquer usuário para qualquer valor arbitrário, sem nenhuma relação (grupo, despesa) entre quem pede e quem é cobrado.

### 2.2 IDOR em `GroupController@show/update/destroy`
Nenhum desses três métodos verifica se o usuário autenticado é membro do grupo. Qualquer usuário logado no sistema pode ver, editar ou "deletar" (soft delete) o grupo de outra pessoa só sabendo o ID.

### 2.3 Segredos versionados em texto puro
`00-constitution.md` §5.3 registrou senha do jasypt, client-id/secret do Google OAuth e um arquivo `client_secret_*.json` na raiz do repositório. Ao iniciar esta feature, uma checagem no repositório atual não encontrou mais esses arquivos/valores — mas a **rotação efetiva das credenciais** (invalidar as chaves antigas, não só remover do repo) é ação humana e não foi confirmada.

### 2.4 Path errado no deploy de produção
`.github/workflows/deploy-backend.yml` referencia `working-directory: backend-php`, mas o backend real do projeto está em `backend/`. Isso sugere que o deploy de produção pode estar apontando para uma pasta que não existe mais no repositório atual.

### 2.5 Senha em texto puro no log
`AuthController::login` loga `$credentials` inteiro via `Log::debug('Credenciais extraídas', $credentials)`, incluindo a senha em claro no log de aplicação a cada tentativa de login.

## 3. Fora de escopo desta feature

- Rotação efetiva das credenciais do achado 2.3 — é ação 100% humana (ver `docs/sdd/00-constitution.md` §5.2); esta feature só garante que nada novo vaza e sinaliza o que falta.
- Qualquer redesenho de autenticação/autorização além do necessário para fechar os 5 achados (ex.: RBAC completo) — não pedido, não faz parte do escopo.
