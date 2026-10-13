<?php

declare(strict_types=1);

namespace SpacedTracker\Ai\Analysis;

use SpacedTracker\Log\Redactor;

/**
 * Shrinks catalog rows and strips credential-shaped values before they are
 * sent to a model. Space-Track passwords never belong in a prompt.
 */
final class PromptSanitizer
{
    public function __construct(
        private readonly Redactor $redactor = new Redactor(),
        private readonly int $maxRecords = 20,
        private readonly int $maxString = 240,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function records(array $records): array
    {
        $clean = [];
        foreach (array_slice($records, 0, $this->maxRecords) as $record) {
            $redacted = $this->redactor->redact($record);
            if (!is_array($redacted)) {
                continue;
            }
            $row = [];
            foreach ($redacted as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $row[$key] = $this->shrink($value);
            }
            $clean[] = $row;
        }

        return $clean;
    }

    private function shrink(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > $this->maxString) {
            return substr($value, 0, $this->maxString) . '…';
        }
        if (is_array($value)) {
            return '[truncated]';
        }

        return $value;
    }
}
