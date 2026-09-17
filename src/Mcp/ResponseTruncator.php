<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

/**
 * Keeps scanner results within a byte budget so they fit in an agent's context.
 *
 * Strategy: keep top-level scalars, keep the largest prefix of the first
 * top-level list that fits, null out other top-level arrays, and add a
 * "_truncated" note telling the agent how to refine the scan.
 */
final class ResponseTruncator
{
    public function __construct(private readonly int $maxBytes) {}

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, array{type: string, description: string}>  $optionSchema
     * @return array<string, mixed>
     */
    public function truncate(array $result, array $optionSchema): array
    {
        if ($this->size($result) <= $this->maxBytes) {
            return $result;
        }

        $listKey = null;
        $kept = [];

        foreach ($result as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $kept[$key] = $value;

                continue;
            }

            if ($listKey === null && is_array($value) && array_is_list($value)) {
                $listKey = $key;
                $kept[$key] = [];

                continue;
            }

            $kept[$key] = null;
        }

        $originalItems = $listKey === null ? 0 : count($result[$listKey]);
        $note = [
            'original_items' => $originalItems,
            'kept_items' => 0,
            'hint' => $optionSchema === []
                ? 'No options available to refine this scan'
                : 'Refine with options: '.implode(', ', array_keys($optionSchema)),
        ];
        $kept['_truncated'] = $note;

        if ($listKey === null) {
            return $kept;
        }

        $low = 0;
        $high = $originalItems;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            $candidate = $kept;
            $candidate[$listKey] = array_slice($result[$listKey], 0, $mid);
            $candidate['_truncated']['kept_items'] = $mid;

            if ($this->size($candidate) <= $this->maxBytes) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        $kept[$listKey] = array_slice($result[$listKey], 0, $low);
        $kept['_truncated']['kept_items'] = $low;

        return $kept;
    }

    private function size(array $data): int
    {
        return mb_strlen((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '8bit');
    }
}
