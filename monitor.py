#!/usr/bin/env python3
"""
URL change monitor.
Fetches each URL, hashes its content, and compares against the last known hash.
Sends a Slack notification when a change is detected.
Hashes are stored as files in .cache/ so GitHub Actions can persist them via cache action.

Some URLs use custom extractors instead of full-page hashing — these extract a
specific signal from the page (e.g. only security-tagged releases) so that
unrelated page changes don't trigger false positives.
"""

import hashlib
import json
import os
import re
import sys
import urllib.request
import urllib.error
from datetime import datetime, timezone
from pathlib import Path

CACHE_DIR = Path(".cache")
SLACK_WEBHOOK_URL = os.environ.get("SLACK_WEBHOOK_URL", "")

# Set to True to print Slack messages without actually sending them.
SLACK_DRY_RUN = True


def fetch_content(url: str) -> str | None:
    """Fetch the text content of a URL. Returns None on failure."""
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (URL Monitor)"})
        with urllib.request.urlopen(req, timeout=15) as response:
            return response.read().decode("utf-8", errors="replace")
    except urllib.error.URLError as e:
        print(f"  [ERROR] Could not fetch {url}: {e}")
        return None


def hash_content(content: str) -> str:
    return hashlib.sha256(content.encode("utf-8")).hexdigest()


CACHE_NAMES: dict[str, str] = {
    "https://www.shopware.com/nl/changelog/": "shopware-6-changelog.json",
    "https://store.shopware.com/en/swag136939272659f/shopware-6-security-plugin.html": "shopware-6-security-plugin-changelog.json",
}


def cache_path(url: str) -> Path:
    """Return a stable file path for caching the hash of a URL."""
    name = CACHE_NAMES.get(url) or f"{hashlib.md5(url.encode()).hexdigest()}.json"
    return CACHE_DIR / name


def load_cached(url: str) -> dict | None:
    path = cache_path(url)
    if path.exists():
        return json.loads(path.read_text())
    return None


def save_cache(url: str, data: dict):
    CACHE_DIR.mkdir(exist_ok=True)
    path = cache_path(url)
    path.write_text(json.dumps({
        "url": url,
        "last_checked": datetime.now(timezone.utc).isoformat(),
        **data,
    }, indent=2))


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")


def send_slack_notification(url: str, message: str):
    if SLACK_DRY_RUN:
        print(f"  [DRY RUN] Would send to Slack:\n{message}")
        return

    if not SLACK_WEBHOOK_URL:
        print("  [WARN] SLACK_WEBHOOK_URL not set — skipping notification.")
        return

    payload = json.dumps({"text": message}).encode("utf-8")
    req = urllib.request.Request(
        SLACK_WEBHOOK_URL,
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            print(f"  [SLACK] Notification sent (status {resp.status}).")
    except urllib.error.URLError as e:
        print(f"  [ERROR] Failed to send Slack notification: {e}")


# ---------------------------------------------------------------------------
# Custom extractors
# ---------------------------------------------------------------------------

def extract_shopware_security_versions(content: str) -> dict[str, str]:
    """
    Return the latest security-tagged release per major Shopware branch.

    The changelog page lists releases newest-first. For each version entry that
    carries the 'release-title--security' badge, we record it as the latest
    security release for its major branch (e.g. "6.5", "6.6", "6.7") — taking
    the first (newest) match per branch.

    Returns a dict like {"6.5": "6.5.8.18", "6.6": "6.6.10.15", "6.7": "6.7.8.1"}.
    """
    version_positions = [
        (m.start(), m.group(1))
        for m in re.finditer(r'release-title--version[^>]*>(\d+\.\d+\.\d+(?:\.\d+)?)', content)
    ]
    latest_per_branch: dict[str, str] = {}
    for i, (pos, version) in enumerate(version_positions):
        next_pos = version_positions[i + 1][0] if i + 1 < len(version_positions) else pos + 5000
        segment = content[pos:min(pos + 3000, next_pos)]
        if "release-title--security" in segment:
            branch = ".".join(version.split(".")[:2])  # "6.5", "6.6", "6.7"
            if branch not in latest_per_branch:
                latest_per_branch[branch] = version
    return dict(sorted(latest_per_branch.items()))


def extract_shopware_security_plugin_versions(content: str) -> dict[str, str]:
    """
    Return the latest plugin version per Shopware branch from the store changelog.

    All entries are security releases. The plugin major version maps to a branch:
      2.x → 6.5,  3.x → 6.6,  4.x → 6.7

    Returns a dict like {"6.5": "2.0.19", "6.6": "3.0.14", "6.7": "4.0.9"}.
    """
    branch_map = {"2": "6.5", "3": "6.6", "4": "6.7"}
    latest_per_branch: dict[str, str] = {}
    for m in re.finditer(r'<h3[^>]*class="changelogs-header"[^>]*>\s*(\d+\.\d+\.\d+)\s', content, re.DOTALL):
        version = m.group(1)
        major = version.split(".")[0]
        branch = branch_map.get(major)
        if branch and branch not in latest_per_branch:
            latest_per_branch[branch] = version
    return dict(sorted(latest_per_branch.items()))


# Maps a URL to a custom extractor function. Extractors receive the raw HTML
# and return a JSON-serialisable value stored in the cache and compared across
# runs. Only the extracted signal triggers notifications.
EXTRACTORS: dict[str, callable] = {
    "https://www.shopware.com/nl/changelog/": extract_shopware_security_versions,
    "https://store.shopware.com/en/swag136939272659f/shopware-6-security-plugin.html": extract_shopware_security_plugin_versions,
}


# ---------------------------------------------------------------------------
# Check logic
# ---------------------------------------------------------------------------

def check_url(url: str):
    print(f"\nChecking: {url}")
    content = fetch_content(url)
    if content is None:
        return

    extractor = EXTRACTORS.get(url)
    if extractor:
        _check_url_with_extractor(url, content, extractor)
    else:
        _check_url_hash(url, content)


def _check_url_hash(url: str, content: str):
    current_hash = hash_content(content)
    cached = load_cached(url)

    if cached is None:
        print("  [NEW] No previous record found — saving baseline.")
        save_cache(url, {"hash": current_hash})
        send_slack_notification(url, f":eyes: *URL Monitor* is now tracking:\n<{url}|{url}>\n_First check completed at {_now()} — future changes will trigger a notification._")

    elif cached.get("hash") != current_hash:
        print(f"  [CHANGED] Hash mismatch detected!")
        print(f"    Old: {cached['hash'][:16]}…")
        print(f"    New: {current_hash[:16]}…")
        save_cache(url, {"hash": current_hash})
        send_slack_notification(url, f":bell: *Page changed!*\n<{url}|{url}>\n_Detected at {_now()}_")

    else:
        print(f"  [OK] No change detected (hash: {current_hash[:16]}…)")


def _check_url_with_extractor(url: str, content: str, extractor):
    current_value = extractor(content)
    cached = load_cached(url)

    if cached is None or "extracted" not in cached:
        print(f"  [NEW] No previous record found — saving baseline.")
        print(f"    Extracted: {current_value}")
        save_cache(url, {"extracted": current_value})
        send_slack_notification(url, f":eyes: *URL Monitor* is now tracking:\n<{url}|{url}>\n_Baseline set at {_now()}._")
        return

    previous_value = cached["extracted"]

    if isinstance(current_value, dict) and isinstance(previous_value, dict):
        changed = {
            branch: (previous_value.get(branch), new_ver)
            for branch, new_ver in current_value.items()
            if new_ver != previous_value.get(branch)
        }
        if changed:
            print(f"  [CHANGED] New security versions: {changed}")
            save_cache(url, {"extracted": current_value})
            lines = "\n".join(
                f"  • {branch}: `{new}`  (was `{old}`)"
                for branch, (old, new) in sorted(changed.items())
            )
            send_slack_notification(url, f":rotating_light: *Shopware Security Update(s) released!*\n<{url}|{url}>\n{lines}\n_Detected at {_now()}_")
        else:
            print(f"  [OK] No new security versions: {current_value}")
    else:
        if current_value != previous_value:
            print(f"  [CHANGED] Extracted value changed.")
            save_cache(url, {"extracted": current_value})
            send_slack_notification(url, f":bell: *Page changed!*\n<{url}|{url}>\n_Detected at {_now()}_")
        else:
            print(f"  [OK] No change detected.")


if __name__ == "__main__":
    urls = sys.argv[1:]
    if not urls:
        print("Usage: python3 monitor.py <url1> [url2] ...")
        sys.exit(1)

    for url in urls:
        check_url(url)

    print("\nDone.")
