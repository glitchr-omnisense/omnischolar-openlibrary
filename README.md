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

License: LGPL-3.0-or-later.
