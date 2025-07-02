<?php

namespace PrestaShop\Traces\Command;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GenerateTopCompaniesCommand extends AbstractCommand
{
    protected int $optionLimitNew = 10;

    /**
     * @var array<string, string>
     */
    protected $companyAliases = [];

    /**
     * @var array<string, array<array{startDate: string, endDate: string, company: string}>>
     */
    protected $companyEmployees = [];

    /**
     * @var array<string, array{numPRs: int, lastContribution: string}>
     */
    protected $companyEmployeesWOCompany = [];

    /**
     * @var array<string>
     */
    protected array $configExclusions = [];
    protected bool $configKeepExcludedUsers = false;

    protected function configure()
    {
        $this
            ->setName('traces:generate:topcompanies')
            ->setDescription('Generate Top Companies from Merged PRs')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN'] ?? null
            )
            ->addOption(
                'limitNew',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                10
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists(self::FILE_PULLREQUESTS)) {
            $this->output->writeLn(sprintf(
                '%s is missing. Please execute `php bin/console traces:fetch:repositories`',
                self::FILE_PULLREQUESTS
            ));

            return 1;
        }

        $this->optionLimitNew = (int) $input->getOption('limitNew');

        $this->companyAliases = json_decode(\file_get_contents(self::FILE_DATA_COMPANY_ALIASES), true);
        $this->companyEmployees = json_decode(\file_get_contents(self::FILE_DATA_COMPANY_EMPLOYEES), true);

        $data = json_decode(file_get_contents(self::FILE_PULLREQUESTS), true);
        $data = $data['pullRequests'];
        $this->output->writeLn([
            sprintf(
                '=== Data : %d PRs processed',
                count($data)
            ),
        ]);

        $this->fetchConfiguration($input->getOption('config'));

        // Clean PR Listing
        $numPRRemoved = 0;
        $companies = [];
        foreach ($data as $key => $datum) {
            // Is the PR is not merged ?
            if ($datum['state'] !== 'MERGED') {
                unset($key);
                ++$numPRRemoved;
                continue;
            }

            // Is a user a bot ?
            if ($datum['author'] === null
              || $datum['author']['login'] === null
              || (!$this->configKeepExcludedUsers && in_array($datum['author']['login'], $this->configExclusions, true))) {
                unset($key);
                ++$numPRRemoved;
                continue;
            }

            $company = $this->extractCompany($datum);
            if (!isset($companies[$company])) {
                $companies[$company] = 0;
            }
            ++$companies[$company];
        }
        $this->output->writeLn([
            sprintf(
                '=== Data : %d PRs removed (PR Not in Status = Merged || PR has no Author || PR has a Bot Author)',
                $numPRRemoved
            ),
            '',
        ]);

        uksort($companies, function ($a, $b) use ($companies) {
            if ($companies[$a] == $companies[$b]) {
                if ($a == $b) {
                    return 0;
                }

                return ($a < $b) ? -1 : 1;
            }

            return ($companies[$a] < $companies[$b]) ? 1 : -1;
        });

        // Company contributors
        $sumContributions = 0;
        foreach ($companies as $company => $numContributions) {
            if ($company == '') {
                continue;
            }
            $sumContributions += $numContributions;
        }

        $this->output->writeLn(
            sprintf(
                '=== Company contributors (Sponsor Company & Linked employees) (%d contributions for %d companies + %d from Community):',
                $sumContributions,
                count($companies) - 1,
                $companies['']
            )
        );

        $this->writeFileTopCompanies($companies);
        $this->writeFileGHLoginWOCompany();
        $this->writeFileNewContributors();

        return 0;
    }

    /**
     * @param array{author: array{login: string}, body: string, createdAt: string, number: int, repository: array{name: string}, mergedAt: string} $datum
     */
    protected function extractCompany(array $datum): string
    {
        $matchCompany = '';

        // Extract company from "Sponsor Company"
        if (preg_match('/\|\h+Sponsor company\h+\|\h+([^\r\n]+)/mu', $datum['body'], $matches)) {
            $matchCompany = trim($matches[1]);
        }
        if (!empty($matchCompany)) {
            if (array_key_exists($matchCompany, $this->companyAliases)) {
                if (!empty($this->companyAliases[$matchCompany])) {
                    return $this->companyAliases[$matchCompany];
                }
            } else {
                $this->output->writeln(
                    'Sponsor Company Not Found : '
                    . $datum['repository']['name']
                    . '#' . $datum['number']
                    . ' => ' . $datum['author']['login']
                    . '/' . $matchCompany
                );

                return '';
            }
        }

        // Extract company from "Author"
        $matchCompany = $this->extractCompanyFromAuthor($datum['author']['login'], $datum['createdAt']);
        $matchCompany = trim($matchCompany);

        if (!empty($matchCompany) && array_key_exists($matchCompany, $this->companyAliases)) {
            return $this->companyAliases[$matchCompany];
        }
        if (!isset($this->companyEmployeesWOCompany[$datum['author']['login']])) {
            $this->companyEmployeesWOCompany[$datum['author']['login']] = [
                'numPRs' => 0,
                'lastContribution' => '',
            ];
        }

        // Num of merged PRs
        ++$this->companyEmployeesWOCompany[$datum['author']['login']]['numPRs'];
        // Last merged PR
        if (empty($this->companyEmployeesWOCompany[$datum['author']['login']]['lastContribution'])
            || $this->companyEmployeesWOCompany[$datum['author']['login']]['lastContribution'] < $datum['mergedAt']) {
            $this->companyEmployeesWOCompany[$datum['author']['login']]['lastContribution'] = $datum['mergedAt'];
        }

        return '';
    }

    protected function extractCompanyFromAuthor(string $login, string $createdAt): string
    {
        if (!isset($this->companyEmployees[$login])) {
            return '';
        }

        $timeframes = $this->companyEmployees[$login];

        $createdAt = date('Y-m-d', strtotime($createdAt));
        foreach ($timeframes as $timeframe) {
            $timeframeStart = date('Y-m-d', strtotime($timeframe['startDate']));
            $timeframeEnd = date('Y-m-d', strtotime($timeframe['endDate'] ?: 'now'));

            if (($createdAt >= $timeframeStart) && ($createdAt <= $timeframeEnd)) {
                return $timeframe['company'];
            }
        }

        return '';
    }

    /**
     * @param array<string, int> $companies
     */
    protected function writeFileTopCompanies(array $companies): void
    {
        $rank = 1;
        $numLastContributions = 0;
        foreach ($companies as $company => $numContributions) {
            $this->output->writeLn(sprintf(
                '%s %s (%d)',
                $numLastContributions != $numContributions ? sprintf('#%02d', $rank) : '   ',
                $company ?: 'Community',
                $numContributions
            ));

            $numLastContributions = $numContributions;
            ++$rank;
        }
        \file_put_contents(self::FILE_TOP_COMPANIES, json_encode($companies, JSON_PRETTY_PRINT));
    }

    protected function writeFileGHLoginWOCompany(): void
    {
        uasort($this->companyEmployeesWOCompany, function (array $a, array $b): int {
            if ($a['numPRs'] == $b['numPRs']) {
                if ($a['lastContribution'] == $b['lastContribution']) {
                    return 0;
                }

                return ($a['lastContribution'] < $b['lastContribution']) ? -1 : 1;
            }

            return ($a['numPRs'] < $b['numPRs']) ? 1 : -1;
        });
        \file_put_contents(self::FILE_GHLOGIN_WO_COMPANY, json_encode($this->companyEmployeesWOCompany, JSON_PRETTY_PRINT));
    }

    protected function writeFileNewContributors(): void
    {
        $lastNewContributors = array_slice(
            $this->companyEmployeesWOCompany,
            $this->optionLimitNew * -1,
            $this->optionLimitNew,
            true
        );
        $lastNewContributors = array_reverse($lastNewContributors, true);

        $newcontributors = [];
        foreach ($lastNewContributors as $ghLogin => $lastNewContributor) {
            $newcontributors[$ghLogin] = $lastNewContributor['lastContribution'];
        }
        \file_put_contents(self::FILE_NEW_CONTRIBUTORS, json_encode($newcontributors, JSON_PRETTY_PRINT));
    }
}
