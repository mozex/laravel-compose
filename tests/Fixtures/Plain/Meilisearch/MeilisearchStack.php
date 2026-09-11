<?php

declare(strict_types=1);

namespace Mozex\Compose\Tests\Fixtures\Plain\Meilisearch;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class MeilisearchStack extends Stack
{
    public function environment(): array
    {
        return [
            'MEILISEARCH_KEY' => Config::string('fixtures.meilisearch.key', 'fixture-master-key'),
            'MEILISEARCH_PORT' => 7700,
        ];
    }

    public function enabled(): bool
    {
        return Config::boolean('fixtures.meilisearch.enabled', true);
    }
}
