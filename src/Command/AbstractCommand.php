<?php

namespace PrestaShop\Traces\Command;

use PrestaShop\Traces\Service\Github;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class AbstractCommand extends Command
{
    protected const FILE_CONTRIBUTORS_COMMITS = 'contributors.json';

    protected const FILE_CONTRIBUTORS_PRS = 'contributors_prs.json';

    protected const FILE_PULLREQUESTS = 'gh_pullrequests.json';

    protected const FILE_REPOSITORIES = 'gh_repositories.json';

    protected const FILE_TOP_COMPANIES = 'topcompanies.json';

    protected const FILE_TOP_COMPANIES_PRS = 'topcompanies_prs.json';

    protected const FILE_NEW_CONTRIBUTORS = 'newcontributors.json';

    protected const FILE_GHLOGIN_WO_COMPANY = 'gh_loginsWOCompany.json';

    protected const FILE_DATA_COMPANIES = 'var/data/companies.json';

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
            'user-documentation-landing',
            'user-documentation-en',
            'user-documentation-fr',
            'user-documentation-es',
            'user-documentation-it',
            'user-documentation-nl',
            'user-documentation-fa',
            'user-documentation-1.6',
            'user-documentation-v8-en',
            'user-documentation-v8-fr',
            'functional-documentation',
            'ADR',
            'test-scenarios',
            'example-modules',
            'prestashop-retro',
            'open-source',
            'example_module_mailtheme',
            'childtheme-example',
            'module-companion',
            'ps-docs-theme',
            'DocToolsBundle',
            'devdocs-site',
            'contextual-help-api',
            'keycloak_connector_demo',
            'webservice-postman-examples',
        ],
        'themes' => [
            'classic-theme',
            'community-theme-16',
            'hummingbird',
            'StarterTheme',
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
            '.github',
            'composer-script-handler',
            'autoload',
            'azure-template-basic',
            'azure-template-high-performance',
            'azure-template-performance',
            'bootstrap-compatibility-layer',
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
            'ps-project-metrics',
            'phpstan-prestashop',
            'PrestaShop-norm-validator',
        ],
        'tests' => [
            'ui-testing-library',
            'ga.tests.ui.pr',
            'PSFunctionalTests',
        ],
        'blog' => [
            'prestashop.github.io',
        ],
        'others' => [
            'performance-project',
            'engineering',
            '.github',
            'ps-org-theme',
            'zip-archives',
        ],
    ];

    protected Github $github;

    protected OutputInterface $output;

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
    protected array $configKeepedRepositories = [];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->github = new Github(APPVAR_DIR, $input->getOption('ghtoken'));
        $this->output = $output;

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
        $this->configExtractEmailDomain = $config['extractEmailDomain'] ?? false;
        $this->configFieldsWhitelist = $config['fieldsWhitelist'] ? array_flip($config['fieldsWhitelist']) : [];
        $this->configKeepedRepositories = $config['keepedRepositories'] ?? [];
    }
}
