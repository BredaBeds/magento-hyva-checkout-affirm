<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * PHPStan ignores that apply only when the harness resolved older dependency releases (PHP 8.1 resolves to
 * Magento 2.4.7 and Monolog 2). Each rule is added only when the installed code shows the difference, so the
 * newest stack is analysed without them.
 */
declare(strict_types=1);

$vendor = __DIR__ . '/vendor';
$ignoreErrors = [];

// Magento 2.4.7 documents the message parameter as string only; Phrase works at runtime and later releases
// document string|\Stringable.
$messageManager = (string) @file_get_contents($vendor . '/magento/framework/Message/ManagerInterface.php');
if (str_contains($messageManager, '@param string $message')) {
    $ignoreErrors[] = [
        'message' => '#^Parameter \#1 \$message of method Magento\\\\Framework\\\\Message\\\\ManagerInterface::'
            . 'add(Error|Notice|Success|Warning)Message\(\) expects string, Magento\\\\Framework\\\\Phrase given\.$#',
    ];
}

// Monolog 2 has LogRecord only as an interface and no Level enum. The processor handles Monolog 2's array
// records at runtime and the LogRecord tests skip, so only the Monolog 3 branch is reported here.
if (!is_file($vendor . '/monolog/monolog/src/Monolog/Level.php')) {
    $ignoreErrors[] = [
        'message' => '#Monolog\\\\LogRecord#',
        'path' => __DIR__ . '/../../src/Logger/RedactCheckoutTokenProcessor.php',
    ];
    $ignoreErrors[] = [
        'message' => '#Monolog\\\\(LogRecord|Level)|^Cannot (call method toArray\(\)|access property \$context) on array<#',
        'path' => __DIR__ . '/../../Test/Unit/Logger/RedactCheckoutTokenProcessorTest.php',
    ];
}

return ['parameters' => ['ignoreErrors' => $ignoreErrors]];
