<?php

return [
    'neo4j' => [
        'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD', 'neo4jtest'),
        'database' => env('NEO4J_DATABASE', 'neo4j'),
    ],

    'whisper' => [
        'model' => env('WHISPER_MODEL', 'whisper-1'),
        'max_file_bytes' => env('WHISPER_MAX_FILE_BYTES', 25 * 1024 * 1024),
    ],

    'decay' => [
        'rate' => env('GRAPH_DECAY_RATE', 0.02),
        'not_reinforced_days' => env('GRAPH_DECAY_NOT_REINFORCED_DAYS', 30),
    ],

    'extraction' => [
        'related_node_limit' => env('GRAPH_RELATED_LIMIT', 5),
    ],
];
