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
    // docs/feature/20260829-deploy-topologia-unificada/). Não há tela de
    // cadastro ainda — "Cadastre-se" aponta para o login até existir registro.
    'app_login_url' => '/app/',
    'app_signup_url' => '/app/',

    'nav' => [
        ['label' => 'Recursos', 'href' => '#recursos'],
        ['label' => 'Como funciona', 'href' => '#como-funciona'],
        ['label' => 'Benefícios', 'href' => '#beneficios'],
        ['label' => 'Preços', 'href' => '#'],
        ['label' => 'Contato', 'href' => 'mailto:novemax@gmail.com'],
    ],
];
