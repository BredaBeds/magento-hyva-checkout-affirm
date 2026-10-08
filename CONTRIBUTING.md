# Contributing

Thanks for helping improve the Hyvä Checkout integration for Affirm.

## Reporting a problem

Open an issue with the versions of Magento, PHP, Hyvä (theme module, default theme, Hyvä Checkout where relevant),
Affirm's extension (`affirm/magento2`) and this module, the store's relevant Affirm settings (never API keys), the
steps to reproduce and what you expected. Never post API keys, checkout tokens, customer data or order data.

Security issues: please do not open a public issue; use GitHub's private vulnerability reporting for this repository.

## Pull requests

- One change per pull request, with a test where the change is testable.
- Run the checks the CI runs: `php-cs-fixer fix --dry-run`, `phpcs`, PHPStan and the unit tests
  (see `.github/workflows/ci.yml` and `Test/ci/README.md`).
- Code style: Magento's php-cs-fixer rule set and `Magento2` PHPCS standard, `declare(strict_types=1)` in every
  PHP and PHTML file, constructor property promotion, CSP-compatible Alpine.js (named `Alpine.data` components,
  no inline expressions), every inline `<script>` followed by `$hyvaCsp->registerInlineScript()`.
- Do not copy code from commercial Hyvä modules. Code adapted from Affirm's extension keeps its BSD-3-Clause notice
  (see NOTICE) and a provenance comment.
- Add an entry to `CHANGELOG.md` under "Unreleased".

By contributing you agree that your contribution is licensed under the MIT licence of this project.
