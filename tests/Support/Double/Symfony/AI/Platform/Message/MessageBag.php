<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Message;

final class MessageBag
{
    /** @var list<object> */
    public array $messages;

    public function __construct(object ...$messages)
    {
        $this->messages = array_values($messages);
    }
}
