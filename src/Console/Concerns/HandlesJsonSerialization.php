<?php

declare(strict_types=1);

namespace Grazulex\LaravelDevtoolbox\Console\Concerns;

use BadMethodCallException;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Error;
use Illuminate\Support\Facades\Log;
use JsonSerializable;
use Throwable;

trait HandlesJsonSerialization
{
    /**
     * Safely encode data to JSON with error handling.
     */
    protected function safeJsonEncode(array $data, int $flags = JSON_PRETTY_PRINT): string
    {
        // First, clean the data to ensure it's JSON-serializable
        $cleanedData = $this->makeJsonSerializable($data);

        // Attempt JSON encoding
        $json = json_encode($cleanedData, $flags);

        if ($json === false) {
            $error = json_last_error_msg();
            Log::warning("JSON encoding failed in command: {$error}", [
                'command' => static::class,
                'data_keys' => array_keys($data),
            ]);

            // Return a fallback error response
            return json_encode([
                'error' => 'JSON serialization failed',
                'message' => $error,
                'command' => class_basename(static::class),
                'timestamp' => now()->toISOString(),
            ], JSON_PRETTY_PRINT) ?: '{"error": "Critical JSON serialization failure"}';
        }

        return $json;
    }

    /**
     * Clean data to make it JSON-serializable by removing/converting problematic types.
     *
     * @param  mixed  $data
     * @return mixed
     *
     * @phpstan-ignore-next-line
     */
    protected function makeJsonSerializable($data)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Ensure key is serializable (string or int)
                /** @phpstan-ignore-next-line */
                if (is_string($key) || is_int($key)) {
                    $result[$key] = $this->makeJsonSerializable($value);
                }
            }

            return $result;
        }

        if (is_object($data)) {
            // Handle specific object types
            if ($data instanceof DateTimeImmutable || $data instanceof DateTimeInterface) {
                return $data->format('Y-m-d H:i:s');
            }

            if ($data instanceof Closure) {
                return '[Closure]';
            }

            if ($data instanceof JsonSerializable) {
                return $this->makeJsonSerializable($data->jsonSerialize());
            }

            // Try to convert object to array, handling potential exceptions
            try {
                if (method_exists($data, 'toArray')) {
                    return $this->makeJsonSerializable($data->toArray());
                }

                if (method_exists($data, '__toString')) {
                    return (string) $data;
                }

                // Convert object to array as last resort
                return $this->makeJsonSerializable(json_decode(json_encode($data), true) ?: []);
            } catch (Throwable $e) {
                return '[Object: '.get_class($data).']';
            }
        }

        if (is_resource($data)) {
            return '[Resource: '.get_resource_type($data).']';
        }

        // Handle other problematic values
        if (is_float($data) && (is_nan($data) || is_infinite($data))) {
            return null;
        }

        // For scalar values, return as-is
        return $data;
    }

    /**
     * Output JSON safely to the console or file.
     */
    protected function outputJson(array $data, ?string $outputFile = null): void
    {
        $json = $this->safeJsonEncode($data);

        if (! in_array($outputFile, [null, '', '0'], true)) {
            file_put_contents($outputFile, $json);
            // Show info message for non-JSON format (only for command instances with Laravel console methods)
            try {
                /** @phpstan-ignore-next-line */
                if ($this->hasOption('format') && $this->option('format') !== 'json') {
                    /** @phpstan-ignore-next-line */
                    $this->info("Output saved to: {$outputFile}");
                }
            } catch (BadMethodCallException|Error $e) {
                // Silently ignore if methods don't exist (e.g., in test context)
            }
        } else {
            try {
                /** @phpstan-ignore-next-line */
                $this->line($json);
            } catch (BadMethodCallException|Error $e) {
                // Fallback for test contexts where line() method doesn't exist
                echo $json.PHP_EOL;
            }
        }
    }
}
