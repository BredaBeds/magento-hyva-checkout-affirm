<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\View;

use DOMDocument;
use DOMXPath;
use Hyva\CheckoutAffirm\Controller\Checkout\Confirm;
use Hyva\Theme\ViewModel\HyvaCsp;
use Magento\Framework\Config\Dom;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\Element\Template;
use PHPUnit\Framework\TestCase;

/**
 * Layout files and templates of the confirmation flow: schema validity, the names the controller relies on,
 * escaping and CSP registration of inline scripts.
 */
class ConfirmationViewTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../../src/view/frontend';

    public function testLayoutFilesMatchThePageSchema(): void
    {
        foreach (['hyva_affirm_checkout_confirm.xml', 'hyva_checkout_onepage_success.xml'] as $file) {
            $dom = $this->load('/layout/' . $file);
            try {
                $errors = Dom::validateDomDocument(
                    $dom,
                    'urn:magento:framework:View/Layout/etc/page_configuration.xsd'
                );
            } catch (NotFoundException $exception) {
                $this->markTestSkipped('Page configuration schema not available here.');
            }
            $this->assertSame([], $errors, $file . ': ' . implode("\n", array_map('strval', $errors)));
        }
    }

    public function testConfirmPageDeclaresTheBlocksTheControllerUses(): void
    {
        $xpath = new DOMXPath($this->load('/layout/hyva_affirm_checkout_confirm.xml'));

        $status = $xpath->query(
            sprintf('//referenceContainer[@name="content"]/block[@name="%s"]', Confirm::STATUS_BLOCK)
        );
        $this->assertNotFalse($status);
        $this->assertCount(1, $status);
        $statusBlock = $status->item(0);
        $this->assertInstanceOf(\DOMElement::class, $statusBlock);
        $this->assertSame('false', $statusBlock->getAttribute('cacheable'), 'The page must never be cached.');
        $this->assertSame(
            'Hyva_CheckoutAffirm::checkout/attempt/in-progress.phtml',
            $statusBlock->getAttribute('template')
        );

        $refresh = $xpath->query(
            sprintf('//referenceContainer[@name="content"]/block[@name="%s"]', Confirm::REFRESH_BLOCK)
        );
        $this->assertNotFalse($refresh);
        $this->assertCount(1, $refresh);
        $refreshBlock = $refresh->item(0);
        $this->assertInstanceOf(\DOMElement::class, $refreshBlock);
        $this->assertSame(
            'Hyva_CheckoutAffirm::checkout/attempt/status-check.phtml',
            $refreshBlock->getAttribute('template')
        );
        $this->assertSame(Confirm::STATUS_BLOCK, $refreshBlock->getAttribute('after'));
    }

    public function testConfirmPageNeverReloadsItselfOnATimer(): void
    {
        // WCAG 2.2.1 (failure F41): the wait is followed in the background, not with a meta refresh.
        $layout = (string) file_get_contents(self::VIEW . '/layout/hyva_affirm_checkout_confirm.xml');
        $this->assertStringNotContainsStringIgnoringCase('http-equiv', $layout);
        foreach (['in-progress', 'status-check'] as $template) {
            $source = (string) file_get_contents(self::VIEW . '/templates/checkout/attempt/' . $template . '.phtml');
            $this->assertStringNotContainsStringIgnoringCase('http-equiv="refresh"', $source, $template);
        }
    }

    public function testSuccessPageBlockRendersBeforeThePageJsDependencies(): void
    {
        $xpath = new DOMXPath($this->load('/layout/hyva_checkout_onepage_success.xml'));
        $blocks = $xpath->query('//referenceContainer[@name="before.body.end"]/block');

        $this->assertNotFalse($blocks);
        $this->assertCount(1, $blocks);
        $block = $blocks->item(0);
        $this->assertInstanceOf(\DOMElement::class, $block);
        $this->assertSame('page-js-dependencies', $block->getAttribute('before'));
        $this->assertSame('Hyva_CheckoutAffirm::checkout/success/cart-refresh.phtml', $block->getAttribute('template'));
    }

    public function testEveryInlineScriptIsRegisteredForCsp(): void
    {
        $templates = [
            'checkout/attempt/in-progress.phtml',
            'checkout/attempt/status-check.phtml',
            'checkout/success/cart-refresh.phtml',
        ];
        foreach ($templates as $template) {
            $source = (string) file_get_contents(self::VIEW . '/templates/' . $template);
            $this->assertStringStartsWith("<?php declare(strict_types=1);\n", $source, $template);
            $scripts = substr_count($source, '</script>');
            $this->assertGreaterThan(0, $scripts);
            $this->assertSame(
                $scripts,
                preg_match_all(
                    '#</script>\s*<\?php if \(isset\(\$hyvaCsp\)\) \{\s*\$hyvaCsp->registerInlineScript\(\);#',
                    $source
                ),
                $template
            );
        }
    }

    public function testInProgressPageRendersTheEscapedMessage(): void
    {
        $html = $this->render('checkout/attempt/in-progress.phtml', [
            'status' => 'in_progress',
            'message' => 'Being <confirmed>',
            'order_reference' => '000000123',
            'retry_url' => 'https://shop.test/hyva_affirm/checkout/confirm/attempt/abc/?checkout_token=T&x=1',
        ]);

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Being &lt;confirmed&gt;', $html);
        $this->assertStringContainsString('see whether your order has been placed', $html);
        $this->assertStringContainsString('data-affirm-attempt-note', $html);
        $this->assertStringContainsString(
            '<a href="https://shop.test/hyva_affirm/checkout/confirm/attempt/abc/?checkout_token=T&amp;x=1"',
            $html,
            'The wait can always be followed by hand.'
        );
        $this->assertStringContainsString('Check the status now', $html);
        $this->assertStringNotContainsString('000000123', $html);
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }

    public function testStatusCheckAsksInTheBackgroundAndLoadsThePageOnlyWhenTheWaitEnds(): void
    {
        $html = $this->render('checkout/attempt/status-check.phtml', []);

        $this->assertStringContainsString("url.searchParams.set('poll', '1')", $html);
        $this->assertStringContainsString('(await response.json()).in_progress !== false', $html);
        $this->assertStringContainsString('window.location.replace(window.location.href)', $html);
        $this->assertStringContainsString("document.querySelector('[data-affirm-attempt-note]')", $html);
        $this->assertStringContainsString("const checking = 'We\\u0020check\\u0020every", $html);
        $this->assertStringContainsString("const slow = 'This\\u0020is\\u0020taking\\u0020longer", $html);
    }

    public function testWaitingForReviewPageShowsTheOrderReferenceAndDoesNotPromiseARefresh(): void
    {
        $html = $this->render('checkout/attempt/in-progress.phtml', [
            'status' => 'uncertain',
            'message' => 'We are confirming your payment.',
            'order_reference' => '000000123',
        ]);

        $this->assertStringContainsString('#000000123', $html);
        $this->assertStringNotContainsString('data-affirm-attempt-note', $html);
        $this->assertStringNotContainsString('Check the status now', $html);
    }

    public function testCartRefreshExpiresTheSectionCookieAndRequestsAReload(): void
    {
        $html = $this->render('checkout/success/cart-refresh.phtml', []);

        $this->assertStringContainsString("hyva.setCookie('mage-cache-sessid', '', -1, true)", $html);
        $this->assertStringContainsString("new CustomEvent('reload-customer-section-data')", $html);
    }

    public function testTemplatesWorkWithoutHyvaCsp(): void
    {
        $html = $this->render('checkout/success/cart-refresh.phtml', [], false);

        $this->assertStringContainsString('<script>', $html);
    }

    /**
     * Render a template with a block holding $data.
     *
     * @param string $template
     * @param array<string, mixed> $data
     * @param bool $withCsp
     * @return string
     */
    private function render(string $template, array $data, bool $withCsp = true): string
    {
        $block = $this->getMockBuilder(Template::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toHtml'])
            ->getMock();
        $block->setData($data);
        // escapeUrl() asks the inline translator, which Magento would fetch from the object manager.
        $escaper = new Escaper();
        (new \ReflectionProperty(Escaper::class, 'translateInline'))
            ->setValue($escaper, $this->createMock(InlineInterface::class));
        $variables = ['block' => $block, 'escaper' => $escaper];
        if ($withCsp) {
            $csp = $this->createMock(HyvaCsp::class);
            $csp->expects($this->once())->method('registerInlineScript');
            $variables['hyvaCsp'] = $csp;
        }

        $file = self::VIEW . '/templates/' . $template;
        ob_start();
        try {
            (static function (string $file, array $variables): void {
                extract($variables);
                include $file;
            })($file, $variables);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Load a view file.
     *
     * @param string $path
     * @return DOMDocument
     */
    private function load(string $path): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->load(self::VIEW . $path), $path);

        return $dom;
    }
}
