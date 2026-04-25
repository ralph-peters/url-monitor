# URL Monitor

Monitors URLs for changes and sends a Slack notification when something relevant is detected. Runs hourly via GitHub Actions.

Two URLs use custom extractors that track the latest security release per Shopware branch, rather than hashing the full page:

| URL | Extractor |
|-----|-----------|
| `https://www.shopware.com/nl/changelog/` | Latest security-tagged release per branch (6.5 / 6.6 / 6.7) |
| `https://store.shopware.com/en/swag136939272659f/shopware-6-security-plugin.html` | Latest plugin version per branch (2.x → 6.5, 3.x → 6.6, 4.x → 6.7) |

Extracted values and plain hashes are cached in `.cache/` and committed back to the repo after each run.

## Requirements

- PHP 8.2+
- Extensions: `curl`, `mbstring`, `xml`
- Composer

## Running locally

Install dependencies:

```bash
composer install
```

Check a URL:

```bash
php monitor.php "https://www.shopware.com/nl/changelog/"
php monitor.php "https://store.shopware.com/en/swag136939272659f/shopware-6-security-plugin.html"
```

The first run saves a baseline to `.cache/`. Subsequent runs compare against it and print `[CHANGED]` if something new is detected. Slack notifications are disabled locally (`SLACK_DRY_RUN = true`).

## Running the tests

```bash
./vendor/bin/phpunit --testdox
```
