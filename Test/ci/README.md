# CI harness

Everything the public CI needs to check this module without a Magento installation and without Hyvä or
Magewire packages. None of it ships: `Test/` is `export-ignore`d, and the module's own `composer.json` has no
`replace` or `provide` entries.

## What is in here

| File | Purpose |
|---|---|
| `composer.json` | A throwaway Composer project. It installs the Magento Open Source packages the module uses from the Mage-OS mirror (`https://mirror.mage-os.org/`, no credentials; restricted to `magento/*`), `affirm/magento2` from Packagist and the dev tools (PHPUnit 10.5, PHPStan 1.12 with `bitexpert/phpstan-magento`, php-cs-fixer, the Magento coding standard). It autoloads `../../src/` and `../../Test/` and loads the test doubles. No lock file is committed, so every run resolves the newest releases for its PHP version. |
| `stubs/magewire.php` | MIT test double for `Magewirephp\Magewire\Component` (magewirephp/magewire): the base class with the `$listeners` and `$uncallables` properties the payment component redeclares, typed as upstream. |
| `stubs/hyva-checkout.php` | MIT test doubles for the hyva-themes/magento2-hyva-checkout classes the module uses: `Config`, `CheckoutNotFoundException`, the evaluation API (`EvaluationInterface`, `EvaluationResultInterface`, `EvaluationResultFactory`, and the results `Success`, `ErrorEventMessage` with their parents `EvaluationResult`, `Event`, `ErrorEvent`), `SystemConfigEvaluationApi`, and the place-order API (`AbstractPlaceOrderService`, `PlaceOrderServiceInterface`, `AbstractOrderData`, `DefaultOrderData`). |
| `stubs/hyva-theme-module.php` | MIT test doubles for `Hyva\Theme\Model\ViewModelRegistry` and `Hyva\Theme\ViewModel\HyvaCsp` (hyva-themes/magento2-theme-module). |
| `bootstrap.php` | PHPUnit bootstrap: the harness autoloader plus Magento's unit-test generators for factories, proxies and extension attribute classes (written to `var/generated/`, ignored by Git), and Magento's placeholder phrase renderer. |
| `phpstan.neon` | Includes `../../phpstan.neon.dist` (level 6) and the bitexpert extension, analyses `src/` and `Test/Unit/`. The extension is used for type resolution of generated classes only; its opinionated rules are off so CI checks what the level 6 run on an installation checks. |
| `phpstan-older-dependencies.php` | Adds ignores only when an older dependency release is installed (PHP 8.1 resolves to Magento 2.4.7, whose `ManagerInterface` documents messages as `string` only, and to Monolog 2, which has no `LogRecord` class). |

### The test doubles

Each double is written from the public API of the release named in its header, not copied: same class and
member names, parameter types and return types, but only the members this module's code and unit tests use.
Evaluation results, order data and the place-order service defaults keep their plain behaviour, because the
tests use real instances of them (for example `new ErrorEventMessage(...)` and `new DefaultOrderData()`).
Methods that need a Magento runtime (the object manager, configuration, CSP) throw a `LogicException`; unit
tests mock them.

`Hyva\Checkout\Model\Magewire\Payment\PlaceOrderServiceProvider` appears only in `etc/frontend/di.xml` and
needs no double. When the module starts using another Hyvä or Magewire class or member, add it to the matching
file with the upstream signature (check the installed code of the oldest supported release), then run
`composer dump-autoload` in `Test/ci`.

## What CI runs

`.github/workflows/ci.yml`:

- **syntax**: `php -l` on every PHP and PHTML file with PHP 8.1, 8.2, 8.3, 8.4 and 8.5.
- **quality** on PHP 8.1 (resolves Magento 2.4.7, Monolog 2) and PHP 8.3 (resolves Magento 2.4.9, Monolog 3):
  `composer install` in `Test/ci`, php-cs-fixer (dry run), PHPCS with the Magento2 standard (errors fail,
  warnings are reported), PHPStan, PHPUnit. On Monolog 2 the two `LogRecord` tests skip by design.
- **js**: `node --test Test/js/*.test.mjs` on Node 20.

Licensed runs (Hyvä Checkout integration, sandbox and Playwright tests on a real store) are not part of public
CI; they are recorded per release in the module's README.

## Running it locally

Requirements: PHP 8.1 or later with the extensions Magento needs (bcmath, ctype, curl, dom, gd, iconv, intl,
mbstring, openssl, pdo_mysql, simplexml, soap, sockets, sodium, xsl, zip; ftp for Magento 2.4.9), Composer 2
and Node 20 or later. From the package root:

```sh
(cd Test/ci && composer install)
Test/ci/vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no --config=.php-cs-fixer.dist.php
Test/ci/vendor/bin/phpcs --standard=phpcs.xml
Test/ci/vendor/bin/phpstan analyse -c Test/ci/phpstan.neon
Test/ci/vendor/bin/phpunit -c phpunit.xml.dist
node --test Test/js/*.test.mjs
```

To try the PHP 8.1 resolution from a newer PHP, run
`composer config platform.php 8.1.27 && composer update --dry-run` in a copy of `Test/ci` (do not commit the
platform setting).

On a Magento installation with Hyvä Checkout the unit tests can also run through Magento's own configuration:
`vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist <path-to-this-package>/Test/Unit`.
