<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\View;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hyva\CheckoutAffirm\Magewire\Payment\Method\AffirmGateway;
use Hyva\CheckoutAffirm\Model\Magewire\Payment\PlaceOrderService;
use Hyva\CheckoutAffirm\ViewModel\Method;
use Magento\Framework\Config\Dom;
use Magento\Framework\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static checks of the checkout layouts and templates: the contracts Hyvä Checkout and Magewire rely on.
 */
class FrontendViewTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../../src/view/frontend';

    /**
     * sha256 of the window.hyvaAffirm loader script (contract v1, frozen and identical in both Affirm modules).
     */
    private const LOADER_SHA256 = 'c45fa08e48cac4518ad0aa8938e562971a49e0e36f700b2510bdc13bddcce23d';

    /**
     * @return array<string, array{0: string}>
     */
    public static function layouts(): array
    {
        return [
            'hyva_checkout_components.xml' => ['hyva_checkout_components.xml'],
            'hyva_checkout_index_index.xml' => ['hyva_checkout_index_index.xml'],
        ];
    }

    #[DataProvider('layouts')]
    public function testLayoutMatchesItsSchema(string $file): void
    {
        $dom = $this->layout($file);
        $schema = $dom->documentElement->getAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'noNamespaceSchemaLocation'
        );

        try {
            $errors = Dom::validateDomDocument($dom, $schema);
        } catch (NotFoundException $exception) {
            $this->markTestSkipped('Schema not available here: ' . $schema);
        }

        $this->assertSame([], $errors, implode("\n", array_map('strval', $errors)));
    }

    public function testMethodBlockFollowsHyvasPaymentContract(): void
    {
        $xpath = new DOMXPath($this->layout('hyva_checkout_components.xml'));
        $block = $this->single($xpath, '//referenceBlock[@name="checkout.payment.methods"]/block');

        $this->assertSame(AffirmGateway::COMPONENT_ID, $block->getAttribute('name'));
        $this->assertSame(AffirmGateway::METHOD_CODE, $block->getAttribute('as'));
        $this->assertTemplateExists($block->getAttribute('template'));
        $this->assertSame(
            AffirmGateway::class,
            trim($this->single($xpath, 'arguments/argument[@name="magewire"]', $block)->textContent)
        );

        $icon = $this->single($xpath, 'arguments/argument[@name="metadata"]/item[@name="icon"]', $block);
        $this->assertSame(
            'Hyva_CheckoutAffirm::images/affirm.svg',
            trim($this->single($xpath, 'item[@name="src"]', $icon)->textContent)
        );
        $this->assertFileExists(self::VIEW . '/web/images/affirm.svg');
        foreach (['width', 'height', 'alt'] as $attribute) {
            $this->single($xpath, 'item[@name="attributes"]/item[@name="' . $attribute . '"]', $icon);
        }
        $this->assertSame(
            0,
            $xpath->query('arguments/argument[@name="metadata"]/item[@name="subtitle"]', $block)->length,
            'Hyvä escapes the subtitle; the info HTML is rendered in the body instead'
        );

        $inline = $this->single($xpath, 'block[@as="inline"]', $block);
        $this->assertTemplateExists($inline->getAttribute('template'));
    }

    public function testCheckoutPageRegistersTheMethodScriptAndTheLoader(): void
    {
        $xpath = new DOMXPath($this->layout('hyva_checkout_index_index.xml'));

        $removed = [];
        foreach ($xpath->query('/page/head/remove') ?: [] as $remove) {
            /** @var DOMElement $remove */
            $removed[] = $remove->getAttribute('src');
        }
        sort($removed);
        $this->assertSame(['Astound_Affirm::affirm-promo.css', 'Astound_Affirm::affirm.css'], $removed);

        $templates = [];
        $container = '//referenceContainer[@name="hyva.checkout.api-v1.payment-methods"]/block';
        foreach ($xpath->query($container) ?: [] as $block) {
            /** @var DOMElement $block */
            $this->assertTemplateExists($block->getAttribute('template'));
            $this->assertSame('payment/affirm_gateway/active', $block->getAttribute('ifconfig'));
            $templates[] = $block->getAttribute('template');
        }
        $this->assertSame([
            'Hyva_CheckoutAffirm::js/sdk-loader.phtml',
            'Hyva_CheckoutAffirm::checkout/payment/affirm-gateway-js.phtml',
        ], $templates);
    }

    public function testMethodTemplateHasOneMagewireRoot(): void
    {
        $template = $this->template('checkout/payment/method/affirm-gateway.phtml');
        $html = trim((string) preg_replace('/<\?php.*?\?>/s', '', $template));

        $this->assertStringStartsWith('<div wire:key="affirm-gateway"', $html, 'Magewire marks the first element');
        $this->assertStringEndsWith('</div>', $html);
        $this->assertStringContainsString('x-data="hyvaCheckoutAffirmGateway"', $html);
        foreach (['data-mode', 'data-edu', 'data-inline-version', 'data-inline-checkout'] as $attribute) {
            $this->assertStringContainsString($attribute . '="', $html);
        }
        $this->assertStringNotContainsString('<script', $template, 'scripts do not run inside Magewire morphs');
        // Alpine's CSP build and Magewire's morph: no directives beyond x-data, wire:key and wire:ignore.
        $this->assertDoesNotMatchRegularExpression(
            '/wire:model|wire:click|x-on:|@click|x-text|x-bind:|\s:[a-z]+=/',
            $template
        );

        $inline = $this->template('checkout/payment/method/inline.phtml');
        $this->assertStringContainsString('<div wire:ignore', $inline);
        $this->assertStringContainsString('Method::INLINE_CONTAINER_ID', $inline);
    }

    public function testMethodScriptIsCspSafe(): void
    {
        $template = $this->template('checkout/payment/affirm-gateway-js.phtml');

        $this->assertSame(1, substr_count($template, '<script>'));
        // The CSP nonce is registered right after the script.
        $this->assertStringContainsString(
            "</script>\n<?php isset(\$hyvaCsp) && \$hyvaCsp->registerInlineScript() ?>",
            $template
        );
        $this->assertStringContainsString("Alpine.data('hyvaCheckoutAffirmGateway'", $template);
        $this->assertStringContainsString('hyvaCheckout.payment.registerMethod(', $template);
        $this->assertStringContainsString('/* bridge:start */', $template);
        $this->assertStringContainsString('/* bridge:end */', $template);
        $this->assertStringNotContainsString('.$wire', $template, 'Magewire.find() already returns $wire');
        $this->assertStringNotContainsString('Magewire.hook', $template);
        $this->assertDoesNotMatchRegularExpression('/\beval\(|new Function/', $template);
        $this->assertStringContainsString('PlaceOrderService::KEY_CHECKOUT_TOKEN', $template);
        $this->assertStringContainsString('PlaceOrderService::KEY_ATTEMPT_ID', $template);
        $this->assertSame('checkout_token', PlaceOrderService::KEY_CHECKOUT_TOKEN);
        $this->assertSame('attempt_id', PlaceOrderService::KEY_ATTEMPT_ID);
        $this->assertSame('hyva-affirm-inline-checkout', Method::INLINE_CONTAINER_ID);
    }

    public function testLoaderIsTheFrozenContract(): void
    {
        $template = $this->template('js/sdk-loader.phtml');

        $this->assertSame(self::LOADER_SHA256, hash('sha256', $this->script($template)));
        $this->assertStringContainsString('\Hyva\CheckoutAffirm\ViewModel\Sdk::class', $template);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templates(): array
    {
        return [
            'method' => ['checkout/payment/method/affirm-gateway.phtml'],
            'inline' => ['checkout/payment/method/inline.phtml'],
            'script' => ['checkout/payment/affirm-gateway-js.phtml'],
            'loader' => ['js/sdk-loader.phtml'],
        ];
    }

    #[DataProvider('templates')]
    public function testTemplatesFollowTheHouseHeader(string $file): void
    {
        $template = $this->template($file);

        $this->assertStringStartsWith('<?php declare(strict_types=1);', $template);
        $this->assertStringContainsString('Copyright (c) 2026 Breda Beds. Licensed under the MIT licence', $template);
    }

    private function assertTemplateExists(string $template): void
    {
        $this->assertStringStartsWith('Hyva_CheckoutAffirm::', $template);
        $this->assertFileExists(self::VIEW . '/templates/' . substr($template, strlen('Hyva_CheckoutAffirm::')));
    }

    private function single(DOMXPath $xpath, string $query, ?DOMElement $context = null): DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, $query);
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function layout(string $file): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->load(self::VIEW . '/layout/' . $file));

        return $dom;
    }

    private function template(string $file): string
    {
        $path = self::VIEW . '/templates/' . $file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function script(string $template): string
    {
        $this->assertSame(1, preg_match('#<script>\n(.*?)</script>#s', $template, $match));

        return $match[1];
    }
}
