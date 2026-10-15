# omnischolar/openlibrary

Open Library for [glitchr/omnischolar](https://github.com/glitchr-studio/omnischolar): an
author's books, one record per edition - its ISBNs, publisher, date, cover - for the Merger to
group the editions of each book; a book by ISBN or Open Library id; a search; an author's page.
No key.

```php
$openlibrary = (new OpenLibrarySourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$editions = $openlibrary->works('Monica Neagoy');         // every spelling of the name, every edition
$books = (new Merger())->merge($editions);                 // Planting the Seeds of Algebra PreK-2: one book, two editions
$openlibrary->work('9781452279701');
```

```yaml
omnischolar:
    sources:
        openlibrary:
            factory: openlibrary
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent, as Open Library asks
                editions: true                 # false: one record per work
                throttle: 0.4                  # seconds between two calls
```

See [docs/](docs/index.md).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Internet Archive (Open Library). It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the Internet Archive's
terms ([archive.org/about/terms](https://archive.org/about/terms)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
