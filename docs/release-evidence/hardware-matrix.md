# Runtime and hardware evidence

Only native execution is hardware evidence. Emulation is labeled separately;
missing capabilities or unrun tests remain explicit. No timing threshold is
used as a support claim.

| Environment | Evidence | Status |
| --- | --- | --- |
| Linux x86_64, PHP 8.3.6, local host | Metadata captured in `/tmp/opencode/platform-evidence.json`; PDO SQLite, session and `pcntl` modules present. | Unsupported PHP version; project requires PHP 8.4+. No HTTP/session integration checks run. |
| Linux x86_64, PHP 8.4 | CI Composer/PHPUnit and deployment smoke jobs. | CI validated; not a separate hardware profile. |
| Linux x86_64, PHP 8.5 | CI Composer/PHPUnit and deployment smoke jobs. | CI validated; not a separate hardware profile. |
| ARM64, PHP 8.4 | Not available. | Unverified; no emulated evidence substituted. |
| 1 CPU / 512 MiB native profile | Not available. | Unverified. |

Run `python3 tests/thinkcore/platform_check.py --output <path>` on each volunteer
native host. The report is metadata only. CLI dispatch, HTTP routing, isolated
session behavior, PDO SQLite transactions, ThinkCore cgroup observation, and
image digest require separate checks before changing a row to validated.
