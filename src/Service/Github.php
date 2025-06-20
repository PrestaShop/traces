<?php
namespace PrestaShop\Traces\Service;

use Cache\Adapter\Filesystem\FilesystemCachePool;
use Exception;
use Github\Client;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

class Github
{
  /**
   * @var Client;
   */
  protected $client;

  public function __construct(string $cacheDir, string $ghToken = null)
  {
      $filesystemAdapter = new Local($cacheDir);
      $filesystem = new Filesystem($filesystemAdapter);
      $pool = new FilesystemCachePool($filesystem);

      $this->client = new Client();
      $this->client->addCache($pool);

      if (!empty($ghToken)) {
          $this->client->authenticate($ghToken, null, Client::AUTH_ACCESS_TOKEN);
      }
  }

  public function getClient(): Client
  {
      return $this->client;
  }

  public function apiSearchGraphQL(string $graphQLQuery): array
  {
      do {
          try {
              $resultPage = $this->client->api('graphql')->execute($graphQLQuery, []);
          } catch (\RuntimeException $e) {
          }
      } while (!isset($resultPage));

      return $resultPage ?? [];
  }

  public function getContributors(string $repository): array
  {
    $repositoryArr = explode('/', $repository);
    $paginator  = new ResultPager($this->client);
    $response = $paginator->fetchAll($this->client->api('repo'), 'contributors', ['PrestaShop', $repository]);

    return $response;
  }

  public function getUser(string $login): array
  {
    return $this->client->api('user')->show($login);
  }
}
