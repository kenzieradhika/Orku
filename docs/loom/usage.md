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
