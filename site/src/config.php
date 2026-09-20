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
    'updated_at' => '24 de agosto de 2026',

    // O app React é servido em /app pelo mesmo domínio (ver
    // docs/feature/concluidas/202608/20260829-deploy-topologia-unificada/). A tela de
    // cadastro existe desde docs/feature/20260919-cadastro-de-usuarios/.
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
