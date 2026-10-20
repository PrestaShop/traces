<?php

namespace PrestaShop\Traces\Tests\Data;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShop\Traces\Command\GenerateTopCompaniesCommand;

class CompaniesJsonTest extends TestCase
{
    private const FILE = __DIR__ . '/../../var/data/companies.json';

    /**
     * @return list<array<string, mixed>>
     */
    private static function companies(): array
    {
        $data = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * Same comparison as GenerateTopCompaniesCommand::getCompanyByAlias()
     */
    private static function normalize(string $value): string
    {
        return trim(strtolower($value));
    }

    public function testEveryCompanyHasANameAndStringAliases(): void
    {
        foreach (self::companies() as $index => $company) {
            self::assertNotEmpty($company['name'] ?? null, sprintf('Company #%d has no name', $index));
            self::assertIsList($company['aliases'] ?? [], sprintf('Aliases of "%s" must be a list', $company['name']));
            foreach ($company['aliases'] ?? [] as $alias) {
                self::assertIsString($alias, sprintf('An alias of "%s" is not a string', $company['name']));
            }
        }
    }

    public function testNoAliasPointsToTwoCompanies(): void
    {
        $owners = [];
        foreach (self::companies() as $company) {
            foreach (array_unique(array_map(self::normalize(...), [$company['name'], ...($company['aliases'] ?? [])])) as $alias) {
                $owners[$alias][] = $company['name'];
            }
        }

        // The first match wins in getCompanyByAlias(), so a shared alias silently credits the wrong company
        $shared = array_filter($owners, static fn (array $names): bool => count($names) > 1);
        self::assertSame([], $shared);
    }

    /**
     * Sponsor rows of pull requests reported as "Sponsor Company Not Found" on 2026-10-19
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideSponsorRows(): iterable
    {
        yield 'PrestaShop#42600' => ['| Sponsor company   | Codencode snc |', 'Codencode'];
        yield 'hummingbird#932' => ['| Sponsor company   | Codencode  snc', 'Codencode'];
        yield 'PrestaShop#42407' => ['| Sponsor company   | waynet               |', 'Waynet'];
        yield 'PrestaShop#42350' => ['| Sponsor company   | TRENDO s.r.o. |', 'Trendo'];
        yield 'PrestaShop#41812' => ['| Sponsor company   | Vilkas Group Oy', 'Vilkas'];
        yield 'PrestaShop#43063' => ['| Sponsor company   | —', 'Open Source Community'];
        yield 'PrestaShop#40644' => ['| Sponsor company   | n/a |', 'Open Source Community'];
        yield 'PrestaShop#41899' => ['| Sponsor company   | Boring Investments', 'Boring Investments'];
        yield 'PrestaShop#41645' => ['| Sponsor company   | GreenMouse', 'GreenMouse'];
        yield 'PrestaShop#40945' => ['| Sponsor company   | Metin Eren', 'Metin Eren'];
        yield 'productcomments#227' => ['| Sponsor company   | Metin Eren.', 'Metin Eren'];
        yield 'docs#2118' => ['| Sponsor company   | difadi.com', 'Difadi'];
        yield 'PrestaShop#42284' => ['| Sponsor company   | https://shop.recart-sim.com/cs/', 'Recart Sim'];
        yield 'ps_apiresources#187' => ['| Sponsor company   | Enkore', 'Enkore'];
        yield 'ps_onepagecheckout#143' => ['| Sponsor company   | ZM40', 'ZM40'];
    }

    #[DataProvider('provideSponsorRows')]
    public function testReportedSponsorsResolveToACompany(string $row, string $expected): void
    {
        $sponsor = GenerateTopCompaniesCommand::extractSponsorCompany($row . "\n");

        $found = null;
        foreach (self::companies() as $company) {
            $names = array_map(self::normalize(...), [$company['name'], ...($company['aliases'] ?? [])]);
            if (in_array(self::normalize($sponsor), $names, true)) {
                $found = $company['name'];
                break;
            }
        }

        self::assertSame($expected, $found, sprintf('"%s" does not resolve to "%s"', $sponsor, $expected));
    }
}
