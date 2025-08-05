<?php

namespace PrestaShop\Traces\DTO;

class Company
{
    public int $mergedPullRequests = 0;
    public int $contributions = 0;
    public int $rankByPR = 0;
    public int $rankByContributions = 0;
    public float $pullRequestsPercent = 0;
    public float $contributionsPercent = 0;
    /**
     * @var array<int, int>
     */
    public array $mergedPullRequestsByYear = [];
    /**
     * @var array<string, int>
     */
    public array $mergedPullRequestsByVersion = [];
    /**
     * @var array<int, int>
     */
    public array $mergedContributionsByYear = [];
    /**
     * @var array<string, int>
     */
    public array $mergedContributionsByVersion = [];

    public function __construct(
        public readonly string $name,
        /**
         * @var string[]
         */
        public readonly array $aliases,
        /**
         * @var Employee[]
         */
        public readonly array $employees,
        public readonly string $avatarUrl,
        public readonly string $htmlUrl,
    ) {
    }

    /**
     * @return array<string, array<int|string, int>|float|int|string>
     */
    public function toArray(bool $rankByContributions): array
    {
        return [
            'name' => $this->name,
            'rank' => $rankByContributions ? $this->rankByContributions : $this->rankByPR,
            'rank_contributions' => $this->rankByContributions,
            'rank_pull_requests' => $this->rankByPR,
            'merged_pull_requests' => $this->mergedPullRequests,
            'merged_pull_requests_by_version' => $this->mergedPullRequestsByVersion,
            'merged_pull_requests_by_year' => $this->mergedPullRequestsByYear,
            'pull_requests_percent' => $this->pullRequestsPercent,
            'contributions' => $this->contributions,
            'contributions_by_version' => $this->mergedContributionsByVersion,
            'contributions_by_year' => $this->mergedContributionsByYear,
            'contributions_percent' => $this->contributionsPercent,
            'avatar_url' => $this->avatarUrl,
            'html_url' => $this->htmlUrl,
        ];
    }
}
