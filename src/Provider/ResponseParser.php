<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Provider;

use SpacedTracker\Ai\Exception\IntegrationException;

final class ResponseParser
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function text(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text'])) {
            return $payload['output_text'];
        }

        if (isset($payload['output']) && is_array($payload['output'])) {
            $chunks = [];
            foreach ($payload['output'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $part) {
                        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                            $chunks[] = $part['text'];
                        }
                    }
                }
            }
            if ($chunks !== []) {
                return implode('', $chunks);
            }
        }

        if (isset($payload['choices'][0]) && is_array($payload['choices'][0])) {
            $choice = $payload['choices'][0];
            if (isset($choice['message']['content']) && is_string($choice['message']['content'])) {
                return $choice['message']['content'];
            }
            if (isset($choice['text']) && is_string($choice['text'])) {
                return $choice['text'];
            }
        }

        if (isset($payload['content'][0]['text']) && is_string($payload['content'][0]['text'])) {
            return $payload['content'][0]['text'];
        }

        if (isset($payload['text']) && is_string($payload['text'])) {
            return $payload['text'];
        }

        throw new IntegrationException('The AI response did not include text.');
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function delta(array $event): ?string
    {
        if (isset($event['delta']) && is_string($event['delta'])) {
            return $event['delta'];
        }
        if (isset($event['choices'][0]['delta']['content']) && is_string($event['choices'][0]['delta']['content'])) {
            return $event['choices'][0]['delta']['content'];
        }

        return null;
    }
}
