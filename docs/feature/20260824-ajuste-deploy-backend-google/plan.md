# Plan — Ajuste do Deploy Backend para Google OAuth

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260824

---

## 1. Corrigir o passo "Gerar arquivo .env" (specify §2.1, §2.2, §2.3)

- Editar `.github/workflows/deploy-backend.yml`, dentro do heredoc do passo "🔐 Gerar arquivo .env" (linhas 30-56):
  - Trocar `APP_URL=https://scd.novemax.com.br` por `APP_URL=https://expense-api.novemax.com.br`.
  - Trocar `MAIL_FROM_ADDRESS=no-reply@scd.novemax.com.br` por `MAIL_FROM_ADDRESS=no-reply@expense-api.novemax.com.br`.
  - Adicionar ao final do bloco (antes do fim do heredoc), no mesmo padrão `echo "CHAVE=${{ secrets.NOME_DO_SECRET }}" >> .env` já usado nas linhas existentes:
    ```
    echo "GOOGLE_CLIENT_ID=${{ secrets.ENV_GOOGLE_CLIENT_ID }}" >> .env
    echo "GOOGLE_CLIENT_SECRET=${{ secrets.ENV_GOOGLE_CLIENT_SECRET }}" >> .env
    echo "GOOGLE_REDIRECT_URI=${{ secrets.ENV_GOOGLE_REDIRECT_URI }}" >> .env
    echo "FRONTEND_URL=${{ secrets.ENV_FRONTEND_URL }}" >> .env
    echo "FRONTEND_NETWORK_URL=${{ secrets.ENV_FRONTEND_NETWORK_URL }}" >> .env
    ```
  - Nenhum valor literal de segredo entra no YAML — só referências a `secrets.*`, mesmo padrão do resto do arquivo.

## N. Ordem de execução

Sem dependência — é uma única edição contígua no mesmo passo do mesmo arquivo. Task única.
