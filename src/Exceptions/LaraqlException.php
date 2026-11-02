<?php

declare(strict_types=1);

namespace Medyat\Laraql\Exceptions;

use RuntimeException;

/**
 * A failure rendered as an RFC 9457 problem object: under `error` inside the
 * results envelope, or as the whole body for an envelope error (docs/protocol.md · Errors).
 */
class LaraqlException extends RuntimeException
{
    /** @param  array<string, list<string>>  $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public const string TYPE_BASE = 'https://github.com/medyat/laraql/blob/production/docs/protocol.md#';

    /** @return array{type: string, title: string, status: int, detail: string, errors?: array<string, list<string>>} */
    public function toArray(): array
    {
        $problem = [
            'type' => self::TYPE_BASE.$this->errorCode,
            'title' => self::message('titles.'.$this->errorCode),
            'status' => $this->status,
            'detail' => $this->getMessage(),
        ];

        if ($this->details !== []) {
            $problem['errors'] = $this->details;
        }

        return $problem;
    }

    /** @param  array<string, scalar>  $params */
    public static function message(string $key, array $params = []): string
    {
        $text = __('laraql::messages.'.$key, $params);

        return is_string($text) ? $text : $key;
    }
}
