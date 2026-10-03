<?php

namespace Omnischolar\OpenLibrary;

use Omnischolar\Config;
use Omnischolar\Source\SourceFactory;
use Omnischolar\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Open Library: books, their editions and covers - no key.
 *
 *   options:
 *     mailto: 'support@glitchr.io'         # in the User-Agent, as Open Library asks of its callers
 *     editions: true                       # one record per edition (the Merger groups them); false: one per work
 *     throttle: 0.4                        # seconds between two calls (Open Library allows about 3 a second to a named caller)
 *     base_uri: 'https://openlibrary.org/'
 */
final class OpenLibrarySourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnischolar.factory_name' => 'openlibrary',
            'omnischolar.required_options' => [],
            'editions' => true,
            'throttle' => 0.4,
            'base_uri' => OpenLibrarySource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new OpenLibrarySource(
            $this->http ?? HttpClient::create(),
            (bool) $c->get('editions', true),
            (string) $c->get('base_uri', OpenLibrarySource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle', 0.4),
        );
    }
}
