<?php

declare(strict_types=1);

namespace Medyat\Laraql\Query;

use Closure;
use Medyat\Laraql\Column\Column;

/** A match column of a validated query: the column and the normalizer the source gave it. */
final readonly class MatchColumn
{
    /** @param  (Closure(string): ?string)|null  $normalize */
    public function __construct(
        public Column $column,
        public ?Closure $normalize,
    ) {}

    /**
     * Each text's value in this column, keyed by the text; texts the normalizer rejects are left out.
     *
     * @param  list<string>  $texts
     * @return array<string, string>
     */
    public function values(array $texts): array
    {
        $values = [];

        foreach ($texts as $text) {
            $value = $this->normalize === null ? $text : ($this->normalize)($text);

            if ($value !== null) {
                $values[$text] = $value;
            }
        }

        return $values;
    }
}
