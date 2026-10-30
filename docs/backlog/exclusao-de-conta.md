# Exclusão de conta (backend, web e página pública)

ID: 072
Origem: análise do app na Google Play (conversa de 2026-10-06); política de dados de usuário da Google Play
Criado em: 2026-10-06
Prioridade: ALTA
Status: Aberto

## Descrição
Hoje não há como excluir a conta: só existe `DELETE /user/photo` e não há nenhuma ocorrência de exclusão de conta no backend, no frontend, no site nem no backlog. A Google Play exige, para apps que permitem criar conta, um caminho dentro do app **e** uma URL web pública para pedir a exclusão da conta e dos dados, informada no formulário Data safety; desativar ou "congelar" a conta não vale. A LGPD (art. 18) também prevê a eliminação dos dados.

Escopo a definir no specify:

- Endpoint autenticado no backend, com confirmação (por exemplo, a senha) antes de excluir.
- Tela de exclusão no perfil do web (e depois no app, item 074).
- Página pública no site com o pedido de exclusão, para quem não tem o app instalado.
- Regra para dados compartilhados: despesas e acertos em que a pessoa participou, grupos que criou, comprovantes, foto, chave Pix, WhatsApp e notificações. Decidir entre excluir, anonimizar ou transferir (por exemplo, o grupo que a pessoa criou).

Toca autenticação e dado sensível: `security-reviewer` antes do PR, e Triagem do BFF não se aplica (é feature).

Ordem: sem dependências, pode andar em paralelo à trilha **070 → 071 → 074**. É **obrigatório antes do release** do app (074), não antes do início dele.

## Por que importa
Sem isso a Google Play recusa a publicação do app, e a eliminação dos dados é uma obrigação de privacidade independente da loja.

Tipo sugerido: backend (com frontend e site)
