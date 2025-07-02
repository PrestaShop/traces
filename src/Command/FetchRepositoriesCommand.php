<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchRepositoriesCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('traces:fetch:repositories')
            ->setDescription('Fetch repositories from Github')
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

        $time = time();

        $repositories = $this->fetchOrgRepositories();
        file_put_contents(self::FILE_REPOSITORIES, json_encode($repositories, JSON_PRETTY_PRINT));
        $this->output->writeLn([
            count($repositories) . ' repositories fetched.',
            '',
            'Output generated in ' . (time() - $time) . 's.',
        ]);

        return 0;
    }

    /**
     * @return array<string>
     */
    protected function fetchOrgRepositories(): array
    {
        $repositories = [];

        $graphQL = 'query {
      organization(login: "PrestaShop") {
        repositories(first: 100, after: "%s", isArchived: false, isFork: false, privacy: PUBLIC) {
          totalCount
          pageInfo {
            endCursor
            hasNextPage
          }
          nodes {
            isArchived
            name
          }
        }
      }
    }';

        do {
            $afterCursor = $data['data']['organization']['repositories']['pageInfo']['endCursor'] ?? '';
            $data = $this->github->apiSearchGraphQL(sprintf($graphQL, $afterCursor));
            foreach ($data['data']['organization']['repositories']['nodes'] as $node) {
                $repositories[] = $node['name'];
            }
        } while ($data['data']['organization']['repositories']['pageInfo']['hasNextPage'] === true);

        return $repositories;
    }
}
