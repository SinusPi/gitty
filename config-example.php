<?php

declare(strict_types=1);

return [
    'repo_roots' => [
        [
            'path' => '/home/user/repos',
            'slug' => 'personal',
            'name' => 'Personal Repos',
            'description' => 'Primary bare repositories used for daily development.',
        ],
        [
            'path' => '/home/user/more-repos',
            'slug' => 'archive',
            'name' => 'Archive Repos',
            'description' => 'Older or less frequently accessed bare repositories.',
        ],
    ],
    'repo_meta' => [
        'overrides' => [
            // Structured format:
            [
                'root' => 'personal', // slug or numeric order index
                'path' => 'project-a/service-api',
                'options' => [
                    'display_name' => 'Service API',
                    'owner' => 'platform-team',
                ],
            ],

            // Shorthand format:
            'archive/legacy/old-tooling' => [
                'hidden' => true,
                'note' => 'Kept for historical reference.',
            ],
        ],
    ],
];
