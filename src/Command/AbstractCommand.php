<?php

namespace PrestaShop\Traces\Command;

use PrestaShop\Traces\Service\Github;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractCommand extends Command
{
    protected const FILE_PULLREQUESTS = 'gh_pullrequests.json';

    protected const FILE_REPOSITORIES = 'gh_repositories.json';

    protected const FILE_TOP_COMPANIES = 'topcompanies.json';

    protected const FILE_GHLOGIN_WO_COMPANY = 'gh_loginsWOCompany.json';

    protected const FILE_DATA_COMPANY_ALIASES = 'var/data/company_aliases.json';

    protected const FILE_DATA_COMPANY_EMPLOYEES = 'var/data/company_employees.json';

    /**
     * @var Github
     */
    protected $github;

    /**
     * OutputInterface
     */
    protected $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->github = new Github(APPVAR_DIR, $input->getOption('ghtoken'));
        $this->output = $output;

        return 0;
    }
}
