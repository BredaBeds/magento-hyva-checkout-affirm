<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hyva\CheckoutAffirm\Console\Command\ListAttempts;
use Hyva\CheckoutAffirm\Console\Command\PruneAttempts;
use Hyva\CheckoutAffirm\Console\Command\ResolveAttempt;
use Hyva\CheckoutAffirm\Controller\Adminhtml\Attempt\Index;
use Hyva\CheckoutAffirm\Cron\CleanupAttempts;
use Hyva\CheckoutAffirm\Model\ResourceModel\Attempt\Grid\Collection;
use Magento\Framework\Config\Dom;
use Magento\Framework\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Static checks of the admin grid, menu, CLI and cron wiring.
 */
class AdminConfigurationTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src';

    private const LISTING = 'view/adminhtml/ui_component/hyva_affirm_attempt_listing.xml';

    private const LAYOUT = 'view/adminhtml/layout/hyva_checkoutaffirm_attempt_index.xml';

    /**
     * @return array<string, array{0: string}>
     */
    public static function xmlFiles(): array
    {
        $files = [];
        foreach (['etc/adminhtml/menu.xml', 'etc/adminhtml/routes.xml', self::LAYOUT, self::LISTING] as $file) {
            $files[$file] = [$file];
        }

        return $files;
    }

    #[DataProvider('xmlFiles')]
    public function testMatchesItsSchema(string $file): void
    {
        $dom = $this->load($file);
        $schema = $dom->documentElement->getAttributeNS(
            'http://www.w3.org/2001/XMLSchema-instance',
            'noNamespaceSchemaLocation'
        );
        $this->assertNotSame('', $schema);

        try {
            $errors = Dom::validateDomDocument($dom, $schema);
        } catch (NotFoundException $exception) {
            $this->markTestSkipped('Schema not available here: ' . $schema);
        }

        $this->assertSame([], $errors, implode("\n", array_map('strval', $errors)));
    }

    public function testMenuOpensTheGridThroughTheAdminRoute(): void
    {
        $routes = new DOMXPath($this->load('etc/adminhtml/routes.xml'));
        $route = $this->element($routes, '/config/router[@id="admin"]/route');
        $this->assertSame('hyva_checkoutaffirm', $route->getAttribute('id'));
        $module = $this->element($routes, '/config/router/route/module');
        $this->assertSame('Hyva_CheckoutAffirm', $module->getAttribute('name'));
        $this->assertSame('Magento_Backend', $module->getAttribute('before'));

        $menu = new DOMXPath($this->load('etc/adminhtml/menu.xml'));
        $item = $this->element($menu, '/config/menu/add');
        $this->assertSame('Magento_Sales::sales_operation', $item->getAttribute('parent'));
        $this->assertSame($route->getAttribute('frontName') . '/attempt/index', $item->getAttribute('action'));
        $this->assertSame(Index::ADMIN_RESOURCE, $item->getAttribute('resource'));

        // The layout handle is <route id>_<controller>_<action>.
        $this->assertFileExists(
            self::SRC . '/view/adminhtml/layout/' . $route->getAttribute('id') . '_attempt_index.xml'
        );
    }

    public function testAdminResourceIsDeclared(): void
    {
        $acl = new DOMXPath($this->load('etc/acl.xml'));
        $resources = $acl->query(sprintf('//resource[@id="%s"]', Index::ADMIN_RESOURCE));

        $this->assertNotFalse($resources);
        $this->assertCount(1, $resources);
        $listing = new DOMXPath($this->load(self::LISTING));
        $this->assertSame(
            Index::ADMIN_RESOURCE,
            trim($this->element($listing, '/listing/dataSource/aclResource')->textContent)
        );
    }

    public function testLayoutRendersTheListingWhoseDataSourceIsRegistered(): void
    {
        $layout = new DOMXPath($this->load(self::LAYOUT));
        $this->assertSame(
            'hyva_affirm_attempt_listing',
            $this->element($layout, '//referenceContainer[@name="content"]/uiComponent')->getAttribute('name')
        );

        $listing = new DOMXPath($this->load(self::LISTING));
        $dataSource = $this->element($listing, '/listing/dataSource')->getAttribute('name');
        $this->assertSame('hyva_affirm_attempt_listing_data_source', $dataSource);
        $dataProvider = $this->element($listing, '/listing/dataSource/dataProvider');
        $this->assertSame($dataSource, $dataProvider->getAttribute('name'));

        $di = new DOMXPath($this->load('etc/di.xml'));
        $item = $this->element($di, sprintf(
            '/config/type[@name="Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory"]'
            . '/arguments/argument[@name="collections"]/item[@name="%s"]',
            $dataSource
        ));
        $this->assertSame(Collection::class, trim($item->textContent));
        $this->element(
            $di,
            sprintf('/config/type[@name="%s"]/arguments/argument[@name="mainTable"]', Collection::class)
        );
    }

    public function testListingIsReadOnlyAndItsClassesExist(): void
    {
        $listing = new DOMXPath($this->load(self::LISTING));

        foreach (['massaction', 'selectionsColumn', 'actionsColumn', 'filterSearch', 'exportButton'] as $element) {
            $nodes = $listing->query('//' . $element);
            $this->assertNotFalse($nodes);
            $this->assertCount(0, $nodes, $element);
        }
        foreach ($listing->query('//*[@class]') ?: [] as $node) {
            /** @var DOMElement $node */
            $class = $node->getAttribute('class');
            $this->assertTrue(class_exists($class), $class);
        }
        $this->element($listing, sprintf('//columns/column[@name="%s"]', Collection::REFERENCE_FIELD));
    }

    public function testConsoleCommandsAndCronJobResolve(): void
    {
        $di = new DOMXPath($this->load('etc/di.xml'));
        $expected = [
            'hyva_affirm_attempt_list' => ListAttempts::class,
            'hyva_affirm_attempt_resolve' => ResolveAttempt::class,
            'hyva_affirm_attempt_prune' => PruneAttempts::class,
        ];
        foreach ($expected as $name => $class) {
            $item = $this->element($di, sprintf(
                '/config/type[@name="Magento\Framework\Console\CommandListInterface"]'
                . '/arguments/argument[@name="commands"]/item[@name="%s"]',
                $name
            ));
            $this->assertSame($class, trim($item->textContent));
            $this->assertTrue(is_subclass_of($class, Command::class), $class);
        }

        $cron = new DOMXPath($this->load('etc/crontab.xml'));
        $job = $this->element($cron, '//job[@name="hyva_affirm_attempt_cleanup"]');
        $this->assertSame(CleanupAttempts::class, $job->getAttribute('instance'));
        $this->assertTrue(method_exists(CleanupAttempts::class, $job->getAttribute('method')));
    }

    /**
     * @param DOMXPath $xpath
     * @param string $query
     * @return DOMElement
     */
    private function element(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes, $query);
        $this->assertCount(1, $nodes, $query);
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    /**
     * @param string $file
     * @return DOMDocument
     */
    private function load(string $file): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->load(self::SRC . '/' . $file), $file);

        return $dom;
    }
}
