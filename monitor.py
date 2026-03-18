#!/usr/bin/env python3
"""
URL change monitor.
Fetches each URL, hashes its content, and compares against the last known hash.
Sends a Slack notification when a change is detected.
Hashes are stored as files in .cache/ so GitHub Actions can persist them via cache action.
"""

import hashlib
import json
import os
import sys
import urllib.request
import urllib.error
from datetime import datetime, timezone
from pathlib import Path

CACHE_DIR = Path(".cache")
SLACK_WEBHOOK_URL = os.environ.get("SLACK_WEBHOOK_URL", "")


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


def cache_path(url: str) -> Path:
    """Return a stable file path for caching the hash of a URL."""
    key = hashlib.md5(url.encode()).hexdigest()
    return CACHE_DIR / f"{key}.json"


def load_cached(url: str) -> dict | None:
    path = cache_path(url)
    if path.exists():
        return json.loads(path.read_text())
    return None


def save_cache(url: str, content_hash: str):
    CACHE_DIR.mkdir(exist_ok=True)
    path = cache_path(url)
    path.write_text(json.dumps({
        "url": url,
        "hash": content_hash,
        "last_checked": datetime.now(timezone.utc).isoformat(),
    }))


def send_slack_notification(url: str, previous_hash: str | None):
    if not SLACK_WEBHOOK_URL:
        print("  [WARN] SLACK_WEBHOOK_URL not set — skipping notification.")
        return

    if previous_hash is None:
        message = f":eyes: *URL Monitor* is now tracking:\n<{url}|{url}>\n_First check completed — future changes will trigger a notification._"
    else:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
        message = f":bell: *Page changed!*\n<{url}|{url}>\n_Detected at {now}_"

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


def check_url(url: str):
    print(f"\nChecking: {url}")
    content = fetch_content(url)
    if content is None:
        return  # Error already printed

    current_hash = hash_content(content)
    cached = load_cached(url)

    if cached is None:
        print("  [NEW] No previous record found — saving baseline.")
        save_cache(url, current_hash)
        send_slack_notification(url, previous_hash=None)

    elif cached["hash"] != current_hash:
        print(f"  [CHANGED] Hash mismatch detected!")
        print(f"    Old: {cached['hash'][:16]}…")
        print(f"    New: {current_hash[:16]}…")
        save_cache(url, current_hash)
        send_slack_notification(url, previous_hash=cached["hash"])

    else:
        print(f"  [OK] No change detected (hash: {current_hash[:16]}…)")


if __name__ == "__main__":
    urls = sys.argv[1:]
    if not urls:
        print("Usage: python3 monitor.py <url1> [url2] ...")
        sys.exit(1)

    for url in urls:
        check_url(url)

    print("\nDone.")
