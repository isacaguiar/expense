<?php

declare(strict_types=1);

/** @var array<string, mixed> $config */
?>
<svg class="logo-mark" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
  <path d="M20 6a14 14 0 1 0 0 28" stroke="#0b2545" stroke-width="3" stroke-linecap="round" />
  <path d="M20 6a14 14 0 1 1 0 28" stroke="#1fab6f" stroke-width="3" stroke-linecap="round" />
  <circle cx="10.5" cy="8.5" r="2.6" fill="#0b2545" />
  <circle cx="29.5" cy="8.5" r="2.6" fill="#1fab6f" />
  <text x="20" y="25" text-anchor="middle" font-family="-apple-system, Segoe UI, Roboto, Arial, sans-serif" font-size="15" font-weight="700" fill="#14895f">$</text>
</svg>
<span class="logo-word">
  <span class="logo-word-navy"><?= e(explode(' ', (string) $config['brand_name'])[0] ?? '') ?></span><?php
  $rest = implode(' ', array_slice(explode(' ', (string) $config['brand_name']), 1));
  if ($rest !== '') : ?><span class="logo-word-green"> <?= e($rest) ?></span><?php endif; ?>
</span>
