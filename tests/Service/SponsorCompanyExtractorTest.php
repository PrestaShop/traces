<?php

namespace PrestaShop\Traces\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShop\Traces\Service\SponsorCompanyExtractor;

class SponsorCompanyExtractorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideBodies(): iterable
    {
        yield 'row without closing pipe' => ["| Sponsor company   | @PrestaShopCorp\n", '@prestashopcorp'];
        yield 'row closed by a pipe' => ["| Sponsor company   | Codencode snc |\n", 'codencode snc'];
        yield 'row closed by a pipe after padding' => ["| Sponsor company | waynet                    |\n", 'waynet'];
        yield 'empty cell' => ["| Sponsor company |  |\n", ''];
        yield 'empty cell, no space before the pipe' => ["| Sponsor company   ||\n", ''];
        yield 'no space after the pipe' => ["| Sponsor company   |PrestaShop SA\n", 'prestashop sa'];
        yield 'markdown link' => ["| Sponsor company | [TRENDO s.r.o.](https://www.trendo.cz) |\n", '[trendo s.r.o.](https://www.trendo.cz)'];
        yield 'windows line endings' => ["| Sponsor company   | Kiwik\r\n| UI Tests | ok\r\n", 'kiwik'];
        yield 'row among the other rows of the template' => [
            "| Questions | Answers\n| --- | ---\n| Type? | bug fix\n| Sponsor company   | Soledis |\n| How to test? | see below\n",
            'soledis',
        ];
        yield 'no sponsor row' => ["| Questions | Answers\n| Type? | bug fix\n", ''];
        yield 'empty body' => ['', ''];
    }

    #[DataProvider('provideBodies')]
    public function testExtract(string $body, string $expected): void
    {
        self::assertSame($expected, (new SponsorCompanyExtractor())->extract($body));
    }
}
