<?php

namespace PrestaShop\Traces\Service;

use Cache\Adapter\Filesystem\FilesystemCachePool;
use Github\Api\GraphQL;
use Github\Api\Repo;
use Github\Api\User;
use Github\Client;
use Github\Exception\RuntimeException;
use Github\HttpClient\Message\ResponseMediator;
use Github\ResultPager;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

class Github
{
    /**
     * @var Client
     */
    protected $client;

    public function __construct(string $cacheDir, ?string $ghtoken = null)
    {
        $filesystemAdapter = new Local($cacheDir);
        $filesystem = new Filesystem($filesystemAdapter);
        $pool = new FilesystemCachePool($filesystem);

        $this->client = new Client();
        $this->client->addCache($pool);

        if (!empty($ghtoken)) {
            $this->client->authenticate($ghtoken, null, Client::AUTH_ACCESS_TOKEN);
        }
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function apiSearchGraphQL(string $graphQLQuery): array
    {
        /** @var GraphQL $clientGraphQL */
        $clientGraphQL = $this->client->api('graphql');
        do {
            try {
                $resultPage = $clientGraphQL->execute($graphQLQuery, []);
            } catch (RuntimeException $e) {
            }
        } while (!isset($resultPage));

        return $resultPage;
    }

    public function getContributors(string $repository): array
    {
        /** @var Repo $clientRepo */
        $clientRepo = $this->client->api('repo');

        $repositoryArr = explode('/', $repository);
        $paginator = new ResultPager($this->client);
        $response = $paginator->fetchAll($clientRepo, 'contributors', ['PrestaShop', $repository]);

        return $response;
    }

    public function getUser(string $login): array
    {
        /** @var User $clientUser */
        $clientUser = $this->client->api('user');

        return $clientUser->show($login);
    }

    /**
     * Published security advisories of a repository (the REST endpoint only
     * exposes published ones to non-admin tokens).
     *
     * @return array<array<string, mixed>>
     */
    public function getRepoSecurityAdvisories(string $repository): array
    {
        $advisories = [];
        $page = 1;
        do {
            try {
                $response = $this->client->getHttpClient()->get(sprintf(
                    '/repos/PrestaShop/%s/security-advisories?per_page=100&page=%d',
                    rawurlencode($repository),
                    $page
                ));
            } catch (RuntimeException $e) {
                // Repositories with advisories disabled answer 404
                return $advisories;
            }
            /** @var array<array<string, mixed>> $content */
            $content = ResponseMediator::getContent($response);
            $advisories = array_merge($advisories, $content);
            ++$page;
        } while (count($content) === 100);

        return $advisories;
    }
}
