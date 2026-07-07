<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTopSecurityCommand extends AbstractCommand
{
    // Only research-side credits are ranked here. Remediation credits
    // (remediation_developer / reviewer / verifier) go to core developers who
    // are already surfaced in the PR and commit leaderboards; counting them
    // again would double-credit them and bury the security researchers this
    // board exists to highlight.
    protected const RESEARCH_TYPES = ['reporter', 'finder', 'analyst', 'coordinator'];

    protected function configure(): void
    {
        $this->setName('traces:generate:topsecurity')
            ->setDescription('Generate the security researchers leaderboard from fetched advisory credits')
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

        /** @var array<array{ghsa_id:string|null, credits:array<array{login:string, avatar_url:string|null, html_url:string|null, type:string|null}>}> $advisories */
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
     * @param array<array{ghsa_id:string|null, credits:array<array{login:string, avatar_url:string|null, html_url:string|null, type:string|null}>}> $advisories
     * @param array<string, mixed> $contributors
     *
     * @return array{updatedAt: string, items: array<array{rank:int, login:string, name:string, avatar_url:string, html_url:string, count:int}>}
     */
    public function buildRanking(array $advisories, array $contributors): array
    {
        // Count, per login, the distinct advisories where they hold a research
        // credit. Remediation-only contributors are intentionally left out (see
        // RESEARCH_TYPES).
        $counts = [];
        $identities = [];
        foreach ($advisories as $advisory) {
            $researchLogins = [];
            foreach ($advisory['credits'] as $credit) {
                if (!in_array($credit['type'] ?? '', self::RESEARCH_TYPES, true)) {
                    continue;
                }
                $login = $credit['login'];
                $researchLogins[$login] = true;
                if (!isset($identities[$login])) {
                    $identities[$login] = [
                        'avatar_url' => $credit['avatar_url'] ?? '',
                        'html_url' => $credit['html_url'] ?? 'https://github.com/' . $login,
                    ];
                }
            }
            foreach (array_keys($researchLogins) as $login) {
                $counts[$login] = ($counts[$login] ?? 0) + 1;
            }
        }

        $logins = array_keys($counts);
        usort($logins, function (string $a, string $b) use ($counts): int {
            return ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b);
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
                'count' => $counts[$login],
            ];
            ++$rank;
        }

        return ['updatedAt' => date('Y-m-d'), 'items' => $items];
    }
}
