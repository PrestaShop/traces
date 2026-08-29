<?php

namespace PrestaShop\Traces\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FetchQaEventsCommand extends AbstractCommand
{
    /**
     * Every label variant used across the org that signals a QA validation.
     * Discovered by scanning every repo's label list — the naming has drifted
     * over time (different check marks, unrendered emoji codes, "by Dev" vs
     * "by Community", etc.). We keep them all here and bucket them into
     * `qa` / `qa_community` in GenerateTopQaCommand.
     */
    private const QA_LABELS = [
        'QA ✔️',
        'QA ✔️ by Community',
        'QA ✓',
        'QA by Community ✓',
        'QA by Dev ✓',
        'QA by dev ✔️',
        'QA with AI ✓',
        'QA :heavy_check_mark:',
        'QA :white_check_mark:',
        'QA approved',
    ];

    protected function configure(): void
    {
        $this->setName('traces:fetch:qaevents')
            ->setDescription('Fetch QA label events from merged PRs across the PrestaShop org')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                isset($_ENV['GH_TOKEN']) ? (string) $_ENV['GH_TOKEN'] : null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!file_exists(self::FILE_REPOSITORIES)) {
            $this->output->writeLn(self::FILE_REPOSITORIES . ' is missing. Please execute `php bin/console traces:fetch:repositories`');

            return 1;
        }

        $repositories = json_decode(file_get_contents(self::FILE_REPOSITORIES) ?: '', true);
        $time = time();
        $events = [];

        foreach (self::QA_LABELS as $label) {
            $this->output->writeLn(['', 'Label: ' . $label]);
            foreach ($repositories as $repository) {
                $repoEvents = $this->fetchLabelEventsForRepo($label, $repository);
                if (count($repoEvents) > 0) {
                    $this->output->writeLn(['  PrestaShop/' . $repository . ': ' . count($repoEvents) . ' events']);
                }
                $events = array_merge($events, $repoEvents);
            }
        }

        $events = $this->dedup($events);

        file_put_contents(self::FILE_QA_EVENTS, json_encode([
            'events' => array_values($events),
            'fetchedAt' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->output->writeLn(['', count($events) . ' unique QA events written to ' . self::FILE_QA_EVENTS . ' in ' . (time() - $time) . 's.']);

        return 0;
    }

    /**
     * Search PRs by label WITHIN a single repository. The GitHub search API
     * caps results at 1000 per query — segmenting by repo keeps every module
     * well under that ceiling (the only volume risk was the core repo, which
     * does not use these labels).
     *
     * @return array<array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}>
     */
    private function fetchLabelEventsForRepo(string $label, string $repository): array
    {
        $labelEscaped = str_replace('"', '\\"', $label);
        $queryTpl = 'search(query: "repo:PrestaShop/' . $repository . ' label:\"' . $labelEscaped . '\" is:pr is:merged", type: ISSUE, first: 100, after: %s) {
            pageInfo { endCursor hasNextPage }
            nodes {
                ... on PullRequest {
                    number
                    repository { name }
                    timelineItems(itemTypes: [LABELED_EVENT], first: 100) {
                        nodes {
                            ... on LabeledEvent {
                                actor { login }
                                label { name }
                                createdAt
                            }
                        }
                    }
                }
            }
        }';

        $events = [];
        $after = 'null';
        do {
            $data = $this->github->apiSearchGraphQL('query { ' . sprintf($queryTpl, $after) . ' }');
            $search = $data['data']['search'] ?? null;
            if ($search === null) {
                break;
            }
            foreach ($search['nodes'] as $pr) {
                if (empty($pr['number'])) {
                    continue;
                }
                foreach ($pr['timelineItems']['nodes'] as $ev) {
                    if (($ev['label']['name'] ?? null) !== $label) {
                        continue;
                    }
                    if (empty($ev['actor']['login'])) {
                        continue;
                    }
                    $events[] = [
                        'repo' => $pr['repository']['name'],
                        'pr_number' => $pr['number'],
                        'actor' => $ev['actor']['login'],
                        'label' => $label,
                        'createdAt' => $ev['createdAt'],
                    ];
                }
            }
            $endCursor = $search['pageInfo']['endCursor'] ?? null;
            $after = $endCursor === null ? 'null' : '"' . $endCursor . '"';
        } while (($search['pageInfo']['hasNextPage'] ?? false) === true);

        return $events;
    }

    /**
     * @param array<int, array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}> $events
     *
     * @return array<string, array{repo:string, pr_number:int, actor:string, label:string, createdAt:string}>
     */
    private function dedup(array $events): array
    {
        $keyed = [];
        foreach ($events as $ev) {
            $key = $ev['repo'] . '#' . $ev['pr_number'] . '@' . $ev['actor'] . '|' . $ev['label'];
            if (!isset($keyed[$key]) || strcmp($ev['createdAt'], $keyed[$key]['createdAt']) < 0) {
                $keyed[$key] = $ev;
            }
        }

        return $keyed;
    }
}
