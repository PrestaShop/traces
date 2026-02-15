<?php

namespace PrestaShop\Traces\Command;

use DateTimeImmutable;
use PrestaShop\Traces\DTO\Company;
use PrestaShop\Traces\DTO\Employee;
use PrestaShop\Traces\DTO\TimeFrame;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GenerateTopCompaniesCommand extends AbstractCommand
{
    /**
     * @var Company[]
     */
    protected $companies = [];

    /**
     * @var array<string, array{numPRs: int, lastContribution: string}>
     */
    protected $companyEmployeesWOCompany = [];

    /**
     * @var array<string>
     */
    protected array $configExclusions = [];
    protected bool $configKeepExcludedUsers = false;

    /**
     * Keep record of unknown sponsor companies.
     *
     * @var array<string, int>
     */
    protected array $unknownSponsorCompanies = [];

    protected function configure(): void
    {
        $this
            ->setName('traces:generate:topcompanies')
            ->setDescription('Generate Top Companies from Merged PRs')
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

        if (!file_exists(self::FILE_REPOSITORIES)) {
            $this->output->writeLn(self::FILE_REPOSITORIES . ' is missing. Please execute `php bin/console traces:fetch:repositories`');

            return 1;
        }

        if (!file_exists(self::FILE_PULLREQUESTS)) {
            $this->output->writeLn(sprintf(
                '%s is missing. Please execute `php bin/console traces:fetch:pullrequests:merged`',
                self::FILE_PULLREQUESTS
            ));

            return 1;
        }

        if (!file_exists(self::FILE_CONTRIBUTORS_COMMITS)) {
            $this->output->writeLn(sprintf(
                '%s is missing. Please execute `php bin/console traces:fetch:contributors`',
                self::FILE_CONTRIBUTORS_COMMITS
            ));

            return 1;
        }
        $contributors = json_decode(\file_get_contents(self::FILE_CONTRIBUTORS_COMMITS), true);
        // Clean contributors
        unset($contributors['updatedAt']);
        foreach ($contributors as $key => $contributor) {
            $contributors[$key]['mergedPullRequests'] = 0;

            foreach (self::REPOSITORIES_CATEGORIES as $section => $repositories) {
                $contributors[$key]['categories'][$section] = [
                    'total' => 0,
                    'repositories' => [],
                ];
                foreach ($repositories as $repository) {
                    $categories[$section]['repositories'][$repository] = 0;
                }
            }
            $contributors[$key]['repositories'] = [];
        }

        $companiesData = json_decode(\file_get_contents(self::FILE_DATA_COMPANIES), true);
        foreach ($companiesData as $companyData) {
            $employees = [];
            if (!empty($companyData['employees'])) {
                foreach ($companyData['employees'] as $employeeLogin => $employeeTimeFrames) {
                    $timeFrames = [];
                    foreach ($employeeTimeFrames as $timeFrame) {
                        $timeFrames[] = new TimeFrame(
                            new DateTimeImmutable($timeFrame['startDate']),
                            !empty($timeFrame['endDate']) ? new DateTimeImmutable($timeFrame['endDate']) : null);
                    }

                    $employees[] = new Employee(
                        $employeeLogin,
                        $timeFrames,
                    );
                }
            }

            $this->companies[] = new Company(
                $companyData['name'],
                $companyData['aliases'] ?? [],
                $employees,
                $companyData['avatar_url'] ?? '',
                $companyData['html_url'] ?? '',
            );
        }

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
        foreach ($this->companies as $company) {
            if ($company->name === 'Open Source Community') {
                $community = $company;
                break;
            }
        }

        if (!isset($community)) {
            throw new RuntimeException('Could not find community company');
        }

        $totalMergedPRs = 0;
        $totalContributions = 0;
        foreach ($data as $key => $pullRequestData) {
            // Is the PR is not merged ?
            if ($pullRequestData['state'] !== 'MERGED') {
                unset($key);
                ++$numPRRemoved;
                continue;
            }

            // Is a user a bot ?
            $authorLogin = $pullRequestData['author']['login'] ?? '';
            if (!$this->configKeepExcludedUsers && in_array($authorLogin, $this->configExclusions, true)) {
                unset($key);
                ++$numPRRemoved;
                continue;
            }
            $yearMerged = date('Y', strtotime($pullRequestData['mergedAt']));
            $milestone = $pullRequestData['repository']['name'] == 'PrestaShop' ? ($pullRequestData['milestone']['title'] ?? null) : null;

            if (isset($contributors[$authorLogin])) {
                $repository = $pullRequestData['repository']['name'];
                $section = array_reduce(array_keys(self::REPOSITORIES_CATEGORIES), function ($carry, $item) use ($repository) {
                    return in_array($repository, self::REPOSITORIES_CATEGORIES[$item]) ? $item : $carry;
                }, 'others');

                ++$contributors[$authorLogin]['mergedPullRequests'];

                // Repositoies
                if (!array_key_exists($repository, $contributors[$authorLogin]['repositories'])) {
                    $contributors[$authorLogin]['repositories'][$repository] = 0;
                }
                ++$contributors[$authorLogin]['repositories'][$repository];
                // Section : Total
                ++$contributors[$authorLogin]['categories'][$section]['total'];
                // Section : Repositories
                if (!array_key_exists($repository, $contributors[$authorLogin]['categories'][$section]['repositories'])) {
                    $contributors[$authorLogin]['categories'][$section]['repositories'][$repository] = 0;
                }
                ++$contributors[$authorLogin]['categories'][$section]['repositories'][$repository];
            }

            $company = $this->extractCompany($pullRequestData);
            if ($company) {
                $objCompany = $company;
            } else {
                $objCompany = $community;
            }
            // Company : Total PRs
            ++$objCompany->mergedPullRequests;
            // Company; Total contributions
            $pullRequestContributions = $pullRequestData['commits']['totalCount'] ?? 0;
            $objCompany->contributions += $pullRequestContributions;
            // Company : Total PRs by year
            if (!isset($objCompany->mergedPullRequestsByYear[$yearMerged])) {
                $objCompany->mergedPullRequestsByYear[$yearMerged] = 0;
                krsort($objCompany->mergedPullRequestsByYear);
            }
            ++$objCompany->mergedPullRequestsByYear[$yearMerged];
            // Company : Total contributions per year
            if (!isset($objCompany->mergedContributionsByYear[$yearMerged])) {
                $objCompany->mergedContributionsByYear[$yearMerged] = 0;
                krsort($objCompany->mergedContributionsByYear);
            }
            $objCompany->mergedContributionsByYear[$yearMerged] += $pullRequestContributions;
            if (!is_null($milestone)) {
                // Sanitize the milestone & keep the minor version
                // - 1.7.8.2 => 1.7.8
                // - 8.0.2 => 8.0
                $milestone = $milestone[0] === '1' ? substr($milestone, 0, 5) : substr($milestone, 0, 3);
                // Company : Total PRs by version
                if (!isset($objCompany->mergedPullRequestsByVersion[$milestone])) {
                    $objCompany->mergedPullRequestsByVersion[$milestone] = 0;
                    krsort($objCompany->mergedPullRequestsByVersion);
                }
                ++$objCompany->mergedPullRequestsByVersion[$milestone];
                // Company : Total contributions by version
                if (!isset($objCompany->mergedContributionsByVersion[$milestone])) {
                    $objCompany->mergedContributionsByVersion[$milestone] = 0;
                    krsort($objCompany->mergedContributionsByVersion);
                }
                $objCompany->mergedContributionsByVersion[$milestone] += $pullRequestContributions;
            }

            // Total PRs
            ++$totalMergedPRs;
            // Total contributions
            $totalContributions += $pullRequestContributions;
        }

        $this->output->writeLn([
            sprintf(
                '=== Data : %d PRs removed (PR Not in Status = Merged || PR has a Bot Author)',
                $numPRRemoved
            ),
            '',
        ]);

        /** @var Company[] $rankedCompaniesByPR */
        $rankedCompaniesByPR = array_values(array_filter($this->companies, function (Company $company) use ($community) {
            return $company->mergedPullRequests > 0 && $company !== $community;
        }));
        // Sort by number of associated PRs filter the companies that didn't contribute any
        usort($rankedCompaniesByPR, function (Company $a, Company $b) {
            return $b->mergedPullRequests - $a->mergedPullRequests;
        });
        // Now update the rank of each company
        $rankByPR = 0;
        $lastScore = null;
        foreach ($rankedCompaniesByPR as $company) {
            if ($lastScore === null || $lastScore !== $company->mergedPullRequests) {
                ++$rankByPR;
            }

            $company->rankByPR = $rankByPR;
            $company->pullRequestsPercent = round($company->mergedPullRequests / $totalMergedPRs * 100, 2);
            $lastScore = $company->mergedPullRequests;
        }
        $community->pullRequestsPercent = round($community->mergedPullRequests / $totalMergedPRs * 100, 2);
        // Company pull requests total
        $companiesPRsTotal = 0;
        foreach ($rankedCompaniesByPR as $company) {
            if ($company !== $community) {
                $companiesPRsTotal += $company->mergedPullRequests;
            }
        }

        /** @var Company[] $rankedCompaniesByContributions */
        $rankedCompaniesByContributions = array_values(array_filter($this->companies, function (Company $company) use ($community) {
            return $company->contributions > 0 && $company !== $community;
        }));
        // Sort by number of contributions
        usort($rankedCompaniesByContributions, function (Company $a, Company $b) {
            return $b->contributions - $a->contributions;
        });
        // Now update the rank of each company
        $rankByContributions = 0;
        $lastScore = null;
        foreach ($rankedCompaniesByContributions as $company) {
            if ($lastScore === null || $lastScore !== $company->mergedPullRequests) {
                ++$rankByContributions;
            }

            $company->rankByContributions = $rankByContributions;
            $company->contributionsPercent = round($company->contributions / $totalContributions * 100, 2);
            $lastScore = $company->contributions;
        }
        $community->contributionsPercent = round($community->contributions / $totalContributions * 100, 2);
        // Company pull requests total
        $companiesContributionsTotal = 0;
        foreach ($rankedCompaniesByContributions as $company) {
            if ($company !== $community) {
                $companiesContributionsTotal += $company->contributions;
            }
        }

        $this->displayCompaniesNotFound();

        $this->output->writeLn(
            sprintf(
                '=== Company contributors (Sponsor Company & Linked employees) (%d (%.2f%%) PRs for %d companies + %d (%.2f%%) PRs from Community):',
                $companiesPRsTotal,
                100 - $community->pullRequestsPercent,
                count($rankedCompaniesByPR),
                $community->mergedPullRequests,
                $community->pullRequestsPercent,
            )
        );
        $this->writeFileTopCompaniesByPRs($rankedCompaniesByPR, $community);

        $this->output->writeLn(
            sprintf(
                '=== Company contributors (Sponsor Company & Linked employees) (%d (%.2f%%) contributions for %d companies + %d (%.2f%%) contributions from Community):',
                $companiesContributionsTotal,
                100 - $community->contributionsPercent,
                count($rankedCompaniesByContributions),
                $community->contributions,
                $community->contributionsPercent,
            )
        );
        $this->writeFileTopCompaniesByContributions($rankedCompaniesByContributions, $community);
        $this->writeFileGHLoginWOCompany();
        $this->writeFileContributorsPRs($contributors);

        return 0;
    }

    /**
     * @param array{author?: array{login: string}, body: string, createdAt: string, number: int, repository: array{name: string}, mergedAt: string} $datum
     */
    protected function extractCompany(array $datum): ?Company
    {
        $matchCompany = '';
        $authorLogin = $datum['author']['login'] ?? '';

        // Extract company from "Sponsor Company"
        if (preg_match('/\|\h+Sponsor company\h+\|\h+([^\r\n]+)/mu', $datum['body'], $matches)) {
            $matchCompany = strtolower(trim($matches[1]));
        }
        if (!empty($matchCompany)) {
            $company = $this->getCompanyByAlias($matchCompany);
            if ($company) {
                return $company;
            }
            $this->output->writeln(
                'Sponsor Company Not Found : '
                . $datum['repository']['name']
                . '#' . $datum['number']
                . ' => ' . $datum['author']['login']
                . '/' . $matchCompany
            );
            if (!isset($this->unknownSponsorCompanies[$matchCompany])) {
                $this->unknownSponsorCompanies[$matchCompany] = 0;
            }
            ++$this->unknownSponsorCompanies[$matchCompany];

            return null;
        }

        // Extract company from "Author"
        $authorCompany = $this->extractCompanyFromAuthor($authorLogin, $datum['createdAt']);
        if ($authorCompany) {
            return $authorCompany;
        }

        // No company found so we store the author as an employee without Company
        if (!isset($this->companyEmployeesWOCompany[$authorLogin])) {
            $this->companyEmployeesWOCompany[$authorLogin] = [
                'numPRs' => 0,
                'lastContribution' => '',
            ];
        }

        // Num of merged PRs
        ++$this->companyEmployeesWOCompany[$authorLogin]['numPRs'];
        // Last merged PR
        if (empty($this->companyEmployeesWOCompany[$authorLogin]['lastContribution'])
            || $this->companyEmployeesWOCompany[$authorLogin]['lastContribution'] < $datum['mergedAt']) {
            $this->companyEmployeesWOCompany[$authorLogin]['lastContribution'] = $datum['mergedAt'];
        }

        return null;
    }

    protected function extractCompanyFromAuthor(string $login, string $createdAt): ?Company
    {
        $createdAt = new DateTimeImmutable($createdAt);

        foreach ($this->companies as $company) {
            if (empty($company->employees)) {
                continue;
            }

            foreach ($company->employees as $employee) {
                if ($employee->login == $login) {
                    foreach ($employee->timeFrames as $timeframe) {
                        if ($createdAt >= $timeframe->startTime && ($timeframe->endTime === null || $createdAt <= $timeframe->endTime)) {
                            return $company;
                        }
                    }
                }
            }
        }

        return null;
    }

    protected function getCompanyByAlias(string $alias): ?Company
    {
        $trimmedAlias = trim(strtolower($alias));
        foreach ($this->companies as $company) {
            if (trim(strtolower($company->name)) === $trimmedAlias) {
                return $company;
            }

            foreach ($company->aliases as $companyAlias) {
                if (trim(strtolower($companyAlias)) === $trimmedAlias) {
                    return $company;
                }
            }
        }

        return null;
    }

    /**
     * Display unknown sponsor companies to see if they are worth being added in the
     * list of companies (under three PRs no real interest).
     *
     * @return void
     */
    protected function displayCompaniesNotFound(): void
    {
        if (!empty($this->unknownSponsorCompanies)) {
            $this->output->writeLn('There are unknown sponsor companies');
            // Sort ascending to show the more interesting ones last
            uasort($this->unknownSponsorCompanies, function (int $prNbA, int $prNbB) {
                return $prNbA - $prNbB;
            });

            foreach ($this->unknownSponsorCompanies as $company => $numberOfPrs) {
                $this->output->writeLn(sprintf(
                    '%s (%d)',
                    $company,
                    $numberOfPrs
                ));
            }
        }

        $this->output->writeLn('');
    }

    /**
     * @param Company[] $rankedCompanies
     * @param Company $community
     *
     * @return void
     */
    protected function writeFileTopCompaniesByPRs(array $rankedCompanies, Company $community): void
    {
        $numLastContributions = 0;
        foreach ($rankedCompanies as $company) {
            $this->output->writeLn(sprintf(
                '%s %s (%d / %.2f%%)',
                $numLastContributions != $company->mergedPullRequests ? sprintf('#%02d', $company->rankByPR) : '   ',
                $company->name,
                $company->mergedPullRequests,
                $company->pullRequestsPercent
            ));

            $numLastContributions = $company->mergedPullRequests;
        }

        \file_put_contents(self::FILE_TOP_COMPANIES_PRS, json_encode([
            'community' => $community->toArray(false),
            'companies' => array_map(function (Company $company) {
                return $company->toArray(false);
            }, $rankedCompanies),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param Company[] $rankedCompanies
     * @param Company $community
     *
     * @return void
     */
    protected function writeFileTopCompaniesByContributions(array $rankedCompanies, Company $community): void
    {
        $numLastContributions = 0;
        foreach ($rankedCompanies as $company) {
            $this->output->writeLn(sprintf(
                '%s %s (%d / %.2f %%)',
                $numLastContributions != $company->contributions ? sprintf('#%02d', $company->rankByContributions) : '   ',
                $company->name,
                $company->contributions,
                $company->contributionsPercent
            ));

            $numLastContributions = $company->contributions;
        }

        \file_put_contents(self::FILE_TOP_COMPANIES, json_encode([
            'community' => $community->toArray(true),
            'companies' => array_map(function (Company $company) {
                return $company->toArray(true);
            }, $rankedCompanies),
        ], JSON_PRETTY_PRINT));
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

    protected function fetchConfiguration(string $file): void
    {
        if (empty($file)) {
            return;
        }
        if (!file_exists($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('File "%s" doesn\'t exist or is not readable', $file));
        }
        $config = Yaml::parse(file_get_contents($file) ?: '')['config'] ?? [];

        $this->configExclusions = $config['exclusions'] ?? [];
        $this->configKeepExcludedUsers = $config['keepExcludedUsers'] ?? false;
    }

    /**
     * @param array<string, array{
     *    login: string,
     *    id: int,
     *    avatar_url: string,
     *    html_url: string,
     *    name: string,
     *    company: string,
     *    blog: string,
     *    location: string,
     *    location: string|null,
     *    email_domain: string,
     *    mergedPullRequests: int
     * }> $contributors
     */
    protected function writeFileContributorsPRs(array $contributors): void
    {
        // Clean 0-contributions
        foreach ($contributors as $key => $contributor) {
            if ($contributor['mergedPullRequests'] == 0) {
                unset($contributors[$key]);
            }
        }

        // Sort by contributions
        uasort($contributors, function (array $contributorA, array $contributorB): int {
            if ($contributorA['mergedPullRequests'] == $contributorB['mergedPullRequests']) {
                return 0;
            }

            return ($contributorA['mergedPullRequests'] > $contributorB['mergedPullRequests']) ? -1 : 1;
        });
        \file_put_contents(self::FILE_CONTRIBUTORS_PRS, json_encode($contributors, JSON_PRETTY_PRINT));
    }
}
