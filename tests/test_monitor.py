import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent.parent))

from monitor import extract_shopware_security_versions

FIXTURE = Path(__file__).parent / "fixtures" / "shopware_changelog.html"


def test_latest_security_version_per_branch():
    content = FIXTURE.read_text(errors="replace")
    result = extract_shopware_security_versions(content)
    assert result == {
        "6.5": "6.5.8.18",
        "6.6": "6.6.10.15",
        "6.7": "6.7.8.1",
    }


def test_only_tracks_known_major_branches():
    content = FIXTURE.read_text(errors="replace")
    result = extract_shopware_security_versions(content)
    for branch in result:
        assert branch.startswith("6."), f"Unexpected branch: {branch}"


def test_no_false_positives_on_non_security_releases():
    non_security_versions = {"6.7.9.0", "6.6.10.16", "6.7.8.2", "6.7.8.0"}
    content = FIXTURE.read_text(errors="replace")
    result = extract_shopware_security_versions(content)
    for version in non_security_versions:
        assert version not in result.values(), f"{version} should not be flagged as a security release"
