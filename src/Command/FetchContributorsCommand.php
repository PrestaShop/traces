<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchContributorsCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];

    protected function configure(): void
    {
        $this->setName('traces:fetch:contributors')
            ->setDescription('Fetch contributors from Github')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN'] ?? null
            )
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'GitHub repository')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists('gh_repositories.json')) {
            $this->output->writeLn('gh_repositories.json is missing. Please execute `php bin/console traces:fetch:repositories`');

            return 1;
        }

        $repository = $input->getOption('repository');
        if (!empty($repository)) {
            $this->orgRepositories = [$repository];
        } else {
            $this->orgRepositories = json_decode(file_get_contents('gh_repositories.json') ?: '', true);
        }
        $this->output->writeLn('Fetching contributors data for these repositories: ' . implode(', ', $this->orgRepositories));

        $time = time();

        $this->fetchConfiguration($input->getOption('config'));

        $contributors = $this->fetchContributors();
        file_put_contents(self::FILE_CONTRIBUTORS_COMMITS, json_encode($contributors, JSON_PRETTY_PRINT));

        $this->output->writeLn([
            '',
            count($contributors) . ' contributors fetched.',
            '',
            'Output generated in ' . (time() - $time) . 's.',
        ]);

        return 0;
    }

    /**
     * @return array<'updatedAt'|int, string|array{
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
     *    contributions: int,
     *    repositories: array<string, int>,
     *    categories: array<string, array{total: int, repositories: array<string, int>}>
     * }>
     */
    protected function fetchContributors(): array
    {
        $this->output->writeLn(sprintf('Loading contributors from %d repositories...', count($this->orgRepositories)));

        /**
         * @var array<string, array{
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
         *    contributions: int,
         *    repositories: array<string, int>,
         *    categories: array<string, array{total: int, repositories: array<string, int>}>
         * }> $users
         */
        $users = [];
        $categories = [];
        foreach (self::REPOSITORIES_CATEGORIES as $section => $repositories) {
            $categories[$section] = [
                'total' => 0,
                'repositories' => [],
            ];
            foreach ($repositories as $repository) {
                $categories[$section]['repositories'][$repository] = 0;
            }
        }

        $progressBar = new ProgressBar($this->output, count($this->orgRepositories));
        $progressBar->start();
        foreach ($this->orgRepositories as $repository) {
            $section = array_reduce(array_keys(self::REPOSITORIES_CATEGORIES), function ($carry, $item) use ($repository) {
                return in_array($repository, self::REPOSITORIES_CATEGORIES[$item]) ? $item : $carry;
            }, 'others');

            $contributors = $this->github->getContributors($repository);
            foreach ($contributors as $contributor) {
                $ghLogin = $contributor['login'];
                // skip user if excluded
                if (!$this->configKeepExcludedUsers && in_array($ghLogin, $this->configExclusions, true)) {
                    continue;
                }
                if (!array_key_exists($ghLogin, $users)) {
                    $user = $this->github->getUser($ghLogin);
                    $userEmail = $user['email'];

                    // Clean up response if whitelist is defined
                    if (!empty($this->configFieldsWhitelist)) {
                        $user = array_intersect_key($user, $this->configFieldsWhitelist);
                    }

                    // Add mail domain if setting enabled
                    if ($this->configExtractEmailDomain) {
                        $user['email_domain'] = empty($userEmail)
                            ? ''
                            : substr($userEmail, strpos($userEmail, '@') + 1);
                    }

                    // add exclusion property if setting enabled
                    if ($this->configKeepExcludedUsers) {
                        $user['excluded'] = in_array($ghLogin, $this->configExclusions, true);
                    }

                    $user['contributions'] = 0;
                    $user['repositories'] = [];
                    $user['categories'] = $categories;

                    $users[$ghLogin] = $user;
                }
                $users[$ghLogin]['contributions'] += $contributor['contributions'];
                $users[$ghLogin]['repositories'][$repository] = $contributor['contributions'];
                $users[$ghLogin]['categories'][$section]['total'] += $contributor['contributions'];
                if (!array_key_exists($repository, $users[$ghLogin]['categories'][$section]['repositories'])) {
                    $users[$ghLogin]['categories'][$section]['repositories'][$repository] = 0;
                }
                $users[$ghLogin]['categories'][$section]['repositories'][$repository] += $contributor['contributions'];
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        // Clean
        foreach ($users as &$user) {
            foreach ($user['categories'] as $keySection => $section) {
                foreach ($section['repositories'] as $repository => $count) {
                    if ($count == 0) {
                        unset($user['categories'][$keySection]['repositories'][$repository]);
                    }
                }
            }
        }

        // Use uasort to keep the initial key matching the login, but still order the list by contributions
        uasort($users, function (array $userA, array $userB): int {
            if ($userA['contributions'] == $userB['contributions']) {
                return 0;
            }

            return ($userA['contributions'] > $userB['contributions']) ? -1 : 1;
        });
        $users['updatedAt'] = date('Y-m-d H:i:s');

        return $users;
    }
}
