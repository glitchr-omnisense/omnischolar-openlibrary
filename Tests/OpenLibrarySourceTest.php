<?php

namespace Omnischolar\OpenLibrary\Tests;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Merger;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\OpenLibrary\OpenLibrarySourceFactory;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Invented records in the shape of answers recorded from openlibrary.org on
 * 2026-10-04 (an invented author, books, ISBNs and Open Library ids): search-author.json
 * (search.json?author=Jeanne Exemple, newest first: nine works under three
 * author records), the editions of the two works that have two
 * (editions-OL9990014W.json, editions-OL9990016W.json), author.json
 * (authors/OL9990001A.json), search-authors.json (search/authors.json?q=Jeanne Exemple),
 * isbn-9782999990067.json and search-key-OL9990015W.json (that edition's work).
 */
final class OpenLibrarySourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>, array<string, list<string>>}> */
    private array $calls = [];

    private function source(array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $query, $options['normalized_headers'] ?? []];
            $fixture = match (true) {
                '/search.json' === $path && 'Jeanne Exemple' === ($query['author'] ?? null) => 'search-author.json',
                '/search.json' === $path && 'key:/works/OL9990015W' === ($query['q'] ?? null) => 'search-key-OL9990015W.json',
                '/search/authors.json' === $path => 'search-authors.json',
                '/authors/OL9990001A.json' === $path => 'author.json',
                '/isbn/9782999990067.json' === $path => 'isbn-9782999990067.json',
                (bool) preg_match('~^/works/(OL9990014W|OL9990016W)/editions\.json$~', $path, $m) => 'editions-'.$m[1].'.json',
                default => null,
            };

            return $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture)) : new MockResponse('{"error": "notfound"}', ['http_code' => 404]);
        });

        return (new OpenLibrarySourceFactory($http))->create($options + ['throttle' => 0, 'mailto' => 'support@glitchr.io']);
    }

    public function testEveryEditionOfEveryBookUnderTheName(): void
    {
        $page = $this->source()->works('Jeanne Exemple');

        self::assertSame(9, $page->total, 'nine works under three author records');
        self::assertCount(11, $page, 'two works have two editions each, read from their editions');
        self::assertTrue($page->isLast());
        self::assertSame(['/search.json', '/works/OL9990014W/editions.json', '/works/OL9990016W/editions.json'], array_map(static fn (array $c) => $c[0], $this->calls));
        self::assertSame('new', $this->calls[0][1]['sort']);
        self::assertStringContainsString('mailto:support@glitchr.io', $this->calls[0][2]['user-agent'][0], 'a named caller');

        foreach ($page as $work) {
            self::assertSame(WorkType::BOOK, $work->type);
            self::assertSame('openlibrary', $work->source);
        }
        $methode = $this->titled($page->works, 'Méthode Exemple CE1 - Fichier de l\'élève 1')[0];
        self::assertSame(['Jeanne Exemple', 'Paul Martin', 'Hélène Durand'], array_map(static fn ($c) => $c->name, $methode->authors), 'once each, out of capitals');
        self::assertSame(['9782999990012'], $methode->isbns());
        self::assertSame('https://covers.openlibrary.org/b/id/90000001-L.jpg', $methode->cover);

        $three = $this->titled($page->works, 'Sowing the Seeds of Geometry, 3-5');
        self::assertCount(2, $three);
        self::assertSame(['9782999990081'], $three[0]->isbns());
        self::assertSame('Explorations for the Upper Elementary Grades', $three[0]->subtitle);
        self::assertSame('en', $three[0]->language);
        self::assertSame('https://covers.openlibrary.org/b/id/90000003-L.jpg', $three[1]->cover);
    }

    public function testTheMergerGroupsTheEditionsOfSowingTheSeedsPreK2(): void
    {
        $books = (new Merger())->merge($this->source()->works('Jeanne Exemple'));

        $preK2 = array_values(array_filter($books, static fn (Work $w) => 'sowing the seeds of geometry prek 2' === Merger::fingerprint($w->title)));
        self::assertCount(1, $preK2, 'one book');
        $book = $preK2[0];
        self::assertCount(2, $book->editions, 'the 2016 edition and the 2012 one (filed twice in Open Library: one ISBN, merged)');
        self::assertSame([2016, 2012], array_map(static fn (Work $e) => $e->year, $book->editions), 'newest first');
        self::assertSame(['9782999990067'], $book->editions[0]->isbns());
        self::assertSame(['9782999990098'], $book->editions[1]->isbns());
        self::assertEqualsCanonicalizing(['9782999990067', '9782999990098'], $book->isbns(), 'the book answers to every edition\'s ISBN');
        self::assertSame(2016, $book->year);
        self::assertNotNull($book->cover, 'a cover from an edition that has one');

        self::assertCount(1, array_filter($books, static fn (Work $w) => 'Sharing Fractions' === $w->title));
        self::assertCount(1, array_filter($books, static fn (Work $w) => 'sowing the seeds of geometry 3 5' === Merger::fingerprint($w->title)));
        self::assertCount(2, array_filter($books, static fn (Work $w) => str_starts_with($w->title, 'Méthode Exemple')), 'two volumes, not two editions');
        self::assertCount(7, $books, 'Exemple 1 and 2, Growing, Sharing, PreK-2, 3-5, Geometry I');
    }

    public function testOneRecordPerWorkWhenAsked(): void
    {
        $page = $this->source(['editions' => false])->works('Jeanne Exemple');
        self::assertCount(9, $page);
        self::assertCount(1, $this->calls);
    }

    /** editions as a site's YAML or environment gives it, a string: "false" is one record per work. */
    public function testEditionsReadAsABooleanWhateverItsSpelling(): void
    {
        $this->source(['editions' => 'false'])->works('Jeanne Exemple');
        self::assertCount(1, $this->calls, 'no editions read');

        $this->expectException(\Omnischolar\Exception\InvalidConfigException::class);
        $this->expectExceptionMessage('editions');
        $this->source(['editions' => 'maybe']);
    }

    public function testABookByItsIsbn(): void
    {
        $book = $this->source()->work(Identifier::isbn('2999990065'));

        self::assertSame('Sowing the Seeds of Geometry, PreK-2', $book->title);
        self::assertSame('Explorations for the Early Grades', $book->subtitle);
        self::assertSame(2016, $book->year);
        self::assertSame('Éditions Exemple', $book->publisher);
        self::assertSame('Jeanne M. Exemple', $book->authors[0]->name);
        self::assertSame(['OL9990026M', 'OL9990015W'], $book->identifiers->values(Scheme::OPENLIBRARY));
        self::assertSame('/isbn/9782999990067.json', $this->calls[0][0], 'an ISBN-10 asked as its ISBN-13');
        self::assertNull($this->source()->work('9780306406157'));
    }

    public function testAnAuthorByName(): void
    {
        $author = $this->source()->author('Jeanne Exemple');

        self::assertSame('Jeanne Exemple', $author->name);
        self::assertSame('OL9990001A', $author->identifiers->value(Scheme::OPENLIBRARY));
        self::assertSame('https://openlibrary.org/authors/OL9990001A', $author->urls[0]);
    }

    public function testOnlyBooks(): void
    {
        self::assertCount(0, $this->source()->works('Jeanne Exemple', new Query(types: [WorkType::ARTICLE])));
        self::assertCount(0, $this->calls);
        $this->expectException(NotSupportedException::class);
        $this->source()->work(Identifier::doi('10.5555/9782999990104'));
    }

    /**
     * @param list<Work> $works
     *
     * @return list<Work>
     */
    private function titled(array $works, string $title): array
    {
        return array_values(array_filter($works, static fn (Work $w) => $title === $w->title));
    }
}
