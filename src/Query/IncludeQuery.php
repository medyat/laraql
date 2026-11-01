<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query;

use Medyat\Laraql\Column\Column;
use Medyat\Laraql\Source\Relation;
use Medyat\Laraql\Source\Source;

/** A validated `include` entry: related rows loaded per parent row. */
final readonly class IncludeQuery
{
    /**
     * @param  array<string, Column>  $columns  The related source's columns for this request
     * @param  list<Column>|null  $fields  null = every column of the related source
     * @param  list<Condition>  $filters
     * @param  list<Sort>  $sort
     * @param  array<string, IncludeQuery>  $include
     */
    public function __construct(
        public string $name,
        public Relation $relation,
        public Source $source,
        public array $columns,
        public ?array $fields,
        public array $filters,
        public array $sort,
        public int $limit,
        public array $include,
    ) {}
}
