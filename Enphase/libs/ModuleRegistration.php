<?php

class ModuleRegistration {

    protected $allowedPrefixes = [
        'Property' => ['type', 'default'],
        'Attribute' => ['type', 'default'],
        'Variable' => ['type',  'name', 'profile', 'position'],
    ];

    protected $typeMapping = [
        'String'  => 3,
        'Float'   => 2,
        'Integer' => 1,
        'Boolean' => 0
    ];

    private $module;
    private $reflection;

    public function __construct($module) {
        $this->module = $module;
        $this->reflection = new ReflectionClass($module);
    }

    public function Register($config) {
        if (! is_array($config)) {
            throw new Exception("Invalid module configuration. Expected array.");
        }
        if (isset($config['properties'])) {
            $this->RegisterProperties($config['properties']);
        }
        if (isset($config['attributes'])) {
            $this->RegisterAttributes($config['attributes']);
        }
        if (isset($config['profiles'])) {
            $this->RegisterProfiles($config['profiles']);
        }
        if (isset($config['variables'])) {
            $this->RegisterVariables($config['variables']);
        }
    }

    public function RegisterProperties($properties) {
        if (! is_array($properties)) {
            throw new Exception("Invalid properties configuration. Expected array.");
        }
        $this->registerItems('Property', $properties);
    }
    
    public function RegisterAttributes($attributes) {
        if (! is_array($attributes)) {
            throw new Exception("Invalid attributes configuration. Expected array.");
        }
        $this->registerItems('Attribute', $attributes);
    }
    
    public function RegisterVariables($variables) {
        if (! is_array($variables)) {
            throw new Exception("Invalid variables configuration. Expected array.");
        }
        $this->registerItems('Variable', $variables);
    }

    public function DeleteProfiles($profiles) {
        if (! $this->isLastInstance()) {
            return;
        }

        if (! is_array($profiles)) {
            throw new Exception("Invalid profiles configuration. Expected array.");
        }

        foreach (array_keys($profiles) as $profileName) {
            if (IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }
        }
    }

    public function checkTypeConsistency($config) {
        $variables = $config['variables'] ?? [];
        $profiles = $config['profiles'] ?? [];
        $inconsistencies = [];
    
        foreach ($variables as $varName => $varAttributes) {
            $varType = $varAttributes['type'];
            $profileName = $varAttributes['profile'];
    
            if (isset($profiles[$profileName])) {
                $profileType = $profiles[$profileName]['type'];
    
                if ($varType !== $profileType) {
                    $inconsistencies[] = [
                        'variable' => $varName,
                        'variableType' => $varType,
                        'profile' => $profileName,
                        'profileType' => $profileType,
                    ];
                }
            }
        }
        return $inconsistencies;
    }                

    protected function isLastInstance() {
        $property = $this->reflection->getProperty('InstanceID');
        $property->setAccessible(true);
        $instanceId = $property->getValue($this->module);
        $moduleInfo = IPS_GetModule($instanceId);
        $moduleId = $moduleInfo['ModuleID'];
        $instances = IPS_GetInstanceListByModuleID($moduleId);
        return count($instances) === 1;
    }

    public function RegisterProfiles($profiles, $updateExisting = true) {
		
        if (empty($profiles)) return;

		foreach ($profiles as $profileName => $settings) {
			$type = $this->typeMapping[$settings['type']] ?? null;
	
			if (is_null($type)) {
                throw new Exception("Unsupported profile type: " . $settings['type']);
				continue;
			}
	
			$profileExists = IPS_VariableProfileExists($profileName);
	
			// If the profile exists and we are not updating, skip
			if ($profileExists && !$updateExisting) {
				continue;
			}
			
			// If the profile doesn't exist, create it
			if (!$profileExists) {
				IPS_CreateVariableProfile($profileName, $type);
			}
			
			// Update the common settings
			IPS_SetVariableProfileIcon($profileName, $settings['icon']);
			
			// The text suffix is valid for all types except Boolean
			if ($type !== 0 && isset($settings['suffix'])) {
				IPS_SetVariableProfileText($profileName, "", $settings['suffix']);
			}
	
			// These are valid for Float and Integer types
			if (in_array($type, [1, 2]) && isset($settings['minValue'])) {
				IPS_SetVariableProfileValues($profileName, $settings['minValue'], $settings['maxValue'] ?? 0, $settings['stepSize'] ?? ($type === 2 ? 0.1 : 1));
			}
			
			// ActionScript is valid for all types
			if (isset($settings['actionScript'])) {
				IPS_SetVariableProfileAction($profileName, $settings['actionScript']);
			}
	
			// Association is valid for all types
			if (isset($settings['associations'])) {
				foreach ($settings['associations'] as $association) {
					IPS_SetVariableProfileAssociation($profileName, $association['value'], $association['text'], $association['icon'], $association['color']);
				}
			}
			
			// Digital formatting is only valid for Float
			if ($type === 2 && isset($settings['digits'])) {
				IPS_SetVariableProfileDigits($profileName, $settings['digits']);
			}
		}
	}

    private function validateItem($typePrefix, $item) {
        // Check if the type prefix is valid
        if (!array_key_exists($typePrefix, $this->allowedPrefixes)) {
            throw new Exception("Invalid type prefix: " . $typePrefix);
        }
    
        // Check if the item is an array
        if (!is_array($item)) {
            throw new Exception("Invalid item format. Each item should be an array.");
        }
    
        // Ensure the item has all the required keys for its type prefix
        foreach ($this->allowedPrefixes[$typePrefix] as $requiredKey) {
            if (!isset($item[$requiredKey])) {
                throw new Exception("Missing key {$requiredKey} for {$typePrefix} item.");
            }
        }
    }
    
    private function registerItems($typePrefix, $items) {

        if (empty($items)) return;

        foreach ($items as $ident => $item) {
            // Validate each item
            $this->validateItem($typePrefix, $item);
    
            // Construct the function name based on the type
            $methodName = 'Register' . $typePrefix . $item['type'];
    
            // Check if the method exists
            if (method_exists($this->module, $methodName)) {
                // Based on type prefix, decide which parameters to pass
                switch ($typePrefix) {
                    case 'Property':
                    case 'Attribute':
                        $this->invoke($methodName, [ $ident, $item['default']]);
                        break;
                    case 'Variable':
                        $this->invoke($methodName, [ $ident, $item['name'], $item['profile'], $item['position']]);
                        if (isset($item['enableAction']) && $item['enableAction']) {
                            $this->invoke('EnableAction', [ $ident ]);
                        } else {
                            $this->invoke('DisableAction', [ $ident ]);
                        }
                        if (isset($item['default']))
                            $this->invoke('SetValue', [ $ident, $item['default'] ]);
                        break;
                }
            } else {
                throw new Exception("Unsupported " . strtolower($typePrefix) . " type: " . $item['type']);
            }
        }
    }

    private function invoke($method, $params = []) {
        $method = $this->reflection->getMethod($method);
        $method->setAccessible(true);
        $result = $method->invokeArgs($this->module, $params);
        return $result;
    }
}
    