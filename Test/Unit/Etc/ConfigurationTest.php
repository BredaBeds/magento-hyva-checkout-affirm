<?php
/**
 * Copyright (c) 2026 Breda Beds. Licensed under the MIT licence; see LICENSE.
 */
declare(strict_types=1);

namespace Hyva\CheckoutAffirm\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hyva\CheckoutAffirm\Cron\CleanupAttempts;
use Hyva\CheckoutAffirm\Cron\EscalateStaleAttempts;
use Hyva\CheckoutAffirm\Logger\RedactCheckoutTokenProcessor;
use Hyva\CheckoutAffirm\Observer\BlockUnresolvedAttempts;
use Magento\Framework\Config\Dom;
use Magento\Framework\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Static checks of the module's XML configuration: schema validity, the hand-written declarative-schema
 * whitelist and the registrations other parts of the module rely on.
 */
class ConfigurationTest extends TestCase
{
    private const ETC = __DIR__ . '/../../../src/etc';

    /**
     * @return array<string, array{0: string}>
     */
    public static function xmlFiles(): array
    {
        $files = [];
        foreach (['acl.xml', 'config.xml', 'crontab.xml', 'db_schema.xml', 'di.xml', 'events.xml',
            'frontend/di.xml', 'frontend/events.xml', 'frontend/routes.xml'] as $file) {
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

    public function testWhitelistMatchesDeclaredSchema(): void
    {
        $xpath = new DOMXPath($this->load('db_schema.xml'));
        $expected = [];
        foreach ($xpath->query('/schema/table') ?: [] as $table) {
            /** @var DOMElement $table */
            $name = $table->getAttribute('name');
            foreach ($xpath->query('column', $table) ?: [] as $column) {
                /** @var DOMElement $column */
                $expected[$name]['column'][$column->getAttribute('name')] = true;
            }
            foreach ($xpath->query('index', $table) ?: [] as $index) {
                /** @var DOMElement $index */
                $columns = [];
                foreach ($xpath->query('column', $index) ?: [] as $column) {
                    /** @var DOMElement $column */
                    $columns[] = $column->getAttribute('name');
                }
                // Magento's generated name: TABLE_COLUMN1_COLUMN2 (no prefix; short enough not to be hashed).
                $generated = strtoupper($name . '_' . implode('_', $columns));
                $this->assertSame($generated, $index->getAttribute('referenceId'));
                $this->assertLessThanOrEqual(64, strlen($generated));
                $expected[$name]['index'][$generated] = true;
            }
            foreach ($xpath->query('constraint', $table) ?: [] as $constraint) {
                /** @var DOMElement $constraint */
                $expected[$name]['constraint'][$constraint->getAttribute('referenceId')] = true;
            }
        }

        $whitelist = json_decode(
            (string) file_get_contents(self::ETC . '/db_schema_whitelist.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($expected, $whitelist);
    }

    public function testRedactionProcessorIsRegisteredOnBothLoggers(): void
    {
        $xpath = new DOMXPath($this->load('di.xml'));
        foreach (['Astound\Affirm\Logger\Logger', 'Magento\Framework\Logger\Monolog'] as $logger) {
            $items = $xpath->query(
                sprintf('/config/type[@name="%s"]/arguments/argument[@name="processors"]/item', $logger)
            );
            $this->assertNotFalse($items);
            $this->assertCount(1, $items, $logger);
            $this->assertSame(RedactCheckoutTokenProcessor::class, trim((string) $items->item(0)?->textContent));
        }
    }

    public function testGridCollectionIsDeclaredGlobally(): void
    {
        $xpath = new DOMXPath($this->load('di.xml'));
        $items = $xpath->query(
            '/config/type[@name="Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory"]'
            . '/arguments/argument[@name="collections"]/item[@name="hyva_affirm_attempt_listing_data_source"]'
        );

        $this->assertNotFalse($items);
        $this->assertCount(1, $items);
        $this->assertFileDoesNotExist(self::ETC . '/adminhtml/di.xml');
    }

    public function testOwnPreferencesResolve(): void
    {
        $xpath = new DOMXPath($this->load('di.xml'));
        $owned = [
            'Hyva\CheckoutAffirm\Model\Attempt\AttemptRepositoryInterface',
            'Hyva\CheckoutAffirm\Model\Attempt\OwnerKeyInterface',
            'Hyva\CheckoutAffirm\Model\Attempt\SnapshotInterface',
            'Hyva\CheckoutAffirm\Model\Checkout\VerifierInterface',
        ];
        foreach ($owned as $interface) {
            $preference = $xpath->query(sprintf('/config/preference[@for="%s"]', $interface));
            $this->assertNotFalse($preference);
            $this->assertCount(1, $preference, $interface);
            /** @var DOMElement $element */
            $element = $preference->item(0);
            $type = $element->getAttribute('type');
            $this->assertTrue(class_exists($type), $type);
            $this->assertContains($interface, class_implements($type) ?: []);
        }
    }

    public function testSubmitGuardCoversQuoteManagementAndMultishipping(): void
    {
        $xpath = new DOMXPath($this->load('events.xml'));
        $guarded = [];
        $observers = $xpath->query('/config/event/observer[@name="hyva_affirm_block_unresolved_attempts"]');
        foreach ($observers ?: [] as $node) {
            /** @var DOMElement $node */
            $this->assertSame(BlockUnresolvedAttempts::class, $node->getAttribute('instance'));
            /** @var DOMElement $event */
            $event = $node->parentNode;
            $guarded[] = $event->getAttribute('name');
        }

        $this->assertSame(
            ['sales_model_service_quote_submit_before', 'checkout_type_multishipping_create_orders_single'],
            $guarded
        );
    }

    public function testCronJobs(): void
    {
        $xpath = new DOMXPath($this->load('crontab.xml'));
        $jobs = [];
        foreach ($xpath->query('/config/group[@id="default"]/job') ?: [] as $job) {
            /** @var DOMElement $job */
            $schedule = $xpath->query('schedule', $job);
            $this->assertNotFalse($schedule);
            $jobs[$job->getAttribute('name')] = [
                $job->getAttribute('instance'),
                $job->getAttribute('method'),
                trim((string) $schedule->item(0)?->textContent),
            ];
        }

        $this->assertSame([
            'hyva_affirm_attempt_cleanup' => [CleanupAttempts::class, 'execute', '17 3 * * *'],
            'hyva_affirm_attempt_escalate' => [EscalateStaleAttempts::class, 'execute', '*/5 * * * *'],
        ], $jobs);
        foreach ($jobs as [$class, $method]) {
            $this->assertTrue(method_exists($class, $method), $class);
        }
    }

    public function testDebugReplaceKeysMaskTheToken(): void
    {
        $xpath = new DOMXPath($this->load('config.xml'));
        $nodes = $xpath->query('/config/default/payment/affirm_gateway/debugReplaceKeys');
        $this->assertNotFalse($nodes);
        $value = trim((string) $nodes->item(0)?->textContent);

        $this->assertSame(['uri', 'checkout_id', 'checkout_token'], explode(',', $value));
    }

    private function load(string $file): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->load(self::ETC . '/' . $file), $file);

        return $dom;
    }
}
