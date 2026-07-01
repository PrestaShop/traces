<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchPullRequestsAllCommand extends AbstractCommand
{
    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];

    protected function configure(): void
    {
        $this->setName('traces:fetch:pullrequests:all')
            ->setDescription('Fetch all pull requests (any state) and their reviewers from Github')
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
        $this->fetchOrgPullRequests();
        $this->output->writeLn(['', 'Output generated in ' . (time() - $time) . 's.']);

        return 0;
    }

    protected function fetchOrgPullRequests(): void
    {
        $pullRequests = [];
        if (file_exists(self::FILE_PULLREQUESTS_ALL)) {
            unlink(self::FILE_PULLREQUESTS_ALL);
        }

        foreach ($this->orgRepositories as $repository) {
            $this->output->writeLn(['', 'Repository : PrestaShop/' . $repository]);
            $graphQL = 'query {
              repository(name: "' . $repository . '", owner: "PrestaShop") {
                pullRequests(first: 25, after: "%s", states: [OPEN, MERGED, CLOSED], orderBy: {field: CREATED_AT, direction: DESC}) {
                  totalCount
                  pageInfo {
                    endCursor
                    hasNextPage
                  }
                  nodes {
                    number
                    author {
                      login
                      avatarUrl
                      url
                      ... on User {
                        name
                      }
                    }
                    reviews(first: 100) {
                      nodes {
                        author {
                          login
                          avatarUrl
                          url
                          ... on User {
                            name
                          }
                        }
                      }
                    }
                  }
                }
              }
            }';

            $data = [];
            $pullRequestsCount = 0;
            do {
                $afterCursor = $data['data']['repository']['pullRequests']['pageInfo']['endCursor'] ?? '';
                $data = $this->github->apiSearchGraphQL(sprintf($graphQL, $afterCursor));
                $nodes = $data['data']['repository']['pullRequests']['nodes'];
                $pullRequestsCount += count($nodes);

                foreach ($nodes as $node) {
                    $reviewers = [];
                    foreach ($node['reviews']['nodes'] as $review) {
                        $reviewer = $review['author'] ?? null;
                        $reviewerLogin = $reviewer['login'] ?? null;
                        if ($reviewerLogin !== null && !isset($reviewers[$reviewerLogin])) {
                            $reviewers[$reviewerLogin] = [
                                'login' => $reviewerLogin,
                                'name' => $reviewer['name'] ?? null,
                                'avatar_url' => $reviewer['avatarUrl'] ?? null,
                                'html_url' => $reviewer['url'] ?? null,
                            ];
                        }
                    }
                    $author = $node['author'] ?? null;
                    $pullRequests[] = [
                        'number' => $node['number'],
                        'login' => $author['login'] ?? null,
                        'name' => $author['name'] ?? null,
                        'avatar_url' => $author['avatarUrl'] ?? null,
                        'html_url' => $author['url'] ?? null,
                        'reviewers' => array_values($reviewers),
                    ];
                }

                $this->output->writeLn([
                    'Repository : PrestaShop/' . $repository
                    . ' > Status: ' . $pullRequestsCount . ' / ' . $data['data']['repository']['pullRequests']['totalCount']
                    . ' - Total: ' . count($pullRequests)]);
            } while ($data['data']['repository']['pullRequests']['pageInfo']['hasNextPage'] === true);

            file_put_contents(self::FILE_PULLREQUESTS_ALL, json_encode($pullRequests, JSON_PRETTY_PRINT));
        }
    }
}
