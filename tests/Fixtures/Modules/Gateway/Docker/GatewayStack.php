<?php

declare(strict_types=1);

namespace Mozex\Compose\Tests\Fixtures\Modules\Gateway\Docker;

use Illuminate\Support\Facades\Config;
use Mozex\Compose\Stack;

class GatewayStack extends Stack
{
    public function environment(): array
    {
        return [
            'GATEWAY_SECRET' => '0123456789abcdef0123456789abcdef',
            'GATEWAY_DOMAIN' => 'rdp.example.test',
        ];
    }

    public function profiles(): array
    {
        return Config::boolean('fixtures.gateway.tls', false) ? ['tls'] : [];
    }
}
