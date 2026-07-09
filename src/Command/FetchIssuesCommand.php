<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchIssuesCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];

    protected function configure(): void
    {
        $this->setName('traces:fetch:issues')
            ->setDescription('Fetch issues (excluding pull requests) from Github')
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
        $this->fetchOrgIssues();
        $this->output->writeLn(['', 'Output generated in ' . (time() - $time) . 's.']);

        return 0;
    }

    protected function fetchOrgIssues(): void
    {
        $issues = [];
        if (file_exists(self::FILE_ISSUES)) {
            unlink(self::FILE_ISSUES);
        }

        foreach ($this->orgRepositories as $repository) {
            $this->output->writeLn(['', 'Repository : PrestaShop/' . $repository]);
            $graphQL = 'query {
              repository(name: "' . $repository . '", owner: "PrestaShop") {
                issues(first: 100, after: "%s", states: [OPEN, CLOSED], orderBy: {field: CREATED_AT, direction: DESC}) {
                  totalCount
                  pageInfo {
                    endCursor
                    hasNextPage
                  }
                  nodes {
                    number
                    createdAt
                    author {
                      login
                      avatarUrl
                      url
                      ... on User {
                        name
                      }
                    }
                    repository {
                      name
                    }
                  }
                }
              }
            }';

            $data = [];
            $issuesCount = 0;
            do {
                $afterCursor = $data['data']['repository']['issues']['pageInfo']['endCursor'] ?? '';
                $data = $this->github->apiSearchGraphQL(sprintf($graphQL, $afterCursor));
                $nodes = $data['data']['repository']['issues']['nodes'];
                $issuesCount += count($nodes);

                foreach ($nodes as $node) {
                    $author = $node['author'] ?? null;
                    $issues[] = [
                        'number' => $node['number'],
                        'login' => $author['login'] ?? null,
                        'name' => $author['name'] ?? null,
                        'avatar_url' => $author['avatarUrl'] ?? null,
                        'html_url' => $author['url'] ?? null,
                        'repository' => $node['repository']['name'],
                        'createdAt' => $node['createdAt'] ?? null,
                    ];
                }

                $this->output->writeLn([
                    'Repository : PrestaShop/' . $repository
                    . ' > Status: ' . $issuesCount . ' / ' . $data['data']['repository']['issues']['totalCount']
                    . ' - Total: ' . count($issues)]);
            } while ($data['data']['repository']['issues']['pageInfo']['hasNextPage'] === true);

            file_put_contents(self::FILE_ISSUES, json_encode($issues, JSON_PRETTY_PRINT));
        }
    }
}
