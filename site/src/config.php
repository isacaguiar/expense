<?php

declare(strict_types=1);

/**
 * Dados centrais do site institucional — nome, navegação, contato e URLs externas.
 * Qualquer página deve ler daqui em vez de repetir strings soltas.
 */
return [
    'brand_name' => 'Shared Expense',
    'legal_name' => 'Controle de Despesas Compartilhadas',
    'tagline' => 'Despesas compartilhadas, contas em dia.',
    'description' => 'Controle de despesas mensais fixas e variáveis entre grupos de usuários, '
        . 'com divisão igualitária dos valores entre os pagadores designados.',
    'contact_email' => 'novemax@gmail.com',

    // Base absoluta de `og:url` e `<link rel="canonical">`. Sem barra final.
    'site_url' => 'https://expense.novemax.com.br',
    'updated_at' => '24 de agosto de 2026',

    /*
     * Propriedade do Google Analytics. Não é segredo — vai no HTML de qualquer
     * forma —, mas mora aqui em vez de solto no `header.php` pela mesma regra
     * dos outros valores deste arquivo: uma fonte só.
     *
     * O app React usa a MESMA propriedade, por `VITE_GA_MEASUREMENT_ID`
     * (`frontend/src/config.ts`, injetada em `deploy-frontend.yml`). Trocar de
     * propriedade exige mudar nos dois lugares: são deploys separados e não há
     * código compartilhado entre o site PHP e o app.
     *
     * O carregamento é condicionado ao consentimento — ver `consent.php`.
     */
    'ga_measurement_id' => 'G-RNQM4DT19G',

    // O app React é servido em /app pelo mesmo domínio (ver
    // docs/feature/concluidas/202608/20260829-deploy-topologia-unificada/). A tela de
    // cadastro existe desde docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/.
    'app_login_url' => '/app/',
    'app_signup_url' => '/app/cadastro',

    // Destinos absolutos de propósito: o site é servido na raiz do domínio (o app
    // React fica em /app), e âncora relativa como '#recursos' não resolve quando o
    // menu é renderizado em páginas internas — era o que quebrava em termos.php.
    'nav' => [
        ['label' => 'Recursos', 'href' => '/recursos.php'],
        ['label' => 'Como funciona', 'href' => '/#como-funciona'],
        ['label' => 'Preços', 'href' => '/precos.php'],
        ['label' => 'Ajuda', 'href' => '/manual.php'],
        ['label' => 'Contato', 'href' => '/contato.php'],
    ],

    /*
     * Canais da página de contato. `href` nulo significa "monte o mailto a
     * partir de contact_email" — o e-mail não é repetido aqui de propósito.
     * O site não tem formulário: nenhum dado pessoal é coletado por ele, e é o
     * que mantém a Política de Privacidade vigente válida sem alteração.
     */
    'contact_channels' => [
        [
            'icon' => 'book-open',
            'title' => 'Antes de escrever, veja o manual',
            'text' => 'A maior parte das dúvidas de uso está respondida lá, com passo a passo e perguntas frequentes.',
            'href' => '/manual.php',
            'link_label' => 'Abrir o manual de uso',
        ],
        [
            'icon' => 'mail',
            'title' => 'E-mail',
            'text' => 'Para dúvidas, problemas com a sua conta e questões sobre privacidade dos seus dados.',
            'href' => null,
            'link_label' => null,
        ],
        [
            'icon' => 'message-circle',
            'title' => 'Sugestões e melhorias',
            'text' => 'Tem uma ideia para o produto ou encontrou algo que poderia ser melhor? Há uma página só para isso.',
            'href' => '/sugestoes.php',
            'link_label' => 'Enviar uma sugestão',
        ],
    ],

    // Espelha MAX_GROUPS_CREATED_PER_USER em
    // backend/app/Http/Controllers/GroupController.php:39. O site é um deploy
    // separado e não consegue ler a constante em runtime, então o número mora
    // aqui — e só aqui. Nenhuma página escreve "3" no meio do texto.
    'free_groups_limit' => 3,

    /*
     * Planos publicados na página de Preços.
     *
     * O Pro ainda não é comprável: não existe cobrança, assinatura nem bloqueio
     * por plano no produto. Por isso ele sai com `badge` de "em breve" e o CTA
     * leva ao cadastro gratuito, nunca a um checkout.
     *
     * Só aparecem aqui diferenciais que o Pro *adiciona*. Limite de membros por
     * grupo e janela de histórico ficaram de fora de propósito: hoje são
     * ilimitados para todo mundo, e anunciá-los como benefício do Pro seria
     * prometer uma piora a quem já usa o produto (specify.md §2.5).
     *
     * Cuidado de redação: o aviso de comprovante por WhatsApp já existe para
     * todos (WhatsAppNotifier, opt-in por usuário). O que o Pro adiciona é o
     * lembrete de cobrança para quem está devendo, que não existe hoje.
     */
    'plans' => [
        [
            'name' => 'Gratuito',
            'price' => 'R$ 0',
            'period' => 'para sempre',
            'badge' => null,
            'highlight' => true,
            'summary' => 'Tudo que um grupo precisa para dividir as contas do mês.',
            'features' => [
                'Até %d grupos criados por você',
                'Participar de quantos grupos você for convidado',
                'Membros ilimitados em cada grupo',
                'Despesas fixas, parceladas e à vista',
                'Divisão automática entre os pagadores escolhidos',
                'Ciclo mensal com fechamento e acerto de contas',
                'Cobrança via Pix com QR Code e copia-e-cola',
                'Relatórios e histórico completo dos ciclos',
                'Notificações no app',
            ],
            'cta_label' => 'Criar minha conta grátis',
        ],
        [
            'name' => 'Pro',
            'price' => 'R$ 4,90',
            'period' => 'por mês',
            'badge' => 'em breve',
            'highlight' => false,
            'summary' => 'Para quem administra vários grupos e cansou de cobrar na mão.',
            'features' => [
                'Tudo o que o plano Gratuito oferece',
                'Grupos ilimitados, sem o teto de %d',
                'Lembrete automático de cobrança para quem está devendo',
            ],
            'cta_label' => 'Começar pelo Gratuito',
        ],
    ],

    // Sugestões vive só aqui e dentro de contato.php — seis itens no menu
    // principal é excesso.
    'footer_nav' => [
        ['label' => 'Política de Privacidade', 'href' => '/privacidade.php'],
        ['label' => 'Termos de Serviço', 'href' => '/termos.php'],
        ['label' => 'Ajuda', 'href' => '/manual.php'],
        ['label' => 'Contato', 'href' => '/contato.php'],
        ['label' => 'Sugestões', 'href' => '/sugestoes.php'],
    ],
];
