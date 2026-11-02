<?php

declare(strict_types=1);

return [
    'titles' => [
        'invalid_request' => 'Invalid request',
        'invalid_query' => 'Invalid query',
        'unknown_source' => 'Unknown source',
        'forbidden' => 'Forbidden',
    ],
    'invalid_request' => 'The request is invalid.',
    'invalid_query' => 'The query is invalid.',
    'forbidden' => 'This action is unauthorized.',
    'queries_required' => 'At least one query is required.',
    'invalid_query_key' => 'Query key ":key" is invalid.',
    'invalid_timezone' => 'Unknown timezone ":timezone".',
    'unknown_key' => 'Unknown key ":key".',
    'invalid_value' => 'Invalid value for ":field".',
    'unknown_source' => 'Unknown source ":source".',
    'unknown_column' => 'Unknown column ":column" on source ":source".',
    'column_not_filterable' => 'Column ":column" cannot be filtered.',
    'column_not_sortable' => 'Column ":column" cannot be sorted.',
    'operator_not_allowed' => 'Operator ":op" is not allowed on column ":column" (:type).',
    'unknown_option' => 'Unknown option ":option" on source ":source".',
    'unknown_param' => 'Unknown parameter ":param" on source ":source".',
    'unknown_relation' => 'Unknown relation ":relation" on source ":source".',
    'include_too_deep' => 'Includes may be nested at most :depth level(s) deep.',
    'limit_exceeded' => 'Value for ":field" exceeds the limit of :max.',
    'me_requires_user' => '"@me" needs an authenticated user.',
    'search_not_supported' => 'Source ":source" has no searchable columns.',
];
