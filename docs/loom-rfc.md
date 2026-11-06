# Loom initial compiler contract

Status: proposal, not implemented. Existing trusted PHP templates remain supported.

Initial `.ork` grammar: literal HTML plus `{{ identifier.path }}` references to
array values. Identifiers use ASCII letters/underscore followed by letters,
digits/underscore. No PHP expressions, function calls, object access, filters,
raw output, directives or nested evaluation in this first increment. Unknown
values and non-string/non-number leaves fail with template path and line number.

The first compiler accepts interpolation only in HTML text nodes. Reject it in
tags, attributes, comments, script/style/raw-text elements, and malformed markup.
Reject PHP opening tags. A lexer must track HTML states; regular-expression
replacement alone is not a compiler. Escape dynamic values with UTF-8
`htmlspecialchars`, quotes and invalid-byte substitution. Templates are trusted
application files; this is not a sandbox for user-supplied markup.

Resolve template names against one configured directory, reject traversal,
symlinks escaping that root, absolute paths and stream wrappers. Cache compiled
PHP in a private directory outside the public root, keyed by source hash and
compiler version. Compile completely before atomic publication; concurrent
writers must never expose partial PHP. No eval. Production precompilation must
fail rather than serve stale incompatible output.

Before implementation: adversarial fixtures cover escaping, UTF-8, malformed
delimiters, HTML states, traversal, cache invalidation and concurrent publication.
Layouts/components/slots require a later grammar extension and separate tests.
No `.ork` support is advertised until these gates pass.
