<?php

namespace Omnischolar\OpenLibrary\Tests;

use Omnischolar\OpenLibrary\OpenLibrarySourceFactory;
use Omnischolar\Source\HttpSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/** Open Library's figures as its policy: one request a second, three when the User-Agent names a contact. */
final class PolicyTest extends TestCase
{
    public function testIdentifiedCallersGetThreeASecond(): void
    {
        $anonymous = (new OpenLibrarySourceFactory(new MockHttpClient()))->create();
        \assert($anonymous instanceof HttpSource);
        self::assertSame(1, $anonymous->guard()->remaining(HttpSource::REQUEST));

        $identified = (new OpenLibrarySourceFactory(new MockHttpClient()))->create(['mailto' => 'support@glitchr.io']);
        \assert($identified instanceof HttpSource);
        self::assertSame(3, $identified->guard()->remaining(HttpSource::REQUEST));
    }
}
