<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 *
 * PHPUnit bootstrap of the CI harness: the harness autoloader (module, tests, Magento packages, Hyvä test
 * doubles) plus on-the-fly generation of Magento factories, proxies and extension attribute classes, as
 * Magento's own unit test framework does on an installation.
 */
declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;
use Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator;

require __DIR__ . '/vendor/autoload.php';

$generators = [
    new ExtensionAttributesGenerator(),
    new ExtensionAttributesInterfaceGenerator(),
    new FactoryGenerator(),
];
// Magento 2.4.7 (what PHP 8.1 resolves to) has no proxy generator for unit tests; 2.4.9 has.
if (class_exists(ProxyGenerator::class)) {
    $generators[] = new ProxyGenerator();
}
$generatedClasses = new GeneratedClassesAutoloader($generators, new Io(new File(), __DIR__ . '/var/generated'));
spl_autoload_register([$generatedClasses, 'load']);

Phrase::setRenderer(new Placeholder());
