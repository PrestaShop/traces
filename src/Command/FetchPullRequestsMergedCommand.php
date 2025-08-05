<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchPullRequestsMergedCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];

    protected function configure()
    {
        $this->setName('traces:fetch:pullrequests:merged')
            ->setDescription('Fetch merged pullrequests from Github')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN'] ?? null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists(self::FILE_REPOSITORIES)) {
            $this->output->writeLn('gh_repositories.json is missing. Please execute `php bin/console traces:fetch:repositories`');

            return 1;
        }

        $this->orgRepositories = json_decode(file_get_contents(self::FILE_REPOSITORIES) ?: '', true);

        $time = time();

        $this->output->writeLn([count($this->orgRepositories) . ' repositories fetched.']);

        $this->fetchOrgPullRequests();

        $this->output->writeLn(['', 'Output generated in ' . (time() - $time) . 's.']);

        return 0;
    }

    protected function fetchOrgPullRequests(): void
    {
        $pullRequests = [];
        if (file_exists(self::FILE_PULLREQUESTS)) {
            unlink(self::FILE_PULLREQUESTS);
        }

        foreach ($this->orgRepositories as $repository) {
            $this->output->writeLn(['', 'Repository : PrestaShop/' . $repository]);
            $graphQL = 'query {
          repository(name: "' . $repository . '", owner: "PrestaShop") {
            pullRequests(first: 100, after: "%s", states:[MERGED], orderBy: {field: CREATED_AT, direction: DESC}) {
              totalCount
              pageInfo {
                endCursor
                hasNextPage
              }
              nodes {
                number
                title
                body
                state
                author {
                  login
                }
                milestone {
                  title
                }
                repository {
                  name
                  owner {
                    login
                  }
                }
                createdAt
                updatedAt
                mergedAt,
                commits {
                  totalCount
                }
              }
            }
          }
        }';

            $pullRequestsCount = 0;
            $data = [];
            do {
                $afterCursor = $data['data']['repository']['pullRequests']['pageInfo']['endCursor'] ?? '';

                $data = $this->github->apiSearchGraphQL(sprintf($graphQL, $afterCursor));
                $pullRequestsNodes = $data['data']['repository']['pullRequests']['nodes'];
                $pullRequestsCount += count($pullRequestsNodes);
                $pullRequests = array_merge($pullRequests, $pullRequestsNodes);

                file_put_contents(self::FILE_PULLREQUESTS, json_encode([
                    'pullRequests' => $pullRequests,
                    'endCursor' => $data['data']['repository']['pullRequests']['pageInfo']['endCursor'],
                ], JSON_PRETTY_PRINT));

                $this->output->writeLn([
                    'Repository : PrestaShop/' . $repository
                    . ' > Status: ' . $pullRequestsCount . ' / ' . $data['data']['repository']['pullRequests']['totalCount']
                    . ' - Total: ' . count($pullRequests)]);
            } while ($data['data']['repository']['pullRequests']['pageInfo']['hasNextPage'] === true);
        }
    }
}
