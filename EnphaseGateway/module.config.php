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
        'raw_production' => [
            'type' => 'String',
            'default' => '[]',
        ],
        'raw_ivp_ensemble_inventory' => [
            'type' => 'String',
            'default' => '[]',
        ],
        'raw_ivp_meters_readings' => [
            'type' => 'String',
            'default' => '[]',
        ],
        'raw_ivp_livedata_status' => [
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
        'energyTimestamp' => [
            'type' => 'String',
            'name' => 'Letztes Update',
            'profile' => '',
            'position' => 1,
            'enableAction' => false,
        ],
        'sourceVendor' => [
            'type' => 'String',
            'name' => 'Quelle - Hersteller',
            'profile' => '',
            'position' => 2,
            'enableAction' => false,
        ],
        'sourceModel' => [
            'type' => 'String',
            'name' => 'Quelle - Modell',
            'profile' => '',
            'position' => 3,
            'enableAction' => false,
        ],
        'pvPower' => [
            'type' => 'Float',
            'name' => 'PV Leistung',
            'profile' => 'ENPHASE.Power',
            'position' => 10,
            'enableAction' => false,
        ],
        'siteHousePower' => [
            'type' => 'Float',
            'name' => 'Hausverbrauch',
            'profile' => 'ENPHASE.Power',
            'position' => 20,
            'enableAction' => false,
        ],
        'siteGridPower' => [
            'type' => 'Float',
            'name' => 'Netzleistung',
            'profile' => 'ENPHASE.Power',
            'position' => 21,
            'enableAction' => false,
        ],
        'siteTotalConsumption' => [
            'type' => 'Float',
            'name' => 'Gesamtverbrauch',
            'profile' => 'ENPHASE.Power',
            'position' => 22,
            'enableAction' => false,
        ],
        'batterySoc' => [
            'type' => 'Float',
            'name' => 'Batterie SoC',
            'profile' => 'ENPHASE.Percentage',
            'position' => 30,
            'enableAction' => false,
        ],
        'batteryPower' => [
            'type' => 'Float',
            'name' => 'Batterie Leistung',
            'profile' => 'ENPHASE.Power',
            'position' => 31,
            'enableAction' => false,
        ],
        'batteryLedStatus' => [
            'type' => 'Integer',
            'name' => 'Batterie LED Status',
            'profile' => 'ENPHASE.LedStatus',
            'position' => 33,
            'enableAction' => false,
        ],
        'batteryStatus' => [
            'type' => 'String',
            'name' => 'Batterie Status',
            'profile' => 'ENPHASE.Status',
            'position' => 34,
            'enableAction' => false,
        ],
    ],
    'profiles' => [
        'ENPHASE.UpdateInterval' => [
            'type' => 'Integer',
            'icon' => 'Clock',
            'suffix' => ' s',
        ],
        'ENPHASE.Power' => [
            'type' => 'Float',
            'icon' => 'Electricity',
            'suffix' => ' W',
            'digits' => 0,
        ],
        'ENPHASE.Percentage' => [
            'type' => 'Float',
            'icon' => 'Battery',
            'suffix' => ' %',
            'digits' => 1,
        ],
        'ENPHASE.LedStatus' => [
            'type' => 'Integer',
            'icon' => 'Information',
            'associations' => [
                [
                    'value' => 12,
                    'text' => 'lädt',
                    'icon' => 'HollowArrowUp',
                    'color' => -1,
                ],
                [
                    'value' => 13,
                    'text' => 'entlädt',
                    'icon' => 'HollowArrowDown',
                    'color' => -1,
                ],
                [
                    'value' => 14,
                    'text' => 'voll',
                    'icon' => 'Ok',
                    'color' => -1,
                ],
                [
                    'value' => 15,
                    'text' => 'inaktiv',
                    'icon' => 'TurnLeft',
                    'color' => -1,
                ],
                [
                    'value' => 16,
                    'text' => 'inaktiv',
                    'icon' => 'TurnLeft',
                    'color' => -1,
                ],
                [
                    'value' => 17,
                    'text' => 'leer',
                    'icon' => 'HollowArrowDown',
                    'color' => -1,
                ],
            ],
        ],
        'ENPHASE.Status' => [
            'type' => 'String',
            'icon' => 'Information',
            'suffix' => '',
        ],
    ],
];
