# Loom usage

```php
use Orku\Loom\LoomRenderer;

$views = new LoomRenderer(__DIR__.'/templates');
$html = $views->render('pages.home', ['title' => 'Welcome']);
```

`pages/home.ork`:

```html
<h1>{{ title }}</h1>
```

All interpolations are escaped for HTML text. Values are arrays/scalars/null; missing paths and non-scalar leaves throw `LoomException`. Never put untrusted markup in template source. Do not interpolate in attributes, comments, scripts, or styles.

## Layouts

`pages/home.ork`:

```text
{% extends "layouts/base" %}{% block content %}<h1>{{ title }}</h1>{% endblock %}
```

`layouts/base.ork`:

```html
<!doctype html><html><body>{% slot content %}{% endslot %}</body></html>
```

The child content is escaped and rendered before insertion into the one named parent content slot. Layout and child paths resolve under the configured template root. Cyclic inheritance, nested inheritance, missing/multiple slots, and unsupported directives throw `LoomException`.

## Partials

`{% include "partials.card" %}` inserts `partials/card.ork` in HTML text context and passes the current data:

```html
<article>{% include "partials.card" %}</article>
```

`partials/card.ork` uses the same `{{ value }}` syntax and automatic escaping. Include paths cannot leave the configured template root. Cycles, unsupported include syntax, or include directives in tags/attributes fail with `LoomException`. Partials do not accept their own argument list yet.

## Components

Invoke a component with explicit property bindings; the body becomes its optional content slot:

```html
{% component "card" title=page.title %}
    <p>{{ page.description }}</p>
{% endcomponent %}
```

Define it at `components/card.ork`:

```html
{% props title %}
<article><h2>{{ title }}</h2>{% slot content %}{% endslot %}</article>
```

Only declared properties are visible in the component. Values resolve from caller data paths and must be scalar or null. Interpolation and slot markup use Loom's normal escaping; raw HTML is unsupported. Component names cannot escape the template root. Nested calls are supported up to 32 component levels; cycles, invalid bindings, and unsupported contexts fail with `LoomException`. Each component has at most one `content` slot. Conditions, loops, caching, and raw-output syntax are not supported.
