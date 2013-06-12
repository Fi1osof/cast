<?php
/*
 * This file is part of the cast package.
 *
 * Copyright (c) 2013 Jason Coward <jason@opengeek.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cast\Git;

use Cast\Git\Commands\Command;

/**
 * An API wrapper for executing Git commands on a Git repository.
 *
 * @package Cast\Git
 */
class Git
{
    const GIT_BIN = 'cast.git_bin';
    const GIT_ENV = 'cast.git_env';

    /** @var string The path to the Git repository. */
    protected $path;
    /** @var bool Flag indicating if the repository is bare. */
    protected $bare;
    /** @var bool Flag indicating if an initialized repository is related to this instance. */
    protected $initialized = false;
    /** @var array A cached array of Git + Cast config data. */
    protected $options = array();
    /** @var array An array of Command classes loaded (on-demand). */
    protected $commands = array();

    public static function isValidRepositoryPath($path)
    {
        $valid = false;
        if (is_readable($path . '/.git/HEAD') || is_readable($path . '/HEAD')) {
            $valid = true;
        }
        return $valid;
    }

    /**
     * Construct a new Git instance.
     *
     * @param string|null $path The path to a valid Git repository or null.
     * @param null|array $options An optional array of config options.
     */
    public function __construct($path = null, $options = null)
    {
        $this->options = is_array($options) ? $options : array();
        if (is_string($path) && self::isValidRepositoryPath($path)) {
            $this->setPath($path);
            $this->setInitialized();
        } elseif (is_string($path)) {
            $this->path = rtrim($path, '/');
        }
        $this->bare = (bool)$this->getOption('core.bare', null, false);
    }

    /**
     * Get a config option for this Git instance.
     *
     * This includes Git global, user, and local config options, plus any
     * additional user-defined options for use in Cast.
     *
     * @param string $key The key of the config option to get.
     * @param null|array $options An optional array of config key/value pairs.
     * @param mixed $default The default value to use if no option is found.
     *
     * @return mixed The value of the config option.
     */
    public function getOption($key, $options = null, $default = null)
    {
        if (is_array($options) && array_key_exists($key, $options)) {
            $value = $options[$key];
        } elseif (is_array($this->options) && array_key_exists($key, $this->options)) {
            $value = $this->options[$key];
        } else {
            $value = $default;
        }
        return $value;
    }

    /**
     * Execute a Git command.
     *
     * @param string $command The complete command to execute.
     * @param null|array $options An optional config array.
     *
     * @throws \RuntimeException If the process could not be opened.
     * @return array An array containing the process result, stdout and stderr.
     */
    public function exec($command, $options = null)
    {
        @set_time_limit(0);
        $process = proc_open(
            $this->getOption(self::GIT_BIN, $options, 'git') . ' ' . $command,
            array(
                0 => array("pipe", "r"),
                1 => array("pipe", "w"),
                2 => array("pipe", "w")
            ),
            $pipes,
            $this->path,
            $this->getOption(self::GIT_ENV, $options, null)
        );
        if (is_resource($process)) {
            try {
                /* close stdin pipe */
                fclose($pipes[0]);
                /* get stdout and close pipe */
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                /* get stderr and close pipe */
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[2]);

                $return = proc_close($process);
            } catch (\Exception $e) {
                throw new \RuntimeException($e->getMessage());
            }
            return array($return, $output, $errors, $command, $options);
        }
        throw new \RuntimeException(sprintf('Could not execute command git %s', $command));
    }

    public function getPath()
    {
        return $this->path;
    }

    public function setPath($path)
    {
        if (!Git::isValidRepositoryPath($path)) {
            throw new \InvalidArgumentException("Attempt to set the repository path to an invalid Git repository (path={$path}).");
        }
        $this->path = rtrim($path, '/');
    }

    /**
     * Determines if this instance references an initialized Git repository.
     *
     * @return bool true if this instance references an initialized Git repository.
     */
    public function isInitialized()
    {
        return $this->initialized;
    }

    public function setInitialized()
    {
        $this->loadConfig($this->options);
        $this->initialized = true;
    }

    /**
     * Determines if this instance references a bare Git repository.
     *
     * @throws \BadMethodCallException If this instance is not initialized with a repository.
     * @return bool true if this instance references a bare Git repository.
     */
    public function isBare()
    {
        if (!$this->isInitialized())
        {
            throw new \BadMethodCallException(sprintf("%s requires an initialized Git repository to be associated", __METHOD__));
        }
        return $this->bare;
    }

    public function setBare($bare = true)
    {
        $this->bare = $bare;
    }

    /**
     * Load the complete Git config for the repository.
     *
     * @param null|array $options An optional array of config options.
     *
     * @return array The complete Git config merged with options.
     */
    protected function loadConfig($options = null)
    {
        $config = array();
        $configResults = $this->exec("config --list", $options);
        $configLines = explode("\n", $configResults[1]);
        array_pop($configLines);
        foreach ($configLines as $configLine) {
            list($key, $value) = explode("=", $configLine, 2);
            $config[$key] = $value;
        }
        if (!is_array($options)) $options = array();
        return array_merge($config, $options);
    }

    public function __call($name, $arguments)
    {
        if (!array_key_exists($name, $this->commands)) {
            $commandClass = $this->_commandClass($name);
            if (class_exists($commandClass)) {
                $this->commands[$name] = new $commandClass($this);
                return call_user_func_array(array($this->commands[$name], 'run'), array($arguments));
            }
            throw new \BadMethodCallException(sprintf('The Git Command class %s does not exist', ucfirst($name)));
        }
        return call_user_func_array(array($this->commands[$name], 'run'), array($arguments));
    }

    public function __get($name)
    {
        if (!array_key_exists($name, $this->commands)) {
            $commandClass = $this->_commandClass($name);
            if (class_exists($commandClass)) {
                $this->commands[$name] = new $commandClass($this);
                return $this->commands[$name];
            }
            throw new \InvalidArgumentException(sprintf('The Git Command class %s does not exist', ucfirst($name)));
        }
        return $this->commands[$name];
    }

    public function __isset($name)
    {
        return array_key_exists($name, $this->commands);
    }

    protected function _commandClass($name)
    {
        $className = ucfirst($name);
        return "\\Cast\\Git\\Commands\\{$className}";
    }
}
