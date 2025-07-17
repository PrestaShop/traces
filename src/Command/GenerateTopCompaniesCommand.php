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
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml');
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
            if ($company) {
                ++$company->associatedPullRequests;
            } else {
                ++$community->associatedPullRequests;
            }
        }

        $this->output->writeLn([
            sprintf(
                '=== Data : %d PRs removed (PR Not in Status = Merged || PR has no Author || PR has a Bot Author)',
                $numPRRemoved
            ),
            '',
        ]);

        // Sort by number of associated PRs filter the companies that didn't contribute any
        usort($this->companies, function (Company $a, Company $b) {
            return $b->associatedPullRequests - $a->associatedPullRequests;
        });
        $rankedCompanies = array_filter($this->companies, function (Company $company) {
            return $company->associatedPullRequests > 0;
        });

        $rank = 0;
        $lastScore = null;
        foreach ($rankedCompanies as $company) {
            if ($lastScore === null || $lastScore !== $company->associatedPullRequests) {
                ++$rank;
            }

            $company->rank = $rank;
            $lastScore = $company->associatedPullRequests;
        }

        // Company contributors
        $sumContributions = 0;
        foreach ($rankedCompanies as $company) {
            if ($company !== $community) {
                $sumContributions += $company->associatedPullRequests;
            }
        }

        $this->output->writeLn(
            sprintf(
                '=== Company contributors (Sponsor Company & Linked employees) (%d contributions for %d companies + %d from Community):',
                $sumContributions,
                count($rankedCompanies),
                $community->associatedPullRequests
            )
        );

        $this->displayCompaniesNotFound();
        $this->writeFileTopCompanies($rankedCompanies);
        $this->writeFileGHLoginWOCompany();

        return 0;
    }

    /**
     * @param array{author: array{login: string}, body: string, createdAt: string, number: int, repository: array{name: string}, mergedAt: string} $datum
     */
    protected function extractCompany(array $datum): ?Company
    {
        $matchCompany = '';

        // Extract company from "Sponsor Company"
        if (preg_match('/\|\h+Sponsor company\h+\|\h+([^\r\n]+)/mu', $datum['body'], $matches)) {
            $matchCompany = strtolower(trim($matches[1]));
        }
        if (!empty($matchCompany)) {
            $company = $this->getCompanyByAlias($matchCompany);
            if ($company) {
                return $company;
            } else {
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
        }

        // Extract company from "Author"
        $authorCompany = $this->extractCompanyFromAuthor($datum['author']['login'], $datum['createdAt']);
        if ($authorCompany) {
            return $authorCompany;
        }

        // No company found so we store the author as an employee without Company
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
        $this->output->writeLn('');
    }

    /**
     * @param Company[] $rankedCompanies
     *
     * @return void
     */
    protected function writeFileTopCompanies(array $rankedCompanies): void
    {
        $numLastContributions = 0;
        foreach ($rankedCompanies as $company) {
            $this->output->writeLn(sprintf(
                '%s %s (%d)',
                $numLastContributions != $company->associatedPullRequests ? sprintf('#%02d', $company->rank) : '   ',
                $company->name,
                $company->associatedPullRequests
            ));

            $numLastContributions = $company->associatedPullRequests;
        }

        \file_put_contents(self::FILE_TOP_COMPANIES, json_encode(array_map(function (Company $company) {
            return $company->toArray();
        }, $rankedCompanies), JSON_PRETTY_PRINT));
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
}
