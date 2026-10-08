<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Model\Attempt;

use Hyva\CheckoutAffirm\Model\Attempt\OwnerKey;
use Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface;
use Magento\Checkout\Model\Session;
use PHPUnit\Framework\TestCase;

class OwnerKeyTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    /**
     * @var OwnerKey
     */
    private OwnerKey $ownerKey;

    protected function setUp(): void
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', '__call'])
            ->getMock();
        $session->method('getData')->willReturnCallback(fn ($key = '') => $this->storage[$key] ?? null);
        $session->method('__call')->willReturnCallback(function (string $method, array $args) use ($session) {
            $this->assertSame('setData', $method);
            $this->storage[$args[0]] = $args[1];
            return $session;
        });

        $this->ownerKey = new OwnerKey($session);
    }

    public function testCreatesKeyOnceAndReturnsItsHash(): void
    {
        $hash = $this->ownerKey->getHash();

        $key = $this->storage[OwnerKeyInterface::SESSION_KEY];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame(hash('sha256', $key), $hash);
        $this->assertSame($hash, $this->ownerKey->getHash());
        $this->assertSame($key, $this->storage[OwnerKeyInterface::SESSION_KEY]);
    }

    public function testMatches(): void
    {
        $hash = $this->ownerKey->getHash();

        $this->assertTrue($this->ownerKey->matches($hash));
        $this->assertFalse($this->ownerKey->matches(hash('sha256', 'someone else')));
        $this->assertFalse($this->ownerKey->matches(''));
    }

    public function testMatchesNeverCreatesAKey(): void
    {
        $this->assertFalse($this->ownerKey->matches(hash('sha256', '')));
        $this->assertArrayNotHasKey(OwnerKeyInterface::SESSION_KEY, $this->storage);
    }

    public function testMalformedSessionValueIsReplaced(): void
    {
        $this->storage[OwnerKeyInterface::SESSION_KEY] = ['not', 'a', 'key'];
        $this->assertFalse($this->ownerKey->matches(hash('sha256', '')));

        $hash = $this->ownerKey->getHash();

        $this->assertIsString($this->storage[OwnerKeyInterface::SESSION_KEY]);
        $this->assertTrue($this->ownerKey->matches($hash));
    }
}
