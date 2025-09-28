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
    ],
    'variables' => [
        'user' => [
            'type' => 'String',
            'name' => 'User Name',
            'profile' => '',
            'position' => '1',
            'enableAction' => false,
        ],
        'serial' => [
            'type' => 'String',
            'name' => 'Serial Number',
            'profile' => '',
            'position' => '2',
            'enableAction' => false,
        ],
        'access' => [
            'type' => 'String',
            'name' => 'Access Level',
            'profile' => '',
            'position' => '3',
            'enableAction' => false,
        ],
        'issue' => [
            'type' => 'String',
            'name' => 'Token Issue',
            'profile' => '',
            'position' => '4',
            'enableAction' => false,
        ],
        'expiration' => [
            'type' => 'String',
            'name' => 'Token Expiration',
            'profile' => '',
            'position' => '5',
            'enableAction' => false,
        ],
    ],
    'profiles' => [
        'ENPHASE.UpdateInterval' => [
            'type' => 'Integer',
            'icon' => 'Clock',
            'suffix' => ' s',
        ],
    ],
];
