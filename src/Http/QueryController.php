<?php

declare(strict_types=1);

namespace Medyat\Laraql\Http;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Medyat\Laraql\Context;
use Medyat\Laraql\Exceptions\InvalidRequestException;
use Medyat\Laraql\Exceptions\LaraqlException;
use Medyat\Laraql\Laraql;

/**
 * POST {laraql.route.path}: `{ queries: { key: Query } }` → `{ results: { key: Result | { error } } }`.
 * Only an unusable envelope (no queries, bad key, unknown timezone) changes
 * the HTTP status; per-query problems live inside `results`.
 */
final class QueryController
{
    public function __invoke(Request $request, Laraql $laraql): JsonResponse
    {
        try {
            $queries = $this->queries($request);
            $context = new Context($this->timezone($request), CarbonImmutable::now(), $request->user());
        } catch (InvalidRequestException $e) {
            return new JsonResponse($e->toArray(), $e->status, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['results' => $laraql->run($queries, $context)]);
    }

    /** @return array<string, mixed> */
    private function queries(Request $request): array
    {
        $queries = $request->json('queries');

        if (! is_array($queries) || $queries === [] || array_is_list($queries)) {
            throw new InvalidRequestException(['queries' => [LaraqlException::message('queries_required')]]);
        }

        foreach (array_keys($queries) as $key) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', (string) $key) !== 1) {
                throw new InvalidRequestException(['queries' => [LaraqlException::message('invalid_query_key', ['key' => (string) $key])]]);
            }
        }

        $max = config('laraql.limits.queries_per_request');

        if (is_int($max) && count($queries) > $max) {
            throw new InvalidRequestException(['queries' => [LaraqlException::message('limit_exceeded', ['field' => 'queries', 'max' => $max])]]);
        }

        /** @var array<string, mixed> $queries */
        return $queries;
    }

    private function timezone(Request $request): string
    {
        $header = config('laraql.timezone.header');
        $timezone = $request->header(is_string($header) ? $header : 'X-Timezone');

        if ($timezone === null || $timezone === '') {
            $fallback = config('app.timezone');

            return is_string($fallback) && $fallback !== '' ? $fallback : 'UTC';
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidRequestException(['timezone' => [LaraqlException::message('invalid_timezone', ['timezone' => $timezone])]]);
        }

        return $timezone;
    }
}
