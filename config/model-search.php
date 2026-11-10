<?php

declare(strict_types=1);

return [
    'table_prefix' => 'model_search_',

    // Column type of `subject_id` in the index tables: the key type of the searched models. `bigint` follows Laravel's
    // `id()`, `uuid` follows `HasUuids`, `text` holds any key (also models with different key types in one index).
    // A model whose key does not fit is refused when its definition is compiled. Set it before the tables are created:
    // the migration reads it, and an application that ships its own copy of the migration must use the same type.
    'subject_key_type' => 'bigint',

    // Whether the package runs its own migration. Turn it off when the application ships its own copy (published with
    // the `model-search-migrations` tag, or rewritten to its conventions).
    'run_migrations' => true,

    'driver' => 'postgres',
    'cache_store' => null,
    'partition_column' => null,
    'privacy' => 'refuse',
    'pool' => 200,
    'word_similarity_threshold' => 0.5,
    'search' => ['timeout_ms' => 300],
    'similar' => ['timeout_ms' => 1000],
    'name_words' => ['min_length' => 2, 'max_per_record' => 12],
    'each' => ['max_per_record' => 50],
    'identifier_rule' => ['digit_share' => 0.5],
    'drain' => [
        'rows' => 5000,
        'nodes' => 1000,
        'fan_out_limit' => 1000,
        'budget_ms' => 50000,
        'log_rebuild_threshold' => 1000000,
        'unknown_grace_seconds' => 3600,
        'foreign_grace_seconds' => 3600,
        'allowed_transaction_level' => 0,
    ],
    'apply' => ['max_ids' => 10000],
    'lag_alarm_seconds' => 300,
    'doctor' => ['timeout_ms' => 60000],
    'rate_limit' => ['max_attempts' => 30, 'decay_seconds' => 10],
];
