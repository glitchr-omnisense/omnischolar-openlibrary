# omnischolar/openlibrary

## Installation

```sh
composer require omnischolar/openlibrary
```

```php
use Omnischolar\OpenLibrary\OpenLibrarySourceFactory;

$openlibrary = (new OpenLibrarySourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

Open Library asks its callers to name themselves in the User-Agent (with a contact) and allows
about three calls a second to a named caller: the default `throttle` leaves 0.4 s between two.

## Calls

| Method | Reads | Open Library |
|---|---|---|
| `works()` | an author's id (`OL...A`: only the books filed under it) or a name (every author record of that name: "Monica Neagoy", "MONICA NEAGOY", "Monica M. Neagoy") | `search.json?author_key:` / `?author=`, then `works/{id}/editions.json` for each work with several editions |
| `work()` | ISBN (that edition), `OL...M` (an edition), `OL...W` (a work) | `isbn/{isbn}.json`, `books/{id}.json`, `search.json?q=key:` (for the authors' names) |
| `search()` | `text` (`q`), `title`, `author` | `search.json` |
| `author()` | `OL...A`, or the first author record a name finds | `authors/{id}.json`, `search/authors.json` |

`Query`: `from`/`to` (`first_publish_year`), `types` (books only: any other type gives an empty
page), `sort` (`newest`, `oldest`, `relevance`), `limit` (100 at most), `cursor` (a page number).
`domains` is refused.

## What a book holds

One record per edition: title and subtitle, type `book`, authors (each name once; ALL CAPS put in
title case), year and date (Open Library's free-text date read as ISO 8601: "Feb 18, 2020" is
2020-02-18), publisher, edition statement, description, language (ISO 639-1 where there is one),
the edition's page, its cover (`covers.openlibrary.org/b/id/{id}-L.jpg`); identifiers: the
edition's and the work's Open Library ids, every ISBN (as ISBN-13). A work with a single edition
is read from the search alone.

With `editions: false`, one record per work: its first publication year, every ISBN of every
edition, its cover.

## What Open Library does not give

Books only: no articles, no citations, no metrics, rarely an abstract. Its records are entered by
hand: the same book is sometimes filed twice (two works with one ISBN: the Merger joins them),
an author is sometimes split into several records (searching by name covers them), some text is
badly encoded. A work's editions cost one call each.

## Tests

Recorded on 2026-10-04: `search.json?author=Monica Neagoy` (nine works, three author records),
the editions of the two works that have two, her author page, an edition by ISBN and its work.
The tests check that the Merger groups the editions of *Planting the Seeds of Algebra, PreK-2*
(2012, filed twice, and 2016) into one book, and keeps the two volumes of the *Méthode de
Singapour CE1* apart.
