<?php

namespace Omnischolar\OpenLibrary;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Author;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open Library (openlibrary.org, no key): books. An author's books - by
 * their Open Library id or their name, every spelling of it - one record
 * per edition, each with its ISBNs, publisher, date and cover: the Merger
 * groups the editions of a book. A book by ISBN or Open Library id; a
 * search; an author's page (bio, links, ORCID when given).
 *
 * Open Library asks callers to name themselves: the User-Agent carries the
 * mailto. Its works list its editions in a second call (editions.json):
 * a page of 25 works may cost as many calls - hence the throttle.
 */
final class OpenLibrarySource extends HttpSource
{
    public const BASE_URI = 'https://openlibrary.org/';
    public const COVERS = 'https://covers.openlibrary.org/b/id/%d-L.jpg';

    private const FIELDS = 'key,title,subtitle,author_name,author_key,first_publish_year,edition_count,edition_key,isbn,publisher,publish_year,cover_i,language,number_of_pages_median';

    /** MARC language codes, as ISO 639-1 where there is one. */
    private const LANGUAGES = ['eng' => 'en', 'fre' => 'fr', 'fra' => 'fr', 'ger' => 'de', 'deu' => 'de', 'spa' => 'es', 'ita' => 'it', 'jpn' => 'ja', 'por' => 'pt', 'dut' => 'nl', 'rum' => 'ro', 'ron' => 'ro', 'chi' => 'zh', 'rus' => 'ru', 'pol' => 'pl', 'ara' => 'ar', 'kor' => 'ko', 'swe' => 'sv', 'dan' => 'da', 'nor' => 'no', 'fin' => 'fi', 'gre' => 'el', 'heb' => 'he', 'tur' => 'tr', 'cze' => 'cs', 'hun' => 'hu', 'lat' => 'la'];

    /** The most editions read for one work. */
    private const EDITIONS = 50;

    public function __construct(
        HttpClientInterface $http,
        private readonly bool $editions = true,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'openlibrary';
    }

    public function capabilities(): array
    {
        return [Capability::AUTHOR, Capability::WORKS, Capability::WORK, Capability::SEARCH, Capability::COVERS];
    }

    /** An author by their Open Library id (OL7092953A), or the first one a name finds. */
    public function author(Identifier|string $author): ?Author
    {
        $id = self::identify($author, Scheme::OPENLIBRARY);
        if (null === $id) {
            if ($author instanceof Identifier) {
                throw NotSupportedException::identifier('openlibrary', (string) $author);
            }
            $key = $this->getJson('search/authors.json', ['q' => $author, 'limit' => 1])['docs'][0]['key'] ?? null;
            if (null === $key) {
                return null;
            }
            $id = Identifier::openlibrary((string) $key);
        }
        $a = $this->getJson('authors/'.$id->value.'.json');
        if (null === $a || !isset($a['name'])) {
            return null;
        }
        $bio = \is_array($a['bio'] ?? null) ? ($a['bio']['value'] ?? null) : ($a['bio'] ?? null);
        $remote = $a['remote_ids'] ?? [];

        return new Author(
            name: (string) $a['name'],
            identifiers: Identifiers::of($id, Identifier::tryOf(Scheme::ORCID, $remote['orcid'] ?? null)),
            alternativeNames: array_values(array_unique(array_diff(array_filter([$a['personal_name'] ?? null, ...($a['alternate_names'] ?? [])]), [$a['name']]))),
            urls: array_values(array_filter([
                'https://openlibrary.org/authors/'.$id->value,
                ...array_map(static fn (array $l) => $l['url'] ?? null, $a['links'] ?? []),
                isset($remote['wikidata']) ? 'https://www.wikidata.org/wiki/'.$remote['wikidata'] : null,
            ])),
            biography: self::text($bio),
            source: 'openlibrary',
        );
    }

    /**
     * An author's books, one record per edition: by their Open Library id
     * (only the books filed under it) or by their name (every author
     * record of that name: "Monica Neagoy", "MONICA NEAGOY",
     * "Monica M. Neagoy"...). The cursor is a page number.
     */
    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        $query ??= new Query();
        $id = self::identify($author, Scheme::OPENLIBRARY);
        if ($author instanceof Identifier && null === $id) {
            throw NotSupportedException::identifier('openlibrary', (string) $author, 'list the books of');
        }

        return null !== $id ? $this->list($query, ['author_key:'.$id->value]) : $this->list($query, [], ['author' => $author]);
    }

    /** A book by its ISBN (that edition), its edition id (OL...M) or its work id (OL...W). */
    public function work(Identifier|string $id): ?Work
    {
        $identifier = self::identify($id, Scheme::ISBN, Scheme::OPENLIBRARY) ?? throw NotSupportedException::identifier('openlibrary', (string) $id);
        if (Scheme::OPENLIBRARY === $identifier->scheme && str_ends_with($identifier->value, 'W')) {
            $doc = $this->getJson('search.json', ['q' => 'key:/works/'.$identifier->value, 'fields' => self::FIELDS, 'limit' => 1])['docs'][0] ?? null;

            return null === $doc ? null : $this->fromDoc($doc);
        }
        if (Scheme::OPENLIBRARY === $identifier->scheme && !str_ends_with($identifier->value, 'M')) {
            throw NotSupportedException::identifier('openlibrary', (string) $id, 'look up an author as a book:');
        }
        $edition = $this->getJson(Scheme::ISBN === $identifier->scheme ? 'isbn/'.$identifier->value.'.json' : 'books/'.$identifier->value.'.json');
        if (null === $edition || !isset($edition['title'])) {
            return null;
        }
        $workKey = $edition['works'][0]['key'] ?? null;
        $doc = null !== $workKey ? ($this->getJson('search.json', ['q' => 'key:'.$workKey, 'fields' => self::FIELDS, 'limit' => 1])['docs'][0] ?? []) : [];

        return $this->fromEdition($edition, $doc);
    }

    public function search(Query $query): Page
    {
        $parameters = array_filter(['title' => $query->title, 'author' => $query->author]);

        return $this->list($query, null !== $query->text && '' !== trim($query->text) ? [$query->text] : [], $parameters);
    }

    /**
     * @param list<string>          $q
     * @param array<string, string> $parameters
     */
    private function list(Query $query, array $q, array $parameters = []): Page
    {
        if ($query->domains) {
            throw NotSupportedException::operation('openlibrary', 'filter by domain');
        }
        if ($query->types && !\in_array(WorkType::BOOK, $query->types, true)) {
            return new Page([], null, 0);
        }
        if (null !== $query->from || null !== $query->to) {
            $q[] = \sprintf('first_publish_year:[%s TO %s]', $query->from ?? '*', $query->to ?? '*');
        }
        $page = max(1, (int) ($query->cursor ?? 1));
        $limit = max(1, min(100, $query->limit));
        $data = $this->getJson('search.json', $parameters + [
            'q' => $q ? implode(' AND ', $q) : null,
            'fields' => self::FIELDS,
            'sort' => match ($query->sort) {
                Query::OLDEST => 'old',
                Query::RELEVANCE => null,
                default => 'new',
            },
            'limit' => $limit,
            'page' => $page,
        ]) ?? [];

        $works = [];
        foreach ($data['docs'] ?? [] as $doc) {
            if ($this->editions && ($doc['edition_count'] ?? 1) > 1) {
                $entries = $this->getJson('works/'.basename((string) $doc['key']).'/editions.json', ['limit' => self::EDITIONS])['entries'] ?? [];
                foreach ($entries as $edition) {
                    $works[] = $this->fromEdition($edition, $doc);
                }
                if ($entries) {
                    continue;
                }
            }
            $works[] = $this->fromDoc($doc);
        }
        $total = $data['numFound'] ?? $data['num_found'] ?? null;

        return new Page($works, \count($data['docs'] ?? []) === $limit && (null === $total || $page * $limit < $total) ? (string) ($page + 1) : null, $total);
    }

    /**
     * A work as the search sees it: its first edition, or all of them when
     * there is only one.
     *
     * @param array<string, mixed> $doc
     */
    private function fromDoc(array $doc): Work
    {
        $single = 1 === ($doc['edition_count'] ?? 0);
        $identifiers = [Identifier::tryOf(Scheme::OPENLIBRARY, $doc['key'] ?? null)];
        if ($single) {
            $identifiers[] = Identifier::tryOf(Scheme::OPENLIBRARY, $doc['edition_key'][0] ?? null);
        }
        foreach ($doc['isbn'] ?? [] as $isbn) {
            $identifiers[] = Identifier::tryOf(Scheme::ISBN, (string) $isbn);
        }
        $year = $doc['first_publish_year'] ?? null;

        return new Work(
            title: self::text($doc['title'] ?? null) ?? '',
            type: WorkType::BOOK,
            authors: self::authors($doc),
            year: null !== $year ? (int) $year : null,
            date: null !== $year ? (string) $year : null,
            subtitle: self::text($doc['subtitle'] ?? null),
            publisher: $doc['publisher'][0] ?? null,
            language: self::language($doc['language'][0] ?? null),
            url: 'https://openlibrary.org'.$doc['key'],
            cover: isset($doc['cover_i']) ? \sprintf(self::COVERS, $doc['cover_i']) : null,
            identifiers: new Identifiers($identifiers),
            source: 'openlibrary',
        );
    }

    /**
     * @param array<string, mixed> $edition
     * @param array<string, mixed> $doc     its work, as the search sees it (for the authors' names)
     */
    private function fromEdition(array $edition, array $doc): Work
    {
        $identifiers = [Identifier::tryOf(Scheme::OPENLIBRARY, $edition['key'] ?? null), Identifier::tryOf(Scheme::OPENLIBRARY, $edition['works'][0]['key'] ?? $doc['key'] ?? null)];
        foreach ([...($edition['isbn_13'] ?? []), ...($edition['isbn_10'] ?? [])] as $isbn) {
            $identifiers[] = Identifier::tryOf(Scheme::ISBN, (string) $isbn);
        }
        $date = self::publishDate($edition['publish_date'] ?? null);
        $cover = array_values(array_filter($edition['covers'] ?? [], static fn ($c) => \is_int($c) && $c > 0))[0] ?? null;

        return new Work(
            title: self::text($edition['title'] ?? null) ?? '',
            type: WorkType::BOOK,
            authors: self::authors($doc),
            year: null !== $date ? (int) substr($date, 0, 4) : null,
            date: $date,
            subtitle: self::text($edition['subtitle'] ?? null),
            publisher: $edition['publishers'][0] ?? null,
            edition: $edition['edition_name'] ?? null,
            abstract: self::text(\is_array($edition['description'] ?? null) ? ($edition['description']['value'] ?? null) : ($edition['description'] ?? null)),
            language: self::language(isset($edition['languages'][0]['key']) ? basename((string) $edition['languages'][0]['key']) : null),
            url: 'https://openlibrary.org'.$edition['key'],
            cover: null !== $cover ? \sprintf(self::COVERS, $cover) : null,
            identifiers: new Identifiers($identifiers),
            source: 'openlibrary',
        );
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return list<Contributor> each name once, ALL CAPS put in title case
     */
    private static function authors(array $doc): array
    {
        $authors = [];
        foreach ($doc['author_name'] ?? [] as $i => $name) {
            $name = trim((string) $name);
            if (\function_exists('mb_strtoupper') && $name === mb_strtoupper($name)) {
                $name = mb_convert_case(mb_strtolower($name), \MB_CASE_TITLE);
            }
            $authors[$name] ??= Contributor::fromName($name, Identifiers::of(Identifier::tryOf(Scheme::OPENLIBRARY, $doc['author_key'][$i] ?? null)));
        }

        return array_values($authors);
    }

    /** "Feb 18, 2020", "March 2012", "2016" as ISO 8601. */
    private static function publishDate(?string $date): ?string
    {
        if (null === $date || '' === trim($date)) {
            return null;
        }
        $date = trim($date);
        foreach (['Y-m-d' => 'Y-m-d', 'M j, Y' => 'Y-m-d', 'F j, Y' => 'Y-m-d', 'j M Y' => 'Y-m-d', 'j F Y' => 'Y-m-d', 'F Y' => 'Y-m', 'M Y' => 'Y-m', 'Y-m' => 'Y-m'] as $format => $out) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $date);
            if (false !== $parsed && $parsed->format($format) === $date) {
                return $parsed->format($out);
            }
        }

        return preg_match('~\b(1[5-9]\d\d|20\d\d)\b~', $date, $m) ? $m[1] : null;
    }

    private static function language(?string $code): ?string
    {
        return null === $code ? null : (self::LANGUAGES[$code] ?? $code);
    }
}
