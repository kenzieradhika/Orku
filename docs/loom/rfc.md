# Orku Loom RFC: initial renderer and layouts

Status: renderer, one-slot layouts, partial includes, components, and boolean conditionals. Existing `Orku\Web\ViewRenderer` and its PHP templates are unchanged.

## Grammar

```text
template     = (html-text | html-markup | interpolation)*
interpolation = "{{" whitespace? identifier ("." identifier)* whitespace? "}}"
identifier   = [A-Za-z_][A-Za-z0-9_]*
whitespace   = (space | tab | CR | LF)*
```

Markup is literal trusted project source. Interpolation is only accepted between tags, not in attributes, comments, or `script`/`style` raw-text elements. Values resolve through arrays only. Missing values, objects, unsupported expressions, malformed delimiters, and PHP opening tags fail with `LoomException`. Interpolated values use UTF-8 `htmlspecialchars` with quote escaping and invalid-byte substitution. There is no raw-output escape hatch.

## Partials

`{% include "partials.card" %}` renders `partials/card.ork` at the include position with the caller's data. Include directives are accepted only in HTML text context. The partial uses the same interpolation and HTML-state validation as its caller. Include paths use the standard dotted template-name grammar and are resolved under the configured root. Recursive and cyclic references fail with `LoomException`. Only this include directive is recognized; include arguments and other directives are unsupported.

## Layout inheritance

A child starts with `{% extends "layout" %}` and contains exactly one `{% block content %}...{% endblock %}`. A parent defines exactly one `{% slot content %}...{% endslot %}`. Child block content is rendered first using the same escaping rules, then inserted into that named parent slot. Template names use the same rooted `.ork` resolution as direct rendering. Cycles and nested inheritance fail. Other directives are rejected. Layout files must define the slot; fallback slot content is not used in this version.

## Resolution and trust

The constructor accepts one existing template directory. A name is a dotted sequence of identifier segments and resolves to the corresponding `.ork` file. Absolute paths, traversal, wrappers, and symlinks outside the configured root fail. Template files are trusted application code, not user uploads. Dynamic values remain untrusted and escaped. This renderer does not sandbox HTML or validate literal markup beyond interpolation context.

## Deferred

Additional slots, loops, compilation cache, and invalidation are deferred. Add only with a grammar proposal plus focused tests. No `eval()` is used.

## Components

Call a component with a literal dotted name and explicit `name=path.to.value` scalar bindings:

```html
{% component "card" title=page.title %}<p>{{ page.description }}</p>{% endcomponent %}
```

Component source resolves only under `components/` and begins with one property declaration. Declared properties are required; caller variables are not inherited:

```html
{% props title %}<article><h2>{{ title }}</h2>{% slot content %}{% endslot %}</article>
```

One optional `content` slot accepts caller template source, which Loom renders with caller data and the same escaping/context rules. Properties accept only scalar or null values and are interpolated with automatic escaping. No raw HTML binding exists. Nested components are allowed, with cyclic references rejected and nesting capped at 32 component levels. Unknown/duplicate/missing properties, malformed syntax, unsafe contexts, and path violations throw `LoomException`.

## Conditionals

Conditions accept one boolean data path. `else` is optional; blocks may nest to 32 levels:

```html
{% if user.isAdmin %}<b>Admin</b>{% else %}<b>User</b>{% endif %}
```

The path must exist and resolve to a PHP boolean; missing and non-boolean values throw `LoomException`. Comparisons, literals, calls, and arbitrary PHP expressions are unsupported. Interpolation in the selected branch still uses automatic HTML escaping. Directives remain restricted to HTML text, including inside components, layouts, and partials. No `eval()` is used.

## Compatibility

`Orku\Web\ViewRenderer` remains unchanged and continues to serve existing PHP templates. Loom is an additive standalone API. No shared integration, autoload, or package metadata changes are required because the existing PSR-4 `Orku\` mapping covers `src/Loom/`.
