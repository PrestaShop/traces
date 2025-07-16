<?php

namespace PrestaShop\Traces\Command;

use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class FetchContributorsCommand extends AbstractCommand
{
    protected const REPOSITORIES_CATEGORIES = [
        'core' => [
            'PrestaShop',
            'prestashop-icon-font',
            'smarty',
            'prestashop-ui-kit',
            'TranslationToolsBundle',
            'LocalizationFiles',
            'stylelint-config',
            'stylelint-browser-compatibility',
            'jquery.live-polyfill',
            'circuit-breaker',
            'eslint-config',
            'php-dev-tools',
            'TranslationFiles',
            'decimal',
            'php-ps-info',
        ],
        'specs' => [
            'prestashop-specs',
        ],
        'documentation' => [
            'paymentexample',
            'docs',
            'user-documentation-en',
            'user-documentation-fr',
            'user-documentation-es',
            'user-documentation-it',
            'user-documentation-nl',
            'user-documentation-fa',
            'ADR',
            'test-scenarios',
            'example-modules',
            'prestashop-retro',
            'open-source',
            'example_module_mailtheme',
            'childtheme-example',
            'module-companion',
        ],
        'themes' => [
            'classic-theme',
            'community-theme-16',
            'hummingbird',
        ],
        'modules' => [
            'PrestaShop-modules',
            'blockcontactinfos',
            'blockfacebook',
            'blocklink',
            'blockmyaccountfooter',
            'blockpaymentlogo',
            'blockpermanentlinks',
            'blocksharefb',
            'blockstore',
            'blocktags',
            'dashactivity',
            'dashgoals',
            'dashproducts',
            'dashtrends',
            'graphnvd3',
            'gridhtml',
            'pagesnotfound',
            'productpaymentlogos',
            'sekeywords',
            'statsbestcategories',
            'statsbestcustomers',
            'statsbestmanufacturers',
            'statsbestproducts',
            'statsbestsuppliers',
            'statsbestvouchers',
            'statscarrier',
            'statscatalog',
            'statscheckup',
            'statsdata',
            'statsequipment',
            'statsforecast',
            'statslive',
            'statsnewsletter',
            'statsorigin',
            'statspersonalinfos',
            'statsproduct',
            'statsregistrations',
            'statssales',
            'statssearch',
            'statsstock',
            'statsvisits',
            'themeconfigurator',
            'trackingfront',
            'vatnumber',
            'blockwishlist',
            'carriercompare',
            'cashondelivery',
            'dateofdelivery',
            'editorial',
            'favoriteproducts',
            'followup',
            'gamification',
            'gapi',
            'loyalty',
            'mailalerts',
            'newsletter',
            'productcomments',
            'pscleaner',
            'referralprogram',
            'sendtoafriend',
            'autoupgrade',
            'watermark',
            'gsitemap',
            'ganalytics',
            'prestafraud',
            'pssupport',
            'eurovatgenerator',
            'securitypatch',
            'contactform',
            'welcome',
            'blockreassurance',
            'ps_sharebuttons',
            'ps_socialfollow',
            'ps_linklist',
            'ps_customtext',
            'ps_contactinfo',
            'ps_emailsubscription',
            'ps_banner',
            'ps_featuredproducts',
            'ps_imageslider',
            'ps_mainmenu',
            'ps_customersignin',
            'ps_categorytree',
            'ps_legalcompliance',
            'ps_wirepayment',
            'ps_shoppingcart',
            'ps_currencyselector',
            'ps_languageselector',
            'ps_facetedsearch',
            'ps_customeraccountlinks',
            'ps_searchbar',
            'ps_checkpayment',
            'ps_emailalerts',
            'ps_rssfeed',
            'ps_bestsellers',
            'ps_advertising',
            'ps_brandlist',
            'ps_categoryproducts',
            'ps_newproducts',
            'ps_specials',
            'ps_supplierlist',
            'ps_crossselling',
            'ps_productinfo',
            'ps_dataprivacy',
            'ps_cashondelivery',
            'ps_carriercomparison',
            'ps_viewedproduct',
            'ps_feeder',
            'ps_reminder',
            'ps_searchbarjqauto',
            'ps_buybuttonlite',
            'ps_faviconnotificationbo',
            'ps_healthcheck',
            'ps_qualityassurance',
            'ps_emailgenerator',
            'pstagger',
            'ps_emailsmanager',
            'ps_livetranslation',
            'ps_googleanalytics',
            'ps_themecusto',
            'psgdpr',
            'ps_apiresources',
            'ps_distributionapiclient',
            'ps_fixturescreator',
        ],
        'tools' => [
            'azure-template-basic',
            'azure-template-high-performance',
            'azure-template-performance',
            'core-weekly-generator',
            'docker',
            'docker-ci',
            'docker-internal-images',
            'docker-templates',
            'email-templates-sdk',
            'fontmanager',
            'github-webhook-parser',
            'issuebot',
            'live-demo-devices',
            'mjml-theme-converter',
            'module-generator',
            'nightly-board',
            'PrestaShop-webservice-lib',
            'prestashop-shop-creator',
            'presthubot',
            'presthubot-ui',
            'prestonbot',
            'ps-monitor-module-releases',
            'QANightlyResults',
            'TopContributors',
            'TopTranslators',
            'traces',
            'travis-status-board',
            'vagrant',
            'kanbanbot',
            'psssst',
            'SeamlessUpgradeToolbox',
        ],
        'tests' => [
            'ui-testing-library',
            'ga.tests.ui.pr',
        ],
        'blog' => [
            'prestashop.github.io',
        ],
        'others' => [
            'performance-project',
            'engineering',
            '.github',
            'ps-org-theme',
        ],
    ];

    /**
     * @var array<string>
     */
    protected array $orgRepositories = [];
    /**
     * @var array<string>
     */
    protected array $configExclusions = [];
    protected bool $configKeepExcludedUsers = false;
    protected bool $configExtractEmailDomain = false;
    /**
     * @var array<string>
     */
    protected array $configFieldsWhitelist = [];
    /**
     * @var array<string>
     */
    protected array $configExcludeRepositories = [];

    protected function configure()
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
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, '', 'config.dist.yml')
            ->addOption('repository', 'r', InputOption::VALUE_OPTIONAL, 'GitHub repository');
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
        // Keep contributors.js for backward compatibility with the old page, but could be removed when no longer used
        file_put_contents(self::FILE_CONTRIBUTORS, json_encode($contributors, JSON_PRETTY_PRINT));

        file_put_contents(self::FILE_CONTRIBUTORS_LEGACY, json_encode($contributors, JSON_PRETTY_PRINT));
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
     *    location: null|string,
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
         *    location: null|string,
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
        $this->configExtractEmailDomain = $config['extractEmailDomain'] ?? false;
        $this->configFieldsWhitelist = $config['fieldsWhitelist'] ? array_flip($config['fieldsWhitelist']) : [];
        $this->configExcludeRepositories = $config['excludeRepositories'] ?? [];
    }
}
