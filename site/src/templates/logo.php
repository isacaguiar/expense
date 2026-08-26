<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $config
 * @var string|null $logoVariant 'light' (padrão, fundo claro) ou 'dark' (fundo escuro — ex.: cta-banner).
 *   No fundo escuro os dois "bonecos" ao redor do cifrão usam branco/#e4e4e4 em vez de
 *   navy/verde (o arquivo original fica ilegível sobre fundo escuro), mantendo o cifrão como está.
 */

$variant = $logoVariant ?? 'light';
$iconSrc = $variant === 'dark' ? asset('logo-expense-footer.png') : asset('logo-expense.png');
$wordClass = $variant === 'dark' ? 'logo-word logo-word--on-dark' : 'logo-word';
?>
<img class="logo-mark" src="<?= e($iconSrc) ?>" alt="" aria-hidden="true" />
<span class="<?= e($wordClass) ?>">
  <span class="logo-word-navy"><?= e(explode(' ', (string) $config['brand_name'])[0] ?? '') ?></span><?php
  $rest = implode(' ', array_slice(explode(' ', (string) $config['brand_name']), 1));
  if ($rest !== '') : ?><span class="logo-word-green"> <?= e($rest) ?></span><?php endif; ?>
</span>
