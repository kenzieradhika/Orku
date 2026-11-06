# Local runtime diagnostics

Run `php bin/orku doctor` to print JSON containing PHP/SAPI, capability checks and
warnings. Exit 0 means PHP and JSON satisfy the runtime minimum, not that the
application is production-ready. Exit 1 means a required runtime check failed;
invalid command arguments also exit 1.

Session availability, debug mode, local HTTP cookie override, display_errors and
OPcache are advisory checks. No configuration is changed. No environment dump,
credentials, session path, document-root path or application objects are printed.

CLI values do not establish FPM/web-server settings, document-root safety, TLS or
session directory permissions. Verify those under the actual deployment identity.
Dependency autoloading occurs before diagnostics: an unsupported PHP version may
be rejected by Composer before Doctor can start. Install a supported PHP first.
