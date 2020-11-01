<?php

return [
    'frontend' => [
        'webian/visualedit/initiator' => [
            'target' => \Webian\Visualedit\Middleware\FrontendEditInitiator::class,
            'after' => [
                'typo3/cms-frontend/page-resolver',
            ]
        ],
    ]
];
