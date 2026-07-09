<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTopSecurityCommand extends AbstractCommand
{
    // Credits split into two families. `count` sums both — the leaderboard
    // ranks all credited security contributors together, and the front end
    // shows the per-family breakdown next to it so the two kinds of work
    // remain distinguishable.
    protected const RESEARCH_TYPES = ['reporter', 'finder', 'analyst', 'coordinator'];

    protected const REMEDIATION_TYPES = ['remediation_developer', 'remediation_reviewer', 'remediation_verifier'];

    protected function configure(): void
    {
        $this->setName('traces:generate:topsecurity')
            ->setDescription('Generate the security contributors leaderboard from fetched advisory credits')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                isset($_ENV['GH_TOKEN']) ? (string) $_ENV['GH_TOKEN'] : null
            )
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Identity comes from the fetched advisories (and contributors_prs.json
        // when available); no GitHub API call is made. --ghtoken is kept for
        // pipeline compatibility only.
        parent::execute($input, $output);

        if (!file_exists(self::FILE_SECURITY_ADVISORIES)) {
            $this->output->writeLn(self::FILE_SECURITY_ADVISORIES . ' is missing. Please execute `php bin/console traces:fetch:security-advisories`');

            return 1;
        }

        $this->fetchConfiguration($input->getOption('config'));

        /** @var array<array{ghsa_id:string|null, published_at:string|null, credits:array<array{login:string, avatar_url:string|null, html_url:string|null, type:string|null}>}> $advisories */
        $advisories = json_decode(file_get_contents(self::FILE_SECURITY_ADVISORIES) ?: '', true);
        /** @var array<string, mixed> $contributors */
        $contributors = file_exists(self::FILE_CONTRIBUTORS_PRS)
            ? json_decode(file_get_contents(self::FILE_CONTRIBUTORS_PRS) ?: '', true)
            : [];

        file_put_contents(self::FILE_TOP_SECURITY, json_encode($this->buildRanking($advisories, $contributors), JSON_PRETTY_PRINT));

        $this->output->writeLn(['', 'Top security generated.']);

        return 0;
    }

    /**
     * Increments a scalar counter AND its parallel year map (additive-strict pattern).
     *
     * @param array<string, int> $byYear
     */
    private static function bumpPair(int &$scalar, array &$byYear, string $year): void
    {
        $scalar++;
        if (!isset($byYear[$year])) {
            $byYear[$year] = 0;
            krsort($byYear);
        }
        $byYear[$year]++;
    }

    /**
     * Increments the scalar counter unconditionally, and bumps the year map only
     * if $rawDate parses to a valid timestamp. An empty/malformed date must NOT
     * fall back to "now" — that would silently misattribute old items to the
     * current year and corrupt the byYear breakdown. Skipping the year bump
     * (while still counting the scalar) keeps totals correct and honest.
     *
     * @param array<string, int> $byYear
     */
    private static function bumpPairFromDate(int &$scalar, array &$byYear, ?string $rawDate): void
    {
        $ts = ($rawDate !== null && $rawDate !== '') ? strtotime($rawDate) : false;
        if ($ts === false) {
            $scalar++;

            return;
        }
        self::bumpPair($scalar, $byYear, date('Y', $ts));
    }

    /**
     * @param array<array{ghsa_id:string|null, published_at:string|null, credits:array<array{login:string, avatar_url:string|null, html_url:string|null, type:string|null}>}> $advisories
     * @param array<string, mixed> $contributors
     *
     * @return array{updatedAt: string, items: array<array{rank:int, login:string, name:string, avatar_url:string, html_url:string, count:int, countByYear:array<string,int>, research:int, researchByYear:array<string,int>, remediation:int, remediationByYear:array<string,int>}>}
     */
    public function buildRanking(array $advisories, array $contributors): array
    {
        // Per login and per advisory, tag whether that person shows up in a
        // research role, a remediation role, or both. Then per login, count
        // the distinct advisories in each family; `count` totals both.
        $counts = [];
        $identities = [];
        foreach ($advisories as $advisory) {
            $publishedAt = $advisory['published_at'] ?? null;
            $perLogin = [];
            foreach ($advisory['credits'] as $credit) {
                $type = $credit['type'] ?? '';
                $isResearch = in_array($type, self::RESEARCH_TYPES, true);
                $isRemediation = in_array($type, self::REMEDIATION_TYPES, true);
                if (!$isResearch && !$isRemediation) {
                    continue;
                }
                $login = $credit['login'];
                $perLogin[$login]['research'] = ($perLogin[$login]['research'] ?? false) || $isResearch;
                $perLogin[$login]['remediation'] = ($perLogin[$login]['remediation'] ?? false) || $isRemediation;
                if (!isset($identities[$login])) {
                    $identities[$login] = [
                        'avatar_url' => $credit['avatar_url'] ?? '',
                        'html_url' => $credit['html_url'] ?? 'https://github.com/' . $login,
                    ];
                }
            }
            foreach ($perLogin as $login => $families) {
                if (!isset($counts[$login])) {
                    $counts[$login] = [
                        'count' => 0,
                        'countByYear' => [],
                        'research' => 0,
                        'researchByYear' => [],
                        'remediation' => 0,
                        'remediationByYear' => [],
                    ];
                }
                self::bumpPairFromDate($counts[$login]['count'], $counts[$login]['countByYear'], $publishedAt);
                if ($families['research']) {
                    self::bumpPairFromDate($counts[$login]['research'], $counts[$login]['researchByYear'], $publishedAt);
                }
                if ($families['remediation']) {
                    self::bumpPairFromDate($counts[$login]['remediation'], $counts[$login]['remediationByYear'], $publishedAt);
                }
            }
        }

        $logins = array_keys($counts);
        usort($logins, function (string $a, string $b) use ($counts): int {
            return ($counts[$b]['count'] <=> $counts[$a]['count']) ?: strcmp($a, $b);
        });

        $items = [];
        $rank = 1;
        foreach ($logins as $login) {
            if (!$this->configKeepExcludedUsers && in_array($login, $this->configExclusions, true)) {
                continue;
            }
            $contributor = isset($contributors[$login]) && is_array($contributors[$login]) ? $contributors[$login] : [];
            $items[] = [
                'rank' => $rank,
                'login' => $login,
                'name' => (string) ($contributor['name'] ?? $login),
                'avatar_url' => (string) ($contributor['avatar_url'] ?? $identities[$login]['avatar_url']),
                'html_url' => (string) ($contributor['html_url'] ?? $identities[$login]['html_url']),
                'count' => $counts[$login]['count'],
                'countByYear' => $counts[$login]['countByYear'],
                'research' => $counts[$login]['research'],
                'researchByYear' => $counts[$login]['researchByYear'],
                'remediation' => $counts[$login]['remediation'],
                'remediationByYear' => $counts[$login]['remediationByYear'],
            ];
            ++$rank;
        }

        return ['updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'items' => $items];
    }
}
