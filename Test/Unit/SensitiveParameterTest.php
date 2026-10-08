<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit;

use FilesystemIterator;
use Hyva\CheckoutAffirm\Controller\Checkout\Confirm;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionParameter;
use SplFileInfo;

/**
 * Attempt ids and checkout tokens stay out of stack traces, including traces that third-party loggers write:
 * PHP 8.2+ shows an argument marked #[\SensitiveParameter] as Object(SensitiveParameterValue).
 */
class SensitiveParameterTest extends TestCase
{
    private const SOURCE = __DIR__ . '/../../src';
    private const NAMESPACE_PREFIX = 'Hyva\\CheckoutAffirm\\';

    // By name, so that the lookup also works on PHP 8.1, which has no SensitiveParameter class.
    private const ATTRIBUTE = 'SensitiveParameter';

    private const SENSITIVE_NAMES = ['attemptId', 'checkoutToken', 'exceptAttemptId'];

    public function testEveryAttemptIdAndCheckoutTokenParameterIsMarkedSensitive(): void
    {
        $checked = [];
        $unmarked = [];
        foreach ($this->classes() as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    // Promoted constructor parameters are value-object properties set by constructors that
                    // cannot throw.
                    if ($parameter->isPromoted() || !in_array($parameter->getName(), self::SENSITIVE_NAMES, true)) {
                        continue;
                    }
                    $name = substr($class, strlen(self::NAMESPACE_PREFIX)) . '::' . $method->getName()
                        . '($' . $parameter->getName() . ')';
                    $checked[] = $name;
                    if (!$this->isSensitive($parameter)) {
                        $unmarked[] = $name;
                    }
                }
            }
        }

        $this->assertSame([], $unmarked, 'Parameters without #[\SensitiveParameter]');
        foreach ([
            'Model\Checkout\ConfirmationPipelineInterface::confirm($attemptId)',
            'Model\Checkout\ConfirmationPipeline::confirm($attemptId)',
            'Model\Checkout\ConfirmationPipeline::confirm($checkoutToken)',
            'Model\Checkout\OrderPlacement::place($attemptId)',
            'Model\Attempt\AttemptRepository::transition($attemptId)',
            'Model\Attempt\AttemptRepository::hasUnresolvedForQuote($exceptAttemptId)',
            'Model\Checkout\Verifier::verify($checkoutToken)',
            'Controller\Checkout\Confirm::confirmUrl($attemptId)',
        ] as $expected) {
            $this->assertContains($expected, $checked, 'The scan did not reach ' . $expected);
        }
    }

    public function testTheConfirmRedirectUrlIsMarkedSensitive(): void
    {
        // The POST bounce redirects to a URL that carries both the attempt id and the token.
        $this->assertTrue($this->isSensitive(new ReflectionParameter([Confirm::class, 'redirect'], 'url')));
    }

    /**
     * @param ReflectionParameter $parameter
     * @return bool
     */
    private function isSensitive(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(self::ATTRIBUTE) !== [];
    }

    /**
     * Every class, interface and enum of the module (PSR-4: one per file under src/).
     *
     * @return list<class-string>
     */
    private function classes(): array
    {
        $source = (string) realpath(self::SOURCE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        $classes = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo
                || $file->getExtension() !== 'php'
                || $file->getFilename() === 'registration.php'
            ) {
                continue;
            }
            $class = self::NAMESPACE_PREFIX
                . str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4));
            $this->assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                $file->getPathname() . ' does not declare ' . $class
            );
            $classes[] = $class;
        }
        sort($classes);
        $this->assertNotEmpty($classes);

        return $classes;
    }
}
