<?php

namespace Syonix\LogViewer;

use Symfony\Component\Yaml\Yaml;

/**
 * Abstracts the application configuration and includes a linter to validate the configuration.
 */
class Config
{
    protected $config;
    protected $configTree;

    /**
     * Config constructor.
     *
     * @param array|string $config Either an array or the file contents of the config file (yaml).
     */
    public function __construct($config)
    {
        if (is_array($config)) {
            $this->config = $config;
        } else {
            $this->config = $this->parse($config);
        }
    }

    /**
     * Lints a config file for syntactical and semantical correctness.
     *
     * @param array $config         The parsed configuration to lint
     * @param bool  $verifyLogFiles Also verfy whether the log files are accessible
     *
     * @return array
     */
    public static function lint($config, $verifyLogFiles = false)
    {
        $valid = true;
        $checks = [];

        // Valid YAML
        $checks['valid_yaml'] = [
            'message' => 'Is a valid YAML file',
        ];
        try {
            $config = self::parse($config);
            $checks['valid_yaml']['status'] = 'ok';
        } catch (\Exception $e) {
            $valid = false;
            $checks['valid_yaml']['status'] = 'fail';
            $checks['valid_yaml']['error'] = $e->getMessage();
        }
        try {
            // Valid structure
            $checks['valid_structure'] = self::lintValidProperties($config);
            if ($checks['valid_structure']['status'] == 'fail') {
                throw new \Exception();
            }

            // Valid config values
            $checks['valid_settings'] = self::lintValidSettingsValues($config);
            if ($checks['valid_settings']['status'] == 'fail') {
                throw new \Exception();
            }

            // Validate log collections (each)

            $checks['log_collections'] = [
                'message' => 'Checking log collections',
            ];
            try {
                foreach ($config['logs'] as $logCollectionName => $logCollection) {
                    $checks['log_collections']['sub_checks'][$logCollectionName] = self::lintLogCollection($logCollectionName, $logCollection);
                    if ($checks['log_collections']['sub_checks'][$logCollectionName]['status'] == 'fail') {
                        throw new \Exception();
                    }
                }
                foreach ($config['logs'] as $logCollectionName => $logCollection) {
                    $checks['log_collections']['checks'][$logCollectionName] = self::lintLogCollection($logCollectionName, $logCollection, $verifyLogFiles);
                    if ($checks['log_collections']['checks'][$logCollectionName]['status'] == 'fail') {
                        throw new \Exception();
                    }
                }
                $checks['log_collections']['status'] = 'ok';
            } catch (\Exception $e) {
                $checks['log_collections']['status'] = 'fail';
                $checks['log_collections']['error'] = $e->getMessage();
                $valid = false;
            }
        } catch (\Exception $e) {
            $valid = false;
        }

        return [
            'valid'  => $valid,
            'checks' => $checks,
        ];
    }

    protected static function lintValidProperties($config)
    {
        $return = [
            'message' => 'Structure is valid',
        ];
        try {
            $unknown = [];
            if (!array_key_exists('logs', $config)) {
                throw new \Exception('Config property "logs" is missing.');
            }

            foreach ($config as $property => $value) {
                if ($property == 'logs') {
                    $emptyCollections = [];
                    foreach ($value as $logCollectionKey => $logCollection) {
                        if (!empty($logCollection)) {
                            foreach ($logCollection as $logFileKey => $logFile) {
                                if (!array_key_exists('type', $logFile)) {
                                    throw new \Exception('Log file "'.$logCollectionKey.'.'
                                        .$logFileKey.'" has no type property.');
                                }
                            }
                        }
                    }
                    if (!empty($emptyCollections)) {
                        $return['status'] = 'warn';
                        $return['error'] = 'The following log collections have no logs: '.implode(', ', $emptyCollections);
                    }
                }
                if ($property != 'logs' && !array_key_exists($property, self::getValidSettings())) {
                    $unknown[] = $property;
                }
            }

            if (!isset($return['status'])) {
                if (!empty($unknown)) {
                    $return['status'] = 'warn';
                    $return['error'] = 'Unknown config properties: '.implode(', ', $unknown);
                } else {
                    $return['status'] = 'ok';
                }
            }
        } catch (\Exception $e) {
            $return['status'] = 'fail';
            $return['error'] = $e->getMessage();
        }

        return $return;
    }

    protected static function lintValidSettingsValues($config)
    {
        $return = [
            'message' => 'Settings values are valid',
        ];
        try {
            foreach ($config as $property => $value) {
                if ($property != 'logs') {
                    switch (self::getValidSettings($property)['type']) {
                        case 'bool':
                            if (!is_bool($value)) {
                                throw new \Exception('"'.$property.'" must be a boolean value.');
                            }
                            break;
                        case 'int':
                            if (!is_int($value)) {
                                throw new \Exception('"'.$property.'" must be an integer value.');
                            }
                            break;
                    }
                }
            }
            $return['status'] = 'ok';
        } catch (\Exception $e) {
            $return['status'] = 'fail';
            $return['error'] = $e->getMessage();
        }

        return $return;
    }

    protected static function lintLogCollection($name, $logCollection)
    {
        $return = [
            'message' => 'Checking "'.$name.'"',
        ];
        try {
            if (empty($logCollection)) {
                $return['status'] = 'warn';
                $return['error'] = '"'.$name.'" has no log files.';
            } else {
                foreach ($logCollection as $logFileName => $logFile) {
                    $return['sub_checks'][$logFileName] = self::lintLogFile($logFileName, $logFile);
                    if ($return['sub_checks'][$logFileName]['status'] == 'fail') {
                        throw new \Exception();
                    }
                }
                $return['status'] = 'ok';
            }
        } catch (\Exception $e) {
            $return['status'] = 'fail';
            $return['error'] = $e->getMessage();
        }

        return $return;
    }

    protected static function lintLogFile($name, $logFile, $verifyLogFiles = false)
    {
        $return = [
            'message' => 'Checking "'.$name.'"',
        ];
        try {
            if (!array_key_exists($logFile['type'], self::getValidLogTypes())) {
                throw new \Exception('"'.$logFile['type'].'" is not a supported type.');
            }
            if ($verifyLogFiles) {
                $return['checks'][$name] = self::lintCheckFileAccessible(new LogFile($name, null, $logFile));
                if ($return['checks'][$name]['status'] == 'fail') {
                    throw new \Exception();
                }
            }
            $return['status'] = 'ok';
        } catch (\Exception $e) {
            $return['status'] = 'fail';
            $return['error'] = $e->getMessage();
        }

        return $return;
    }

    protected static function lintCheckFileAccessible(LogFile $logFile)
    {
        $return = ['message' => 'Checking if "'.$logFile->getName().'" is accessible'];
        try {
            if (!LogFileCache::isSourceFileAccessible($logFile)) {
                throw new \Exception('File does not exist on target file system.');
            }
            $return['status'] = 'ok';
        } catch (\Exception $e) {
            $return['status'] = 'fail';
            $return['error'] = $e->getMessage();
        }

        return $return;
    }

    protected static function parse($config)
    {
        return Yaml::parse($config, true);
    }

    protected static function getValidSettings($key = null)
    {
        $settings = [
            'debug'              => [
                'type'    => 'bool',
                'default' => false,
            ],
            'display_logger'     => [
                'type'    => 'bool',
                'default' => false,
            ],
            'reverse_line_order' => [
                'type'    => 'bool',
                'default' => true,
            ],
            'date_format'        => [
                'type'    => 'string',
                'default' => 'dd.MM.yyyy HH:mm:ss',
            ],
            'timezone'           => [
                'type'    => 'string',
                'default' => 'Europe/Zurich',
            ],
            'limit'              => [
                'type'    => 'int',
                'default' => 100,
            ],
            'cache_expire'       => [
                'type'    => 'int',
                'default' => 300,
            ],
        ];

        return $key !== null ? $settings[$key] : $settings;
    }

    protected static function getValidLogTypes()
    {
        return [
            'local'   => [
                'path'    => [
                    'type' => 'string',
                ],
                'pattern' => [
                    'type' => 'string',
                ],
            ], 'ftp'  => [
                'host'     => [
                    'type' => 'string',
                ],
                'username' => [
                    'type' => 'string',
                ],
                'password' => [
                    'type' => 'string',
                ],
                'path'     => [
                    'type' => 'string',
                ],
                'pattern'  => [
                    'type' => 'string',
                ],
            ], 'sftp' => [
                'host'        => [
                    'type' => 'string',
                ],
                'username'    => [
                    'type' => 'string',
                ],
                'password'    => [
                    'type' => 'string',
                ],
                'path'        => [
                    'type' => 'string',
                ],
                'pattern'     => [
                    'type' => 'string',
                ],
                'private_key' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    public function validate()
    {
        return self::lint($this->config);
    }

    /**
     * Returns a config property if it exists and throws an exception if not.
     *
     * @param string|null $property Dot-separated property (e.g. "date_format" or "logs.collection.log_file")
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    public function get($property = null)
    {
        if ($property === null || $property == '') {
            return $this->config;
        }

        $tree = explode('.', $property);
        $node = $this->config;
        foreach ($tree as $workingNode) {
            if (!array_key_exists($workingNode, $node)) {
                $actualNode = null;
                foreach ($node as $testNodeKey => $testNode) {
                    if (\URLify::filter($testNodeKey) == $workingNode) {
                        $actualNode = $testNodeKey;
                    }
                }
                if ($actualNode === null) {
                    throw new \InvalidArgumentException('The property "'.$property
                        .'" was not found. Failed while getting node "'.$workingNode.'"');
                }
                $workingNode = $actualNode;
            }
            $node = $node[$workingNode];
        }

        return $node;
    }

    protected function getValidConfigTree()
    {
        return $this->configTree;
    }

    protected function getMandatoryConfigTree()
    {
    }
}
