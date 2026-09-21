<?php

declare(strict_types=1);

namespace LuziApi\Loyalty\Infrastructure\WordPress;

use LuziApi\Loyalty\Domain\Gateway\IdGenerator;

final readonly class WordPressIdGenerator implements IdGenerator
{
    public function newId(): string
    {
        return wp_generate_uuid4();
    }
}
