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

        $refresh = $xpath->query(sprintf(
            '//referenceBlock[@name="head.additional"]/block[@name="%s"]/arguments/argument[@name="text"]',
            Confirm::REFRESH_BLOCK
        ));
        $this->assertNotFalse($refresh);
        $this->assertCount(1, $refresh);
        $this->assertSame('<meta http-equiv="refresh" content="3">', trim((string) $refresh->item(0)?->textContent));
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
        foreach (['checkout/attempt/in-progress.phtml', 'checkout/success/cart-refresh.phtml'] as $template) {
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
        ]);

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('Being &lt;confirmed&gt;', $html);
        $this->assertStringContainsString('refreshes automatically', $html);
        $this->assertStringNotContainsString('000000123', $html);
        $this->assertStringContainsString("addEventListener('pageshow'", $html);
    }

    public function testWaitingForReviewPageShowsTheOrderReferenceAndDoesNotPromiseARefresh(): void
    {
        $html = $this->render('checkout/attempt/in-progress.phtml', [
            'status' => 'uncertain',
            'message' => 'We are confirming your payment.',
            'order_reference' => '000000123',
        ]);

        $this->assertStringContainsString('#000000123', $html);
        $this->assertStringNotContainsString('refreshes automatically', $html);
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
        $escaper = new Escaper();
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
