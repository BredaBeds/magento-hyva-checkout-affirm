<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Plugin\Framework\Session;

use Hyva\CheckoutAffirm\Plugin\Framework\Session\SkipSessionOnConfirmPost;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionStartChecker;
use PHPUnit\Framework\TestCase;

class SkipSessionOnConfirmPostTest extends TestCase
{
    public function testSkipsTheSessionOnlyForAPostToTheConfirmController(): void
    {
        $confirm = '/hyva_affirm/checkout/confirm/attempt/' . str_repeat('a', 64) . '/';

        $this->assertFalse($this->check(true, true, $confirm));
        $this->assertFalse($this->check(true, true, '/hyva_affirm/checkout/confirm'));
        $this->assertFalse($this->check(true, true, 'hyva_affirm/checkout/confirm/'));

        $this->assertTrue($this->check(true, false, $confirm), 'GET carries the session cookie');
        $this->assertTrue($this->check(true, true, '/hyva_affirm/checkout/cancel/attempt/x'));
        $this->assertTrue($this->check(true, true, '/hyva_affirm/checkout/confirmation'));
        $this->assertTrue($this->check(true, true, '/hyva_affirm/cart/start'));
        $this->assertTrue($this->check(true, true, '/checkout/cart/add'));
    }

    public function testNeverStartsASessionThatWasRefusedAlready(): void
    {
        $this->assertFalse($this->check(false, false, '/checkout/cart'));
        $this->assertFalse($this->check(false, true, '/hyva_affirm/checkout/confirm'));
    }

    /**
     * Run the plugin for a request.
     *
     * @param bool $result
     * @param bool $post
     * @param string $pathInfo
     * @return bool
     */
    private function check(bool $result, bool $post, string $pathInfo): bool
    {
        $request = $this->createMock(Http::class);
        $request->method('isPost')->willReturn($post);
        $request->method('getPathInfo')->willReturn($pathInfo);

        return (new SkipSessionOnConfirmPost($request))
            ->afterCheck($this->createMock(SessionStartChecker::class), $result);
    }
}
