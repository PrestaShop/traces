<?php
namespace PrestaShop\Traces\Command;

use PrestaShop\Traces\Service\Github;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchRepositoriesCommand extends Command
{
  /**
   * @var Github;
   */
  protected $github;

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
      $this->github = new Github(APPVAR_DIR, $input->getOption('ghtoken'));
      $time = time();

      $repositories = $this->fetchOrgRepositories();
      file_put_contents('gh_repositories.json', json_encode($repositories, JSON_PRETTY_PRINT));
      $output->writeLn([
        count($repositories) . ' repositories fetched.',
        '',
        'Output generated in ' . (time() - $time) . 's.'
      ]);

      return 0;
  }

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
      foreach($data['data']['organization']['repositories']['nodes'] as $node) {
        $repositories[] = $node['name'];
      }
    } while ($data['data']['organization']['repositories']['pageInfo']['hasNextPage'] === true);
    return $repositories;
  }
}
