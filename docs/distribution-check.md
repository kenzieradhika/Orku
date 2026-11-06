# Distribution check

Run `python3 tests/package_check.py` with Git, Composer, Python 3.12+ and Docker.
CI runs this on PHP 8.4. It creates a Git archive from HEAD, installs that package
as a copied Composer path dependency in an empty consumer, then runs real kernel
dispatch and the Composer CLI proxy with `php:8.4.26-apache`, network disabled.
No repository vendor directory or development dependencies are reused.

Before committing, stage the intended changes and run:

```sh
python3 tests/package_check.py --ref "$(git write-tree)"
```

The default checks HEAD, not unstaged changes. Archive export rules exclude
tests, benchmarks, CI configuration, development tool configuration and the
library lock file. Documentation and examples remain available. This checks a
local Git distribution, not a Packagist publication or GitHub release upload.

Composer installation ignores only its PHP version check so the runner can use
a host PHP older than 8.4. Execution uses real PHP 8.4 with Composer's generated
platform check enabled. All other dependency constraints remain enforced.
The CLI requires Composer runtime API 2.2+ for the consumer autoloader proxy;
direct `php bin/orku` remains supported from a development checkout.
The check exercises CLI help, not running packaged examples as applications.
