<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateTopStatsCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('traces:generate:topstats')
            ->setDescription('Generate reviewers/issues/pull-requests leaderboards and enrich contributors_prs.json')
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
        // This command makes no GitHub API call: identity comes from the fetched
        // files. The --ghtoken option is kept only for pipeline compatibility
        // (Makefile / gh-pages.yml pass it); parent::execute() still sets up an
        // unused Github client.
        parent::execute($input, $output);

        // contributors_prs.json is produced by traces:generate:topcompanies, so this
        // command must run after it (and after the two fetch commands below).
        foreach ([
            self::FILE_PULLREQUESTS_ALL => 'traces:fetch:pullrequests:all',
            self::FILE_ISSUES => 'traces:fetch:issues',
            self::FILE_CONTRIBUTORS_PRS => 'traces:generate:topcompanies',
        ] as $required => $command) {
            if (!file_exists($required)) {
                $this->output->writeLn(sprintf('%s is missing. Please execute `php bin/console %s`', $required, $command));

                return 1;
            }
        }

        $this->fetchConfiguration($input->getOption('config'));

        /** @var array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, reviewers:array<array{login:string, name:string|null, avatar_url:string|null, html_url:string|null}>}> $pullRequests */
        $pullRequests = json_decode(file_get_contents(self::FILE_PULLREQUESTS_ALL) ?: '', true);
        /** @var array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, repository:string}> $issues */
        $issues = json_decode(file_get_contents(self::FILE_ISSUES) ?: '', true);
        /** @var array<string, mixed> $contributors */
        $contributors = json_decode(file_get_contents(self::FILE_CONTRIBUTORS_PRS) ?: '', true);

        $identities = $this->buildIdentities($pullRequests, $issues);
        $aggregate = $this->aggregate($pullRequests, $issues);

        $this->enrichContributors($contributors, $aggregate);
        file_put_contents(self::FILE_CONTRIBUTORS_PRS, json_encode($contributors, JSON_PRETTY_PRINT));

        file_put_contents(self::FILE_TOP_REVIEWERS, json_encode($this->buildRanking($aggregate['reviews'], $contributors, $identities), JSON_PRETTY_PRINT));
        file_put_contents(self::FILE_TOP_ISSUES, json_encode($this->buildRanking($aggregate['issuesOpened'], $contributors, $identities), JSON_PRETTY_PRINT));
        file_put_contents(self::FILE_TOP_PULLREQUESTS, json_encode($this->buildRanking($aggregate['pullRequestsOpened'], $contributors, $identities), JSON_PRETTY_PRINT));

        $this->output->writeLn(['', 'Top stats generated.']);

        return 0;
    }

    /**
     * @param array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, reviewers:array<array{login:string, name:string|null, avatar_url:string|null, html_url:string|null}>}> $pullRequests
     * @param array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, repository:string}> $issues
     *
     * @return array{reviews: array<string,int>, issuesOpened: array<string,int>, pullRequestsOpened: array<string,int>}
     */
    public function aggregate(array $pullRequests, array $issues): array
    {
        $reviews = [];
        $pullRequestsOpened = [];
        $issuesOpened = [];

        foreach ($pullRequests as $pullRequest) {
            $author = $pullRequest['login'] ?? null;
            if ($author !== null) {
                $pullRequestsOpened[$author] = ($pullRequestsOpened[$author] ?? 0) + 1;
            }
            // A review counts for its reviewer regardless of the PR author (even a
            // deleted/null author) — only self-reviews are excluded.
            foreach ($pullRequest['reviewers'] as $reviewer) {
                $reviewerLogin = $reviewer['login'];
                if ($reviewerLogin === $author) {
                    continue;
                }
                $reviews[$reviewerLogin] = ($reviews[$reviewerLogin] ?? 0) + 1;
            }
        }

        foreach ($issues as $issue) {
            $author = $issue['login'] ?? null;
            if ($author !== null) {
                $issuesOpened[$author] = ($issuesOpened[$author] ?? 0) + 1;
            }
        }

        return [
            'reviews' => $reviews,
            'issuesOpened' => $issuesOpened,
            'pullRequestsOpened' => $pullRequestsOpened,
        ];
    }

    /**
     * @param array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, reviewers:array<array{login:string, name:string|null, avatar_url:string|null, html_url:string|null}>}> $pullRequests
     * @param array<array{number:int, login:string|null, name:string|null, avatar_url:string|null, html_url:string|null, repository:string}> $issues
     *
     * @return array<string, array{name:string, avatar_url:string, html_url:string}>
     */
    public function buildIdentities(array $pullRequests, array $issues): array
    {
        $identities = [];
        $add = function (?string $login, ?string $name, ?string $avatarUrl, ?string $url) use (&$identities): void {
            if ($login === null || isset($identities[$login])) {
                return;
            }
            $identities[$login] = [
                'name' => $name ?? $login,
                'avatar_url' => $avatarUrl ?? '',
                'html_url' => $url ?? 'https://github.com/' . $login,
            ];
        };

        foreach ($pullRequests as $pullRequest) {
            $add($pullRequest['login'] ?? null, $pullRequest['name'] ?? null, $pullRequest['avatar_url'] ?? null, $pullRequest['html_url'] ?? null);
            foreach ($pullRequest['reviewers'] as $reviewer) {
                $add($reviewer['login'], $reviewer['name'] ?? null, $reviewer['avatar_url'] ?? null, $reviewer['html_url'] ?? null);
            }
        }
        foreach ($issues as $issue) {
            $add($issue['login'] ?? null, $issue['name'] ?? null, $issue['avatar_url'] ?? null, $issue['html_url'] ?? null);
        }

        return $identities;
    }

    /**
     * @param array<string, mixed> $contributors
     * @param array{reviews: array<string,int>, issuesOpened: array<string,int>, pullRequestsOpened: array<string,int>} $aggregate
     */
    private function enrichContributors(array &$contributors, array $aggregate): void
    {
        foreach ($contributors as $login => &$entry) {
            if (!is_array($entry) || !isset($entry['login'])) {
                continue;
            }
            $entry['reviews'] = $aggregate['reviews'][$login] ?? 0;
            $entry['issuesOpened'] = $aggregate['issuesOpened'][$login] ?? 0;
            $entry['pullRequestsOpened'] = $aggregate['pullRequestsOpened'][$login] ?? 0;
        }
        unset($entry);
    }

    /**
     * @param array<string,int> $counts
     * @param array<string, mixed> $contributors
     * @param array<string, array{name:string, avatar_url:string, html_url:string}> $identities
     *
     * @return array{updatedAt: string, items: array<array{rank:int, login:string, name:string, avatar_url:string, html_url:string, count:int}>}
     */
    private function buildRanking(array $counts, array $contributors, array $identities): array
    {
        $logins = array_keys($counts);
        usort($logins, function (string $a, string $b) use ($counts): int {
            return ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b);
        });

        $items = [];
        $rank = 1;
        foreach ($logins as $login) {
            if ($counts[$login] <= 0) {
                continue;
            }
            if (!$this->configKeepExcludedUsers && in_array($login, $this->configExclusions, true)) {
                continue;
            }
            $identity = $this->resolveIdentity($login, $contributors, $identities);
            $items[] = [
                'rank' => $rank,
                'login' => $login,
                'name' => $identity['name'],
                'avatar_url' => $identity['avatar_url'],
                'html_url' => $identity['html_url'],
                'count' => $counts[$login],
            ];
            ++$rank;
        }

        return ['updatedAt' => date('Y-m-d'), 'items' => $items];
    }

    /**
     * @param array<string, mixed> $contributors
     * @param array<string, array{name:string, avatar_url:string, html_url:string}> $identities
     *
     * @return array{name:string, avatar_url:string, html_url:string}
     */
    private function resolveIdentity(string $login, array $contributors, array $identities): array
    {
        if (isset($contributors[$login]) && is_array($contributors[$login])) {
            $record = $contributors[$login];

            return [
                'name' => (string) ($record['name'] ?? $login),
                'avatar_url' => (string) ($record['avatar_url'] ?? ''),
                'html_url' => (string) ($record['html_url'] ?? 'https://github.com/' . $login),
            ];
        }

        if (isset($identities[$login])) {
            return $identities[$login];
        }

        return [
            'name' => $login,
            'avatar_url' => '',
            'html_url' => 'https://github.com/' . $login,
        ];
    }
}
