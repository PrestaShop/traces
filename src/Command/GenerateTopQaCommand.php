<?php

namespace PrestaShop\Traces\Command;

use DateTimeImmutable;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class GenerateTopQaCommand extends AbstractCommand
{
    /**
     * Labels that EXPLICITLY declare a community-led validation. When the
     * label carries the signal, we trust it — no company lookup needed.
     */
    private const COMMUNITY_LABELS = [
        'QA ✔️ by Community',
        'QA by Community ✓',
    ];

    /**
     * Labels that EXPLICITLY declare an internal (dev/team) validation.
     */
    private const DEV_LABELS = [
        'QA by Dev ✓',
        'QA by dev ✔️',
    ];

    /**
     * Every OTHER tracked label is neutral (`QA ✔️`, `QA ✓`, `QA :heavy_check_mark:`,
     * etc.). On modules these are used by both team members and community
     * reviewers indistinguishably, so we fall back to whether the actor was
     * a PrestaShop employee AT THE TIME of the event (via the employees map
     * in var/data/companies.json). Historic PRs stay attributed correctly
     * even if the employee has since left.
     */
    private const INTERNAL_CANONICAL_COMPANY = 'PrestaShop';

    protected function configure(): void
    {
        $this->setName('traces:generate:topqa')
            ->setDescription('Generate the QA contributors leaderboard from fetched QA label events')
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
        parent::execute($input, $output);

        if (!file_exists(self::FILE_QA_EVENTS)) {
            $this->output->writeLn(self::FILE_QA_EVENTS . ' is missing. Please execute `php bin/console traces:fetch:qaevents`');

            return 1;
        }

        $this->fetchConfiguration($input->getOption('config'));

        /** @var array{events?: array<array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}>} $payload */
        $payload = json_decode(file_get_contents(self::FILE_QA_EVENTS) ?: '', true) ?: [];
        $events = $payload['events'] ?? [];

        /** @var array<string, mixed> $contributors */
        $contributors = file_exists(self::FILE_CONTRIBUTORS_PRS)
            ? json_decode(file_get_contents(self::FILE_CONTRIBUTORS_PRS) ?: '', true)
            : [];

        $employeeStints = $this->loadInternalEmployeeStints();

        file_put_contents(self::FILE_TOP_QA, json_encode($this->buildRanking($events, $contributors, $employeeStints), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->output->writeLn(['', 'Top QA generated.']);

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $events raw event rows from gh_qa_events.json — keys are not statically guaranteed
     * @param array<string, mixed> $contributors
     * @param array<string, mixed> $employeeStints login -> raw employment periods at PrestaShop (isCommunityEvent validates the shape)
     *
     * @return array{updatedAt: string, items: array<array{rank:int, login:string, name:string, avatar_url:string, html_url:string, count:int, qa:int, qa_community:int}>}
     */
    public function buildRanking(array $events, array $contributors, array $employeeStints = []): array
    {
        $counts = [];
        foreach ($events as $ev) {
            $rawLogin = $ev['actor'] ?? null;
            $rawLabel = $ev['label'] ?? null;
            $rawCreated = $ev['createdAt'] ?? null;
            if (!is_string($rawLogin) || $rawLogin === '') {
                continue;
            }
            $login = $rawLogin;
            $label = is_string($rawLabel) ? $rawLabel : '';
            $createdAt = is_string($rawCreated) ? $rawCreated : null;
            if (!isset($counts[$login])) {
                $counts[$login] = [
                    'qa' => 0, 'qa_community' => 0,
                    'countByYear' => [], 'qaByYear' => [], 'qaCommunityByYear' => [],
                ];
            }
            $year = $this->extractYear($createdAt);
            $isCommunity = $this->isCommunityEvent($label, $login, $createdAt, $employeeStints);
            if ($isCommunity) {
                ++$counts[$login]['qa_community'];
            } else {
                ++$counts[$login]['qa'];
            }
            if ($year !== null) {
                $counts[$login]['countByYear'][$year] = ($counts[$login]['countByYear'][$year] ?? 0) + 1;
                if ($isCommunity) {
                    $counts[$login]['qaCommunityByYear'][$year] = ($counts[$login]['qaCommunityByYear'][$year] ?? 0) + 1;
                } else {
                    $counts[$login]['qaByYear'][$year] = ($counts[$login]['qaByYear'][$year] ?? 0) + 1;
                }
            }
        }

        $logins = array_keys($counts);
        usort($logins, static function (string $a, string $b) use ($counts): int {
            $totalA = $counts[$a]['qa'] + $counts[$a]['qa_community'];
            $totalB = $counts[$b]['qa'] + $counts[$b]['qa_community'];

            return ($totalB <=> $totalA)
                ?: ($counts[$b]['qa'] <=> $counts[$a]['qa'])
                ?: strcmp($a, $b);
        });

        $items = [];
        $rank = 1;
        foreach ($logins as $login) {
            if (!$this->configKeepExcludedUsers && in_array($login, $this->configExclusions, true)) {
                continue;
            }
            $contributor = isset($contributors[$login]) && is_array($contributors[$login]) ? $contributors[$login] : [];

            if (empty($contributor['avatar_url']) || empty($contributor['name'])) {
                $profile = $this->resolveProfile($login);
                $contributor = array_merge($profile, $contributor);
            }

            $total = $counts[$login]['qa'] + $counts[$login]['qa_community'];
            // Sort year maps descending — same convention as top_security's byYear
            // sub-maps, so consumers can display the most recent year first.
            $countByYear = $counts[$login]['countByYear'];
            $qaByYear = $counts[$login]['qaByYear'];
            $qaCommunityByYear = $counts[$login]['qaCommunityByYear'];
            krsort($countByYear);
            krsort($qaByYear);
            krsort($qaCommunityByYear);

            $items[] = [
                'rank' => $rank,
                'login' => $login,
                'name' => (string) ($contributor['name'] ?? $login),
                'avatar_url' => (string) ($contributor['avatar_url'] ?? ''),
                'html_url' => (string) ($contributor['html_url'] ?? 'https://github.com/' . $login),
                'count' => $total,
                'countByYear' => $countByYear,
                'qa' => $counts[$login]['qa'],
                'qaByYear' => $qaByYear,
                'qa_community' => $counts[$login]['qa_community'],
                'qaCommunityByYear' => $qaCommunityByYear,
            ];
            ++$rank;
        }

        return ['updatedAt' => (new DateTimeImmutable())->format(DATE_ATOM), 'items' => $items];
    }

    /**
     * Hybrid rule. When the label explicitly declares its origin (Community
     * or Dev), trust it. Otherwise (neutral label used on modules for both
     * kinds of validators), check whether the actor was a PrestaShop
     * employee at the time of the event (via var/data/companies.json).
     * Time-aware: historic events by someone who has since left the company
     * still count as internal.
     *
     * @param array<string, mixed> $employeeStints login -> raw stints array (validated inline)
     */
    private function isCommunityEvent(string $label, string $login, ?string $createdAt, array $employeeStints): bool
    {
        if (in_array($label, self::COMMUNITY_LABELS, true)) {
            return true;
        }
        if (in_array($label, self::DEV_LABELS, true)) {
            return false;
        }

        $stints = $employeeStints[$login] ?? null;
        if (!is_array($stints) || $stints === []) {
            // Not listed as an internal employee at any time — treat as
            // community. The default under-counts internal (unlisted team
            // members) rather than over-attributing to the team.
            return true;
        }

        $eventTs = ($createdAt !== null && $createdAt !== '') ? strtotime($createdAt) : false;
        // Undated event: if the actor was ever a PrestaShop employee, credit
        // it as internal — better than dropping the internal signal entirely.
        if ($eventTs === false) {
            return false;
        }

        foreach ($stints as $stint) {
            if (!is_array($stint)) {
                continue;
            }
            $rawStart = $stint['startDate'] ?? '';
            $rawEnd = $stint['endDate'] ?? '';
            if (!is_string($rawStart) || !is_string($rawEnd)) {
                continue;
            }
            $start = strtotime($rawStart);
            if ($start === false) {
                continue;
            }
            // Empty endDate = currently employed.
            $end = ($rawEnd === '') ? PHP_INT_MAX : strtotime($rawEnd);
            if ($end === false) {
                continue;
            }
            if ($eventTs >= $start && $eventTs <= $end) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load the employees map for the internal PrestaShop company from
     * var/data/companies.json. Returns login -> list of employment stints.
     *
     * @return array<string, mixed> login -> raw stints (shape validated in isCommunityEvent)
     */
    private function loadInternalEmployeeStints(): array
    {
        if (!file_exists(self::FILE_DATA_COMPANIES)) {
            return [];
        }
        $companies = json_decode(file_get_contents(self::FILE_DATA_COMPANIES) ?: '', true);
        if (!is_array($companies)) {
            return [];
        }
        foreach ($companies as $company) {
            if (!is_array($company) || ($company['name'] ?? null) !== self::INTERNAL_CANONICAL_COMPANY) {
                continue;
            }
            $employees = $company['employees'] ?? [];

            return is_array($employees) ? $employees : [];
        }

        return [];
    }

    /**
     * Best-effort year extraction from an ISO-8601 createdAt. A malformed or
     * missing value returns null — the caller then skips the year bump but
     * still counts the scalar (same policy as GenerateTopSecurityCommand).
     */
    private function extractYear(?string $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }
        $ts = strtotime($rawDate);
        if ($ts === false) {
            return null;
        }

        return date('Y', $ts);
    }

    /**
     * Resolve a GitHub profile for a login absent from contributors_prs.json
     * (e.g. QA-ers who never contributed code). Cached per-run.
     *
     * @return array{name?: string, avatar_url?: string, html_url?: string}
     */
    private function resolveProfile(string $login): array
    {
        static $cache = [];
        if (isset($cache[$login])) {
            return $cache[$login];
        }
        try {
            $user = $this->github->getUser($login);
        } catch (Throwable $e) {
            $this->output->writeLn(['  Failed to resolve GitHub user ' . $login . ': ' . $e->getMessage()]);

            return $cache[$login] = [];
        }

        return $cache[$login] = [
            'name' => (string) ($user['name'] ?? $login),
            'avatar_url' => (string) ($user['avatar_url'] ?? ''),
            'html_url' => (string) ($user['html_url'] ?? 'https://github.com/' . $login),
        ];
    }
}
