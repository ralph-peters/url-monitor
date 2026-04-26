# URL Monitor

Monitors URLs for changes and sends a Slack notification when something relevant is detected. Runs hourly via GitHub Actions.

Two URLs use custom extractors that track the latest security release per Shopware branch, rather than hashing the full page:

| URL | Extractor |
|-----|-----------|
| `https://www.shopware.com/nl/changelog/` | Latest security-tagged release per branch (6.5 / 6.6 / 6.7) |
| `https://store.shopware.com/en/swag136939272659f/shopware-6-security-plugin.html` | Latest plugin version per branch (2.x → 6.5, 3.x → 6.6, 4.x → 6.7) |

Extracted values and plain hashes are cached in `.cache/` and committed back to the repo after each run.

## GitHub Actions

The workflow is defined in `.github/workflows/monitor.yml` and runs automatically every hour (`0 * * * *`). Each run:

1. Installs PHP and Composer dependencies
2. Runs the PHPUnit test suite — the workflow fails early if any test fails
3. Checks each monitored URL and sends a Slack notification if a change is detected
4. Commits any updated cache files back to the repo as `chore: update URL hashes`

**Triggering a manual run:** go to the repository on GitHub → Actions → URL Monitor → Run workflow.

**Slack notifications** are sent via a webhook configured as the `SLACK_WEBHOOK_URL` repository secret (Settings → Secrets and variables → Actions).

**Adjusting the schedule:** edit the cron expression in `.github/workflows/monitor.yml`. Examples:
- Every 30 minutes: `*/30 * * * *`
- Every 6 hours: `0 */6 * * *`

## Requirements

- PHP 8.2+
- Extensions: `curl`, `mbstring`, `xml`

  On Ubuntu/Debian (add the PPA first if packages are not found):
  ```bash
  sudo add-apt-repository ppa:ondrej/php && sudo apt update
  sudo apt install php8.3-curl php8.3-mbstring php8.3-xml
  ```
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
