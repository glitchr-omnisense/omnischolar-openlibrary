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
 * Fixtures recorded from openlibrary.org on 2026-10-04: search-author.json
 * (search.json?author=Monica Neagoy, newest first: nine works under three
 * author records), the editions of the two works that have two
 * (editions-OL27390023W.json, editions-OL21043513W.json), author.json
 * (authors/OL7092953A.json), search-authors.json (search/authors.json?q=Monica Neagoy),
 * isbn-9781452279701.json and search-key-OL28004019W.json (that edition's work).
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
                '/search.json' === $path && 'Monica Neagoy' === ($query['author'] ?? null) => 'search-author.json',
                '/search.json' === $path && 'key:/works/OL28004019W' === ($query['q'] ?? null) => 'search-key-OL28004019W.json',
                '/search/authors.json' === $path => 'search-authors.json',
                '/authors/OL7092953A.json' === $path => 'author.json',
                '/isbn/9781452279701.json' === $path => 'isbn-9781452279701.json',
                (bool) preg_match('~^/works/(OL27390023W|OL21043513W)/editions\.json$~', $path, $m) => 'editions-'.$m[1].'.json',
                default => null,
            };

            return $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture)) : new MockResponse('{"error": "notfound"}', ['http_code' => 404]);
        });

        return (new OpenLibrarySourceFactory($http))->create($options + ['throttle' => 0, 'mailto' => 'support@glitchr.io']);
    }

    public function testEveryEditionOfEveryBookUnderTheName(): void
    {
        $page = $this->source()->works('Monica Neagoy');

        self::assertSame(9, $page->total, 'nine works under three author records');
        self::assertCount(11, $page, 'two works have two editions each, read from their editions');
        self::assertTrue($page->isLast());
        self::assertSame(['/search.json', '/works/OL27390023W/editions.json', '/works/OL21043513W/editions.json'], array_map(static fn (array $c) => $c[0], $this->calls));
        self::assertSame('new', $this->calls[0][1]['sort']);
        self::assertStringContainsString('mailto:support@glitchr.io', $this->calls[0][2]['user-agent'][0], 'a named caller');

        foreach ($page as $work) {
            self::assertSame(WorkType::BOOK, $work->type);
            self::assertSame('openlibrary', $work->source);
        }
        $singapore = $this->titled($page->works, 'Méthode de Singapour CE1 - Fichier de l\'élève 1')[0];
        self::assertSame(['Monica Neagoy', 'Evelyne Touchard', 'Agnès Szikora'], array_map(static fn ($c) => $c->name, $singapore->authors), 'once each, out of capitals');
        self::assertSame(['9782369404125'], $singapore->isbns());
        self::assertSame('https://covers.openlibrary.org/b/id/11150256-L.jpg', $singapore->cover);

        $three = $this->titled($page->works, 'Planting the Seeds of Algebra, 3-5');
        self::assertCount(2, $three);
        self::assertSame(['9781483379753'], $three[0]->isbns());
        self::assertSame('Explorations for the Upper Elementary Grades', $three[0]->subtitle);
        self::assertSame('en', $three[0]->language);
        self::assertSame('https://covers.openlibrary.org/b/id/14388445-L.jpg', $three[1]->cover);
    }

    public function testTheMergerGroupsTheEditionsOfPlantingTheSeedsPreK2(): void
    {
        $books = (new Merger())->merge($this->source()->works('Monica Neagoy'));

        $preK2 = array_values(array_filter($books, static fn (Work $w) => 'planting the seeds of algebra prek 2' === Merger::fingerprint($w->title)));
        self::assertCount(1, $preK2, 'one book');
        $book = $preK2[0];
        self::assertCount(2, $book->editions, 'the 2016 edition and the 2012 one (filed twice in Open Library: one ISBN, merged)');
        self::assertSame([2016, 2012], array_map(static fn (Work $e) => $e->year, $book->editions), 'newest first');
        self::assertSame(['9781452279701'], $book->editions[0]->isbns());
        self::assertSame(['9781412996600'], $book->editions[1]->isbns());
        self::assertEqualsCanonicalizing(['9781452279701', '9781412996600'], $book->isbns(), 'the book answers to every edition\'s ISBN');
        self::assertSame(2016, $book->year);
        self::assertNotNull($book->cover, 'a cover from an edition that has one');

        self::assertCount(1, array_filter($books, static fn (Work $w) => 'Unpacking Fractions' === $w->title));
        self::assertCount(1, array_filter($books, static fn (Work $w) => 'planting the seeds of algebra 3 5' === Merger::fingerprint($w->title)));
        self::assertCount(2, array_filter($books, static fn (Work $w) => str_starts_with($w->title, 'Méthode de Singapour')), 'two volumes, not two editions');
        self::assertCount(7, $books, 'Singapour 1 and 2, Cultivating, Unpacking, PreK-2, 3-5, Algebra I');
    }

    public function testOneRecordPerWorkWhenAsked(): void
    {
        $page = $this->source(['editions' => false])->works('Monica Neagoy');
        self::assertCount(9, $page);
        self::assertCount(1, $this->calls);
    }

    /** editions as a site's YAML or environment gives it, a string: "false" is one record per work. */
    public function testEditionsReadAsABooleanWhateverItsSpelling(): void
    {
        $this->source(['editions' => 'false'])->works('Monica Neagoy');
        self::assertCount(1, $this->calls, 'no editions read');

        $this->expectException(\Omnischolar\Exception\InvalidConfigException::class);
        $this->expectExceptionMessage('editions');
        $this->source(['editions' => 'maybe']);
    }

    public function testABookByItsIsbn(): void
    {
        $book = $this->source()->work(Identifier::isbn('1452279705'));

        self::assertSame('Planting the Seeds of Algebra, PreK-2', $book->title);
        self::assertSame('Explorations for the Early Grades', $book->subtitle);
        self::assertSame(2016, $book->year);
        self::assertSame('Corwin Press', $book->publisher);
        self::assertSame('Monica M. Neagoy', $book->authors[0]->name);
        self::assertSame(['OL38294079M', 'OL28004019W'], $book->identifiers->values(Scheme::OPENLIBRARY));
        self::assertSame('/isbn/9781452279701.json', $this->calls[0][0], 'an ISBN-10 asked as its ISBN-13');
        self::assertNull($this->source()->work('9780306406157'));
    }

    public function testAnAuthorByName(): void
    {
        $author = $this->source()->author('Monica Neagoy');

        self::assertSame('Monica Neagoy', $author->name);
        self::assertSame('OL7092953A', $author->identifiers->value(Scheme::OPENLIBRARY));
        self::assertSame('https://openlibrary.org/authors/OL7092953A', $author->urls[0]);
    }

    public function testOnlyBooks(): void
    {
        self::assertCount(0, $this->source()->works('Monica Neagoy', new Query(types: [WorkType::ARTICLE])));
        self::assertCount(0, $this->calls);
        $this->expectException(NotSupportedException::class);
        $this->source()->work(Identifier::doi('10.4135/9781544308616'));
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
