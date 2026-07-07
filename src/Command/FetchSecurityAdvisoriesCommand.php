<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchSecurityAdvisoriesCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];

    protected function configure(): void
    {
        $this->setName('traces:fetch:security-advisories')
            ->setDescription('Fetch published security advisories and their credits from Github')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                isset($_ENV['GH_TOKEN']) ? (string) $_ENV['GH_TOKEN'] : null
            )
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'GitHub repository');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists(self::FILE_REPOSITORIES)) {
            $this->output->writeLn(self::FILE_REPOSITORIES . ' is missing. Please execute `php bin/console traces:fetch:repositories`');

            return 1;
        }

        $repository = $input->getOption('repository');
        if (!empty($repository)) {
            $this->orgRepositories = [$repository];
        } else {
            $this->orgRepositories = json_decode(file_get_contents(self::FILE_REPOSITORIES) ?: '', true);
        }

        $time = time();
        $this->output->writeLn([count($this->orgRepositories) . ' repositories to scan.']);
        $this->fetchOrgSecurityAdvisories();
        $this->output->writeLn(['', 'Output generated in ' . (time() - $time) . 's.']);

        return 0;
    }

    protected function fetchOrgSecurityAdvisories(): void
    {
        $advisories = [];
        if (file_exists(self::FILE_SECURITY_ADVISORIES)) {
            unlink(self::FILE_SECURITY_ADVISORIES);
        }

        foreach ($this->orgRepositories as $repository) {
            $nodes = $this->github->getRepoSecurityAdvisories($repository);

            foreach ($nodes as $node) {
                // Withdrawn advisories no longer stand
                if (!empty($node['withdrawn_at'])) {
                    continue;
                }

                $credits = [];
                foreach ($node['credits_detailed'] ?? [] as $credit) {
                    if (($credit['state'] ?? '') !== 'accepted') {
                        continue;
                    }
                    $user = $credit['user'] ?? [];
                    if (empty($user['login'])) {
                        continue;
                    }
                    $credits[] = [
                        'login' => $user['login'],
                        'avatar_url' => $user['avatar_url'] ?? null,
                        'html_url' => $user['html_url'] ?? null,
                        'type' => $credit['type'] ?? null,
                    ];
                }

                $advisories[] = [
                    'ghsa_id' => $node['ghsa_id'] ?? null,
                    'cve_id' => $node['cve_id'] ?? null,
                    'summary' => $node['summary'] ?? null,
                    'severity' => $node['severity'] ?? null,
                    'published_at' => $node['published_at'] ?? null,
                    'html_url' => $node['html_url'] ?? null,
                    'repository' => $repository,
                    'credits' => $credits,
                ];
            }

            $this->output->writeLn([
                'Repository : PrestaShop/' . $repository
                . ' > Advisories: ' . count($nodes)
                . ' - Total: ' . count($advisories)]);
        }

        file_put_contents(self::FILE_SECURITY_ADVISORIES, json_encode($advisories, JSON_PRETTY_PRINT));
    }
}
