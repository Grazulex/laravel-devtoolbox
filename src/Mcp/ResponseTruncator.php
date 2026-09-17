<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Mcp;

/**
 * Keeps scanner results within a byte budget so they fit in an agent's context.
 *
 * Strategy: locate the collection that carries the bulk of the result (the
 * first list at the top level; otherwise descend into the dominant array —
 * typically the `data` block of the scanner envelope — until a list or a
 * keyed map of homogeneous items is found), keep the largest prefix of that
 * collection that fits, null out its sibling arrays, keep every scalar and
 * every ancestor sibling (e.g. `metadata`) untouched, and add a top-level
 * "_truncated" note telling the agent where the cut happened and how to
 * refine the scan.
 */
final class ResponseTruncator
{
    private const int MAX_DEPTH = 8;

    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

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

        $path = $this->locate($result, 0);
        $collection = $path === null ? [] : $this->valueAt($result, $path);
        $originalItems = count($collection);

        $note = [
            'original_items' => $originalItems,
            'kept_items' => 0,
            'path' => match (true) {
                $path === null => null,
                $path === [] => '(root)',
                default => implode('.', $path),
            },
            'hint' => $optionSchema === []
                ? 'No options available to refine this scan'
                : 'Refine with options: '.implode(', ', array_keys($optionSchema)),
        ];

        if ($path === null) {
            return $this->pruneLevel($result, null, null) + ['_truncated' => $note];
        }

        $low = 0;
        $high = $originalItems;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            $candidate = $this->withCollection($result, $path, array_slice($collection, 0, $mid, true));
            $candidate['_truncated'] = array_replace($note, ['kept_items' => $mid]);

            if ($this->size($candidate) <= $this->maxBytes) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        $kept = $this->withCollection($result, $path, array_slice($collection, 0, $low, true));
        $kept['_truncated'] = array_replace($note, ['kept_items' => $low]);

        return $kept;
    }

    /**
     * Path (list of keys) to the collection to slice, or null when nothing is sliceable.
     *
     * An empty path means the node itself is a keyed collection.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<array-key>|null
     */
    private function locate(array $node, int $depth): ?array
    {
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        foreach ($node as $key => $value) {
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                return [$key];
            }
        }

        $sizes = [];
        $hasScalar = false;

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $sizes[$key] = $this->size($value);
                }
            } else {
                $hasScalar = true;
            }
        }

        if ($sizes === []) {
            return null;
        }

        arsort($sizes);
        $largestKey = array_key_first($sizes);
        $largestSize = $sizes[$largestKey];

        // A keyed map of homogeneous items (tables, classes, middleware...) has no scalar
        // siblings and no single dominant entry; a record (envelope, data block) does.
        if (! $hasScalar && count($sizes) >= 2 && $largestSize * 2 <= array_sum($sizes)) {
            return [];
        }

        /** @var array<array-key, mixed> $child */
        $child = $node[$largestKey];

        return [$largestKey, ...($this->locate($child, $depth + 1) ?? [])];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<array-key>  $path
     * @return array<array-key, mixed>
     */
    private function valueAt(array $node, array $path): array
    {
        foreach ($path as $key) {
            /** @var array<array-key, mixed> $node */
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * Replaces the collection at $path, nulling its sibling arrays and keeping
     * everything above untouched.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<array-key>  $path
     * @param  array<array-key, mixed>  $collection
     * @return array<array-key, mixed>
     */
    private function withCollection(array $node, array $path, array $collection): array
    {
        if ($path === []) {
            return $collection;
        }

        $key = array_shift($path);

        if ($path === []) {
            return $this->pruneLevel($node, $key, $collection);
        }

        /** @var array<array-key, mixed> $child */
        $child = $node[$key];
        $node[$key] = $this->withCollection($child, $path, $collection);

        return $node;
    }

    /**
     * Keeps scalars, replaces $targetKey with $collection and nulls the other arrays.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<array-key, mixed>|null  $collection
     * @return array<array-key, mixed>
     */
    private function pruneLevel(array $node, string|int|null $targetKey, ?array $collection): array
    {
        $pruned = [];

        foreach ($node as $key => $value) {
            if ($targetKey !== null && $key === $targetKey) {
                $pruned[$key] = $collection;
            } elseif (is_scalar($value) || $value === null) {
                $pruned[$key] = $value;
            } else {
                $pruned[$key] = null;
            }
        }

        return $pruned;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function size(array $data): int
    {
        return mb_strlen((string) json_encode($data, self::ENCODE_FLAGS), '8bit');
    }
}
