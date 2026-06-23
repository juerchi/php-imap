<?php

/*
* File:     ClientManager.php
* Category: -
* Author:   M. Goldenbaum
* Created:  19.01.17 22:21
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Support\ArrayHelper;

/**
 * Class ClientManager.
 *
 * @mixin Client
 */
class ClientManager
{
    /**
     * All library configs.
     */
    public static array $config = [];

    protected array $accounts = [];

    protected ?LoggerInterface $logger = null;

    public function __construct(array|string $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * Dynamically pass calls to the default account.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    public function __call(string $method, array $parameters): mixed
    {
        $callable = [$this->account(), $method];

        return call_user_func_array($callable, $parameters);
    }

    /**
     * Safely create a new client instance which is not listed in accounts.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    public function make(array $config): Client
    {
        $client = new Client($config);
        $client->setLogger($this->logger);

        return $client;
    }

    /**
     * Get a dotted config parameter.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = null;
        foreach ($parts as $part) {
            if (null === $value) {
                if (isset(self::$config[$part])) {
                    $value = self::$config[$part];
                } else {
                    break;
                }
            } elseif (isset($value[$part])) {
                $value = $value[$part];
            } else {
                break;
            }
        }

        return $value ?? $default;
    }

    /**
     * Resolve an account instance.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    public function account(?string $name = null): Client
    {
        $name = $name ?: $this->getDefaultAccount();

        // If the connection has not been resolved, we will resolve it now as all
        // the connections are resolved when they are actually needed, so we do
        // not make any unnecessary connection to the various queue end-points.
        if (!isset($this->accounts[$name])) {
            $this->accounts[$name] = $this->resolve($name);
        }

        return $this->accounts[$name];
    }

    /**
     * Get the name of the default account.
     */
    public function getDefaultAccount(): string
    {
        return self::$config['default'];
    }

    /**
     * Set the name of the default account.
     */
    public function setDefaultAccount(string $name): void
    {
        self::$config['default'] = $name;
    }

    /**
     * Merge the vendor settings with the local config.
     *
     * The default account identifier will be used as default for any missing account parameters.
     * If, however, the default account is missing a parameter, the package default account parameter will be used.
     * This can be disabled by setting imap.default in your config file to 'false'
     */
    public function setConfig(array|string $config): self
    {
        if (false === is_array($config)) {
            $config = require $config;
        }

        $configKey = 'imap';
        $path = __DIR__.'/config/'.$configKey.'.php';

        $vendorConfig = require $path;
        $config = ArrayHelper::mergeRecursiveDistinct($vendorConfig, $config);

        if (is_array($config)) {
            if (isset($config['default'])) {
                if (isset($config['accounts']) && $config['default']) {
                    $defaultConfig = $vendorConfig['accounts']['default'];
                    if (isset($config['accounts'][$config['default']])) {
                        $defaultConfig = array_merge($defaultConfig, $config['accounts'][$config['default']]);
                    }

                    if (is_array($config['accounts'])) {
                        foreach ($config['accounts'] as $accountKey => $account) {
                            $config['accounts'][$accountKey] = array_merge($defaultConfig, $account);
                        }
                    }
                }
            }
        }

        self::$config = $config;

        return $this;
    }

    /**
     * Resolve an account.
     *
     * @throws Exceptions\MaskNotFoundException
     */
    protected function resolve(string $name): Client
    {
        $config = $this->getClientConfig($name);

        $client = new Client($config);
        $client->setLogger($this->logger);

        return $client;
    }

    /**
     * Get the account configuration.
     */
    protected function getClientConfig(?string $name): array
    {
        if (null === $name || 'null' === $name) {
            return ['driver' => 'null'];
        }

        return is_array(self::$config['accounts'][$name]) ? self::$config['accounts'][$name] : [];
    }
}
