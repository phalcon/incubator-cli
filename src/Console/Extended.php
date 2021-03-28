<?php

/**
 * This file is part of the Phalcon Incubator Annotations.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phalcon\Incubator\Cli\Console;

use Phalcon\Annotations\Adapter\Memory as MemoryAdapter;
use Phalcon\Cli\Console as ConsoleApp;
use Phalcon\Cli\Console\Exception;
use Phalcon\Helper\Arr;

/**
 * Phalcon\Incubator\Cli\Console\Extended
 *
 * Extended Console Application that uses annotations in order to create automatically a help description.
 *
 * @package Phalcon\Incubator\Cli\Console
 */
class Extended extends ConsoleApp
{
    private $tasksDir;

    private $documentation;

    /**
     * Handle the whole command-line tasks
     *
     * @param array|null $arguments
     */
    public function handle(array $arguments = null)
    {
        if ($this->isHelpArgInTask($arguments) || $this->isHelpArgInAction($arguments)) {
            $this->setTasksDir();
            $this->createHelp();
            $this->showHelp($arguments['task']);
        }

        parent::handle($arguments);
    }

    /**
     * @throws Exception
     */
    private function setTasksDir()
    {
        $config = $this->getDI()->get('config');

        if (!isset($config['tasksDir']) || !is_dir($config['tasksDir'])) {
            throw new Exception("Invalid provided tasks Dir");
        }

        $this->tasksDir = $config['tasksDir'];
    }

    private function createHelp()
    {
        $scannedTasksDir = array_diff(
            scandir(
                $this->tasksDir
            ),
            [
                '..',
                '.',
            ]
        );

        $config = $this->getDI()->get('config');
        $dispatcher = $this->getDI()->getShared('dispatcher');
        $namespace = $dispatcher->getNamespaceName();

        if (isset($config['annotationsAdapter']) && $config['annotationsAdapter']) {
            $adapter = '\Phalcon\Annotations\Adapter\\' . $config['annotationsAdapter'];
            if (class_exists($adapter)) {
                $reader = new $adapter();
            } else {
                $reader = new MemoryAdapter();
            }
        } else {
            $reader = new MemoryAdapter();
        }

        foreach ($scannedTasksDir as $taskFile) {
            $taskFileInfo = pathinfo($taskFile);
            $taskClass = ($namespace ? $namespace . '\\' : '') . $taskFileInfo["filename"];

            $taskName  = strtolower(
                str_replace(
                    'Task',
                    '',
                    $taskFileInfo["filename"]
                )
            );

            $this->documentation[$taskName] = [
                'description' => [''],
                'actions'     => [],
            ];

            $reflector = $reader->get($taskClass);

            $annotations = $reflector->getClassAnnotations();

            if (!$annotations) {
                continue;
            }

            // Class Annotations
            foreach ($annotations as $annotation) {
                if ($annotation->getName() == 'description') {
                    $this->documentation[$taskName]['description'] = $annotation->getArguments();
                }
            }

            $methodAnnotations = $reflector->getMethodsAnnotations();

            // Method Annotations
            if (!$methodAnnotations) {
                continue;
            }

            foreach ($methodAnnotations as $action => $collection) {
                if ($collection->has('DoNotCover')) {
                    continue;
                }

                $actionName = strtolower(
                    str_replace(
                        'Action',
                        '',
                        $action
                    )
                );

                $this->documentation[$taskName]['actions'][$actionName] = [];

                $actionAnnotations = $collection->getAnnotations();

                foreach ($actionAnnotations as $actAnnotation) {
                    $_anotation = $actAnnotation->getName();

                    if ($_anotation == 'description') {
                        $getDesc = $actAnnotation->getArguments();

                        $this->documentation[$taskName]['actions'][$actionName]['description'] = $getDesc;
                    } elseif ($_anotation == 'param') {
                        $getParams = $actAnnotation->getArguments();

                        $this->documentation[$taskName]['actions'][$actionName]['params'][]  = $getParams;
                    }
                }
            }
        }
    }

    /**
     * @param string|null $task
     */
    private function showHelp(string $task = null)
    {
        $config = $this->getDI()->get('config');

        $helpOutput = PHP_EOL;

        if (isset($config['appName'])) {
            $helpOutput .= $config['appName'] . ' ';
        }

        if (isset($config['version'])) {
            $helpOutput .= $config['version'];
        }

        echo $helpOutput . PHP_EOL;
        echo PHP_EOL . 'Usage:' . PHP_EOL;
        echo PHP_EOL;
        echo "\t" , 'command [<task> [<action> [<param1> <param2> ... <paramN>] ] ]', PHP_EOL;
        echo PHP_EOL;

        if (!is_null($task) && !$this->isHelp($task)) {
            $this->showTaskHelp($task);
        } else {
            $this->showAvailableTasks();
        }
    }

    private function showAvailableTasks()
    {
        echo PHP_EOL . 'To show task help type:' . PHP_EOL;
        echo PHP_EOL;
        echo '           command <task> -h | --help | help'  . PHP_EOL;
        echo PHP_EOL;
        echo 'Available tasks ' . PHP_EOL;

        foreach ($this->documentation as $task => $doc) {
            echo  PHP_EOL;
            echo '    ' . $task . PHP_EOL ;

            foreach ($doc['description'] as $line) {
                echo '            ' . $line . PHP_EOL;
            }
        }
    }

    private function showTaskHelp($task)
    {
        $doc = Arr::get($this->documentation, $task);

        echo PHP_EOL;
        echo "Task: " . $task . PHP_EOL . PHP_EOL;

        foreach ($doc['description'] as $line) {
            echo '  '.$line . PHP_EOL;
        }

        echo PHP_EOL;
        echo 'Available actions:' . PHP_EOL . PHP_EOL;

        foreach ($doc['actions'] as $actionName => $aDoc) {
            echo '           ' . $actionName . PHP_EOL;

            if (isset($aDoc['description'])) {
                echo '               '.implode(PHP_EOL, $aDoc['description']) . PHP_EOL;
            }

            echo  PHP_EOL;

            if (isset($aDoc['params']) && is_array($aDoc['params'])) {
                echo '               Parameters:' . PHP_EOL;

                foreach ($aDoc['params'] as $param) {
                    if (is_array($param)) {
                        $_to_print = '';

                        if (isset($param[0]['name'])) {
                            $_to_print = $param[0]['name'];
                        }

                        if (isset($param[0]['type'])) {
                            $_to_print .= ' ( ' . $param[0]['type'] . ' )';
                        }

                        if (isset($param[0]['description'])) {
                            $_to_print .= ' ' . $param[0]['description'] . PHP_EOL;
                        }

                        if (!empty($_to_print)) {
                            echo '                   ' . $_to_print;
                        }
                    }
                }
            }
        }
    }

    private function isHelpArgInTask(array $arguments): bool
    {
        return Arr::has($arguments, 'task') && $this->isHelp($arguments['task']);
    }

    private function isHelpArgInAction(array $arguments): bool
    {
        return Arr::has($arguments, 'action') && $this->isHelp($arguments['action']);
    }

    private function isHelp(string $argument): bool
    {
        return in_array($argument, ['-h', '--help', 'help']);
    }
}
