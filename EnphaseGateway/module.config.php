<?php

return [
    'properties' => [
        'host' => [
            'type' => 'String',
            'default' => 'https://envoy.local',
        ],
        'user' => [
            'type' => 'String',
            'default' => '',
        ],
        'password' => [
            'type' => 'String',
            'default' => '',
        ],
        'serial' => [
            'type' => 'String',
            'default' => '',
        ],
        'updateInterval' => [
            'type' => 'Integer',
            'default' => '5',
        ],
        'enabled' => [
            'type' => 'Boolean',
            'default' => 'true',
        ],
    ],
    'attributes' => [
        'token' => [
            'type' => 'String',
            'default' => '',
        ],
        'pvdata' => [
            'type' => 'String',
            'default' => '[]',
        ],
        'token_serial' => [
            'type' => 'String',
            'default' => '',
        ],
        'token_user' => [
            'type' => 'String',
            'default' => '',
        ],
        'token_access' => [
            'type' => 'String',
            'default' => '',
        ],
        'token_issue' => [
            'type' => 'String',
            'default' => '',
        ],
        'token_expiration' => [
            'type' => 'String',
            'default' => '',
        ],
    ],
    'variables' => [
    ],
    'profiles' => [
        'ENPHASE.UpdateInterval' => [
            'type' => 'Integer',
            'icon' => 'Clock',
            'suffix' => ' s',
        ],
    ],
];
