<?php

declare(strict_types=1);

namespace Medyat\Laraql;

use Medyat\Laraql\Exceptions\ForbiddenException;
use Medyat\Laraql\Exceptions\InvalidQueryException;
use Medyat\Laraql\Exceptions\LaraqlException;
use Medyat\Laraql\Query\Executor;
use Medyat\Laraql\Query\Validator;
use Medyat\Laraql\Source\Registry;
use Medyat\Laraql\Support\Raw;

/**
 * Entry point: runs a batch of raw queries and returns the results map.
 * Every query is resolved, authorized, validated and executed on its own;
 * a LaraqlException becomes that key's `error`, nothing else is caught.
 */
final readonly class Laraql
{
    public function __construct(
        private Registry $registry,
        private Validator $validator,
        private Executor $executor,
    ) {}

    /**
     * @param  array<string, mixed>  $queries
     * @return array<string, array<string, mixed>>
     */
    public function run(array $queries, Context $context): array
    {
        $results = [];

        foreach ($queries as $key => $raw) {
            try {
                $results[(string) $key] = $this->one(Raw::assoc($raw), $context);
            } catch (LaraqlException $e) {
                $results[(string) $key] = ['error' => $e->toArray()];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function one(array $raw, Context $context): array
    {
        $name = $raw['source'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidQueryException(['source' => [LaraqlException::message('invalid_value', ['field' => 'source'])]]);
        }

        $source = $this->registry->get($name);

        if (! $source->authorize($context)) {
            throw new ForbiddenException;
        }

        $query = $this->validator->validate($raw, $source, $context);

        $result = $query->kind === 'count'
            ? ['total' => $this->executor->count($query, $context)]
            : $this->executor->rows($query, $context);

        return $query->ignored === [] ? $result : $result + ['ignored' => $query->ignored];
    }

    public function registry(): Registry
    {
        return $this->registry;
    }
}
