# omnischolar/openlibrary

Open Library, the Internet Archive's catalogue of books, for
[omnisense/omnischolar](https://github.com/glitchr-omnisense/omnischolar): an author's books - by
their Open Library id or their name, every spelling of it - one record per edition, each with its
ISBNs, publisher, date and cover (the Merger groups the editions of a book); a book by ISBN or Open
Library id; a search; an author's page. The source calls Open Library's JSON API, without a key:

| Operation | Request |
|---|---|
| `works($author)` | `GET https://openlibrary.org/search.json?q=author_key:<OL...A>` or `?author=<name>`, `&fields=&sort=&limit=&page=`; then `works/<OL...W>/editions.json?limit=50` for each work with several editions |
| `work($id)` | `GET .../isbn/<isbn>.json` or `books/<OL...M>.json`, then `search.json?q=key:<work>` for the authors' names; `OL...W`: `search.json?q=key:/works/<id>` |
| `search($query)` | `GET .../search.json?q=<text>&title=&author=` |
| `author($id)` | `GET .../authors/<OL...A>.json`; a name: `search/authors.json?q=<name>&limit=1` first |

Covers are served from `https://covers.openlibrary.org/b/id/<id>-L.jpg`
(`OpenLibrarySource::COVERS`). The base address is `OpenLibrarySource::BASE_URI`
(`https://openlibrary.org/`).

## Installation

```sh
composer require omnischolar/openlibrary
```

Requires PHP 8.2, `omnisense/omnischolar` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application with `OmnischolarBundle`, the factory is registered as soon as the package is
installed.

## Credentials

None. Open Library asks its callers to name themselves in the `User-Agent`, with a contact (the
`mailto`), and allows about three calls a second to a named caller: the default `throttle` leaves
0.4 s between two.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `editions` | bool | `true` | no | one record per edition (a work's editions read in one more call each); `false`: one record per work |
| `throttle` | float | `0.4` | no | seconds between two calls |
| `mailto` | string | `null` | no | added to the `User-Agent` |
| `user_agent` | string | `omnischolar/1.x (+https://github.com/glitchr-omnisense/omnischolar)` and the `mailto` | no | |
| `base_uri` | string | `https://openlibrary.org/` | no | |

`editions` is read with PHP's `(bool)`: give a real boolean.

## Capabilities

`capabilities()`: `author`, `works`, `work`, `search`, `covers`.

| Operation | Supported | Reads |
|---|---|---|
| `author()` | yes | `OL...A`, or the first author record a name finds |
| `works()` | yes | `OL...A` (only the books filed under it), or a name (every author record of that name: "Monica Neagoy", "MONICA NEAGOY", "Monica M. Neagoy") |
| `work()` | yes | ISBN (that edition), `OL...M` (an edition), `OL...W` (a work); an `OL...A` is refused |
| `search()` | yes | `text` (`q`), `title`, `author` |

## Behaviour

**Query.** `from`/`to` → `first_publish_year:[from TO to]`; `types` without `BOOK` gives an empty
page without a call; `domains` is refused; `openAccess` is not read. `sort`: `oldest` → `old`,
`relevance` → Open Library's own, any other → `new`. `limit` 1 to 100; the cursor is a page
number (from 1), the next one given while a full page came and the total is not reached;
`Page::$total` is `numFound`.

**Editions.** With `editions: true`, each work with more than one edition is read again
(`editions.json`, 50 at most) and gives one record per edition: a page of 25 works may cost as
many calls - hence the throttle. A work with a single edition is read from the search alone.

**A book.** Title and subtitle, type `book`, authors (each name once; ALL CAPS put in title case;
their Open Library ids), year and date (Open Library's free-text date read as ISO 8601: "Feb 18,
2020" is 2020-02-18, "March 2012" 2012-03, else the year found in it), publisher, edition
statement, description as abstract, language (MARC codes as ISO 639-1 where there is one), the
edition's page, its cover; identifiers: the edition's and the work's Open Library ids, every ISBN
(as ISBN-13). With `editions: false`: the work's first publication year, every ISBN of every
edition, its cover.

**An author.** Name, the Open Library id and the ORCID when the record has one, the personal and
alternate names, the Open Library page, the record's links and its Wikidata page, the biography.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnischolar\OpenLibrary\OpenLibrarySourceFactory;
use Symfony\Component\HttpClient\HttpClient;

$openlibrary = (new OpenLibrarySourceFactory(HttpClient::create()))->create(['mailto' => getenv('OMNISCHOLAR_MAILTO') ?: null]);

$w = $openlibrary->work('9781412996600');
echo $w->fullTitle(), "\n  ", $w->publisher, ', ', $w->date, ', ', implode(' ', $w->isbns()), "\n  cover: ", $w->cover, "\n";
```

```
Planting the seeds of algebra, PreK-2: explorations for the early grades
  Corwin Press, 2012, 9781412996600
  cover: 
```

(called live on 2026-10-26 at 14:18 UTC, from the core's harness image, without a `mailto`; that
edition has no cover on Open Library)

From the core's harness, the editions grouped by the Merger:
`docker compose run --rm omnischolar merge openlibrary:"Monica Neagoy" crossref:"Monica Neagoy"`.

In Symfony:

```yaml
omnischolar:
    sources:
        openlibrary: { factory: openlibrary, options: { mailto: '%env(OMNISCHOLAR_MAILTO)%' } }
```

## Errors

| Case | Result |
|---|---|
| an unknown book or author | `null` |
| an identifier it does not read, an `OL...A` given to `work()`; `domains` | `NotSupportedException` |
| another 4xx | `ProviderException` |
| a 429 | `RateLimitedException` |
| a 5xx, a timeout | `UnavailableException` |

## Verified, and not

Tested offline: `Tests/OpenLibrarySourceTest.php` (6 tests), on `MockHttpClient` with answers
recorded on 2026-10-04 - `search.json?author=Monica Neagoy` (nine works, three author records), the
editions of the two works that have two, her author page, an edition by ISBN and its work; the
Merger grouping the editions of *Planting the Seeds of Algebra, PreK-2* (2012, filed twice, and
2016) into one book, and keeping the two volumes of the *Méthode de Singapour CE1* apart. Called
live on 2026-10-04 and on 2026-10-26 (the example above).

Limits of the service: books only - no articles, no citations, no metrics, rarely an abstract.
Its records are entered by hand: the same book is sometimes filed twice (two works with one ISBN:
the Merger joins them), an author is sometimes split into several records (searching by name
covers them), some text is badly encoded.

## Responsibility

An independent, unofficial client, not affiliated with the Internet Archive (Open Library).
Whoever runs it complies with the Internet Archive's terms
([archive.org/about/terms](https://archive.org/about/terms)): see the README's Responsibility
section.
