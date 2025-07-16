<?php

namespace PrestaShop\Traces\Command;

use DateTime;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class GenerateNewContributorsCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $configExclusions = [];
    protected bool $configKeepExcludedUsers = false;

    protected function configure()
    {
        $this
            ->setName('traces:generate:newcontributors')
            ->setDescription('Generate New Contributors from Merged PRs and list of contributors')
            ->addOption(
                'limitNew',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                10
            )
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!file_exists(self::FILE_PULLREQUESTS)) {
            $this->output->writeLn(sprintf(
                '%s is missing. Please execute `php bin/console traces:fetch:repositories`',
                self::FILE_PULLREQUESTS
            ));

            return 1;
        }

        if (!file_exists(self::FILE_CONTRIBUTORS)) {
            $this->output->writeLn(sprintf(
                '%s is missing. Please execute `php bin/console traces:fetch:contributors`',
                self::FILE_CONTRIBUTORS
            ));

            return 1;
        }

        $this->fetchConfiguration($input->getOption('config'));

        $data = json_decode(file_get_contents(self::FILE_PULLREQUESTS), true);
        $pullRequests = $data['pullRequests'];

        $contributors = json_decode(file_get_contents(self::FILE_CONTRIBUTORS), true);

        // To get new contributors we check the first contribution PR date, and sort by first contribution date
        $newContributors = [];
        foreach ($pullRequests as $pullRequest) {
            if ($pullRequest['state'] !== 'MERGED' || empty($pullRequest['mergedAt'])) {
                continue;
            }

            // Exclude some users (mostly bots)
            if (empty($pullRequest['author']['login'])
                || (!$this->configKeepExcludedUsers && in_array($pullRequest['author']['login'], $this->configExclusions, true))) {
                continue;
            }

            $login = $pullRequest['author']['login'];
            if (!isset($contributors[$login])) {
                continue;
            }

            $mergedAt = $pullRequest['mergedAt'];
            if (!isset($newContributors[$login])) {
                $contributor = $contributors[$login];
                $newContributors[$login] = [
                    'login' => $login,
                    'name' => $contributor['name'],
                    'avatar_url' => $contributor['avatar_url'],
                    'html_url' => $contributor['html_url'],
                    'contributions' => $contributor['contributions'],
                    'firstContributionAt' => $mergedAt,
                ];
            } else {
                $firstContributionAt = new DateTime($newContributors[$login]['firstContributionAt']);
                $prMergedAt = new DateTime($mergedAt);
                if ($prMergedAt < $firstContributionAt) {
                    $newContributors[$login]['firstContributionAt'] = $mergedAt;
                }
            }
        }

        // Now that we have all the contributors and their first contribution we sort them to get the most recent ones
        uasort($newContributors, function ($userA, $userB) {
            $firstContributionA = new DateTime($userA['firstContributionAt']);
            $firstContributionB = new DateTime($userB['firstContributionAt']);
            if ($firstContributionA > $firstContributionB) {
                return -1;
            }

            return 1;
        });

        $optionLimitNew = (int) $input->getOption('limitNew');
        $lastNewContributors = array_slice(
            $newContributors,
            0,
            $optionLimitNew,
            true
        );
        \file_put_contents(self::FILE_NEW_CONTRIBUTORS, json_encode($lastNewContributors, JSON_PRETTY_PRINT));

        $output->writeLn('Generated ' . count($lastNewContributors) . ' new contributors (picked among ' . count($contributors) . ').');

        return 0;
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
