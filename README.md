# ps8_verify_multishop

PrestaShop 8 module — Diagnostic and fix tool for multishop data integrity.

## Features

- Dashboard with session-cached diagnostics overview
- Analyzes: shops, products (9 steps), stocks, content, carriers, payment modules
- Fixes: shop group sharing, product/stock/content duplication, carrier associations, module config
- Dry-run preview before applying any fix
- CSRF protection on all write operations
- Clears PrestaShop cache after successful fixes

## Diagnostics covered

| Domain | Description |
|--------|-------------|
| Shop Group | share_customer, share_order, share_stock flags |
| Products | 9-step multishop completeness (translations, combinations, features, promotions) |
| Stocks | Distribution across shops, NULL quantities, share_stock alignment |
| Content | image_shop, CMS pages, meta/SEO associations |
| Carriers | Zone coverage, delivery ranges, shop associations |
| Config | Blocking NULLs, shop-level overrides |

## Requirements

- PrestaShop 8.x
- PHP 8.1+
- Doctrine DBAL (provided by PrestaShop)

## Installation

Upload to `modules/sc_verify_multishop/` and install from Back Office > Modules.

The module registers under **Advanced Parameters > Scriptami** using the shared `AdminScriptami` parent tab.

## Architecture

```
src/
├── Controller/Admin/     # Dashboard, Diagnostic, Fix controllers
├── Service/              # AnalyzerService, DiagnosticRegistry, 6 fixers
└── Traits/               # HaveScriptamiTab (shared AdminScriptami tab management)
```

## Tests

```bash
composer install
./vendor/bin/phpunit --testdox
```

142 tests, 428 assertions.

## Part of the Scriptami Suite

This module is part of the **Scriptami** suite of PrestaShop 8 maintenance tools:

- [ps8_verify_multishop](https://github.com/RebelliousSmile/ps8_verify_multishop) — Multishop data integrity
- [ps8_replace_text](https://github.com/RebelliousSmile/ps8_replace_text) — Find & replace across the database
- [ps8_giftcard_repair](https://github.com/RebelliousSmile/ps8_giftcard_repair) — Gift card data repair
- [ps8_iqit_repair](https://github.com/RebelliousSmile/ps8_iqit_repair) — IQIT Warehouse theme module repair
