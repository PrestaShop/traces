<?php

namespace PrestaShop\Traces\Command;

use PrestaShop\Traces\Service\Github;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class AbstractCommand extends Command
{
  protected const FILE_PULLREQUESTS = 'gh_pullrequests.json';

  protected const FILE_REPOSITORIES = 'gh_repositories.json';

  /**
   * @var Github
   */
  protected $github;

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->github = new Github(APPVAR_DIR, $input->getOption('ghtoken'));

    return 0;
  }
}