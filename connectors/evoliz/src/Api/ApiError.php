<?php

namespace Passorde\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * What went wrong with a call, on one line.
 */
final readonly class ApiError
{
    private const RAW_BODY_LIMIT = 200;

    /**
     * @param  int|null  $status  Null when no response came back.
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(
        public ?int $status,
        public string $message,
        public array $fieldErrors = [],
        public ?int $retryAfter = null,
    ) {}

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $body = json_decode($response->body(), true);
        $fieldErrors = [];
        $detail = null;

        if (is_array($body)) {
            [$detail, $fieldErrors] = self::parseMessage($body['message'] ?? null);
            $detail ??= self::parseError($body['error'] ?? null);
        }

        $detail ??= self::oneLine($response->body());
        $prefix = 'HTTP '.$status;

        return new self(
            $status,
            $detail === '' ? $prefix : $prefix.' — '.$detail,
            $fieldErrors,
            RetryAfter::seconds($response),
        );
    }

    public static function connectionFailure(string $reason): self
    {
        return new self(null, 'Connexion à Evoliz impossible — '.self::oneLine($reason));
    }

    public function isRetryable(): bool
    {
        return $this->status === null || $this->status === 429 || $this->status >= 500;
    }

    /**
     * @return array{0: string|null, 1: array<string, list<string>>}
     */
    private static function parseMessage(mixed $message): array
    {
        if (is_string($message)) {
            return [trim($message) === '' ? null : self::oneLine($message), []];
        }
        if (! is_array($message) || $message === []) {
            return [null, []];
        }

        $fieldErrors = [];
        $parts = [];

        foreach ($message as $field => $messages) {
            $lines = array_values(array_map(
                fn (mixed $line): string => is_scalar($line) ? self::oneLine((string) $line) : self::oneLine((string) json_encode($line)),
                is_array($messages) ? $messages : [$messages],
            ));

            if (is_string($field)) {
                $fieldErrors[$field] = $lines;
                $parts[] = $field.' : '.implode(', ', $lines);
            } else {
                $parts[] = implode(', ', $lines);
            }
        }

        return [implode(' ; ', $parts), $fieldErrors];
    }

    private static function parseError(mixed $error): ?string
    {
        if (is_string($error) && trim($error) !== '') {
            return self::oneLine($error);
        }

        return is_array($error) && $error !== [] ? self::oneLine((string) json_encode($error)) : null;
    }

    private static function oneLine(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), self::RAW_BODY_LIMIT, '…');
    }
}
