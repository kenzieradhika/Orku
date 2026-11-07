# Laravel SSR fixture dependencies

This isolated fixture has its own `composer.json` and committed `composer.lock`;
root Orku dependencies remain unchanged. Framework is exactly 12.71.0 and PHP is
8.4+. The runner checks `composer.lock` and refuses any other version. Rebuild
dependencies through Docker:

```sh
docker build -t orku-laravel-ssr:12.71.0 -f benchmarks/release/laravel-ssr/Dockerfile benchmarks/release/laravel-ssr
```

Run fixtures or comparison from the repository root:

```sh
docker run --rm -v "$PWD:/orku:ro" orku-laravel-ssr:12.71.0 --framework laravel --mode cases
python3 benchmarks/release/ssr_compare.py --laravel-root benchmarks/release/laravel-ssr \
  --renders 1000 --output /tmp/opencode/ssr-comparison.json
```

Laravel templates use Blade escaped `{{ }}` for dynamic values. Layout output
uses explicitly trusted fixture markup. Timings are rendering-only; Laravel app
bootstrap/cache state must be recorded separately. Do not time until case output
matches exactly. The local runner uses host PHP; use its equivalent versioned
Docker invocation to verify/measure Laravel when host PHP is below 8.4.
