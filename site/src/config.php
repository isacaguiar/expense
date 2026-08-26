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

    // Ainda não existe domínio de produção nem tela de login/cadastro publicada — placeholders
    // explícitos até essas URLs existirem de verdade.
    'app_login_url' => '#',
    'app_signup_url' => '#',

    'nav' => [
        ['label' => 'Recursos', 'href' => '#recursos'],
        ['label' => 'Como funciona', 'href' => '#como-funciona'],
        ['label' => 'Benefícios', 'href' => '#beneficios'],
        ['label' => 'Preços', 'href' => '#'],
        ['label' => 'Contato', 'href' => 'mailto:novemax@gmail.com'],
    ],
];
