# Orku Loom RFC: initial renderer and layouts

Status: initial implementation. Existing `Orku\Web\ViewRenderer` and its PHP templates are unchanged.

## Grammar

```text
template     = (html-text | html-markup | interpolation)*
interpolation = "{{" whitespace? identifier ("." identifier)* whitespace? "}}"
identifier   = [A-Za-z_][A-Za-z0-9_]*
whitespace   = (space | tab | CR | LF)*
```

Markup is literal trusted project source. Interpolation is only accepted between tags, not in attributes, comments, or `script`/`style` raw-text elements. Values resolve through arrays only. Missing values, objects, unsupported expressions, malformed delimiters, and PHP opening tags fail with `LoomException`. Interpolated values use UTF-8 `htmlspecialchars` with quote escaping and invalid-byte substitution. There is no raw-output escape hatch.

## Layout inheritance

A child starts with `{% extends "layout" %}` and contains exactly one `{% block content %}...{% endblock %}`. A parent defines exactly one `{% slot content %}...{% endslot %}`. Child block content is rendered first using the same escaping rules, then inserted into that named parent slot. Template names use the same rooted `.ork` resolution as direct rendering. Cycles and nested inheritance fail. Other directives are rejected. Layout files must define the slot; fallback slot content is not used in this version.

## Resolution and trust

The constructor accepts one existing template directory. A name is a dotted sequence of identifier segments and resolves to the corresponding `.ork` file. Absolute paths, traversal, wrappers, and symlinks outside the configured root fail. Template files are trusted application code, not user uploads. Dynamic values remain untrusted and escaped. This renderer does not sandbox HTML or validate literal markup beyond interpolation context.

## Deferred

Partials, components, additional slots, conditions, loops, compilation cache, and invalidation are deferred. Add only with a grammar proposal plus focused tests. No `eval()` is used.

## Compatibility

`Orku\Web\ViewRenderer` remains unchanged and continues to serve existing PHP templates. Loom is an additive standalone API. No shared integration, autoload, or package metadata changes are required because the existing PSR-4 `Orku\` mapping covers `src/Loom/`.
