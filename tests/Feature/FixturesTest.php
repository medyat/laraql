<?php

declare(strict_types=1);

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

const LARAQL_SCHEMA_ID = 'https://github.com/medyat/laraql/schema/laraql-v1.schema.json';

/**
 * @return list<string>
 */
function laraqlFixtureFiles(): array
{
    $files = glob(__DIR__.'/../../fixtures/v1/*.json') ?: [];

    return array_values(array_filter($files, fn (string $file): bool => basename($file) !== 'dataset.json'));
}

function laraqlValidator(): Validator
{
    $validator = new Validator;
    $schema = json_decode((string) file_get_contents(__DIR__.'/../../schema/laraql-v1.schema.json'), false, 512, JSON_THROW_ON_ERROR);
    $validator->resolver()?->registerRaw($schema, LARAQL_SCHEMA_ID);

    return $validator;
}

function laraqlAssertValid(Validator $validator, mixed $data, string $definition): void
{
    $result = $validator->validate($data, LARAQL_SCHEMA_ID.'#/$defs/'.$definition);

    $errors = $result->error() ? (new ErrorFormatter)->format($result->error()) : [];

    expect($errors)->toBe([], "Does not match #/\$defs/{$definition}: ".json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

dataset('fixtures', fn (): array => array_combine(array_map(fn (string $f): string => basename($f, '.json'), laraqlFixtureFiles()), laraqlFixtureFiles()));

it('matches the fixture schema and names itself after its file', function (string $file): void {
    $fixture = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);

    laraqlAssertValid(laraqlValidator(), $fixture, 'Fixture');

    expect($fixture->name)->toBe(basename($file, '.json'));
})->with('fixtures');

it('carries a valid request envelope when it expects a 200 answer', function (string $file): void {
    $fixture = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);

    if ($fixture->response->status !== 200) {
        expect($fixture->request)->toBeObject();

        return;
    }

    laraqlAssertValid(laraqlValidator(), $fixture->request, 'Request');
    laraqlAssertValid(laraqlValidator(), $fixture->response->body, 'Response');
})->with('fixtures');

it('ships a dataset whose rows only use declared columns', function (): void {
    $dataset = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/v1/dataset.json'), true, 512, JSON_THROW_ON_ERROR);

    foreach ($dataset['rows'] as $source => $rows) {
        $declared = array_keys($dataset['sources'][$source]['columns']);

        foreach ($rows as $row) {
            expect(array_diff(array_keys($row), $declared))->toBe([], "Row in {$source} uses undeclared columns");
            expect($row)->toHaveKey($dataset['sources'][$source]['key']);
        }
    }
});
