<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Postgres\Session;

use JsonException;

/**
 * What the privacy gate concluded from the server settings: the settings that could write a search
 * phrase to a log, and the longest statement timeout that still stays below every log threshold.
 *
 * Only scalars: a verdict is cached as one JSON string.
 */
final readonly class PrivacyVerdict
{
    /** A search is stopped this long before the lowest logging threshold of the server. */
    public const int MARGIN_MS = 50;

    /**
     * @param  list<string>  $violations  One sentence per setting that can log a statement with its parameters.
     * @param  int|null  $limitMs  The longest safe statement timeout; null when no threshold limits it.
     */
    public function __construct(public array $violations = [], public ?int $limitMs = null) {}

    public function isSafe(): bool
    {
        return $this->violations === [];
    }

    /**
     * The configured timeout, never longer than the limit the server's logging thresholds leave.
     */
    public function effectiveTimeout(int $configuredMs): int
    {
        $timeout = max(1, $configuredMs);

        return $this->limitMs === null ? $timeout : max(1, min($timeout, $this->limitMs));
    }

    public function toScalar(): string
    {
        return json_encode(['violations' => $this->violations, 'limit' => $this->limitMs], JSON_THROW_ON_ERROR);
    }

    public static function fromScalar(mixed $scalar): ?self
    {
        if (! is_string($scalar)) {
            return null;
        }

        try {
            $data = json_decode($scalar, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['violations']) || ! is_array($data['violations']) || ! array_key_exists('limit', $data)) {
            return null;
        }

        $limit = $data['limit'];

        if ($limit !== null && ! is_int($limit)) {
            return null;
        }

        return new self(array_values(array_map(strval(...), $data['violations'])), $limit);
    }
}
