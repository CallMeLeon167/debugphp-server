<?php

/**
 * This file is part of the DebugPHP Server.
 *
 * (c) Leon Schmidt <kontakt@callmeleon.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @see https://github.com/CallMeLeon167/debugphp-server
 */

declare(strict_types=1);

namespace DebugPHP\Server;

use DebugPHP\Server\Storage\EntryRepository;
use DebugPHP\Server\Storage\MetricRepository;
use DebugPHP\Server\Storage\SessionRepository;
use DebugPHP\Server\Storage\StoragePath;
use DebugPHP\Server\Http\Controller;
use DebugPHP\Server\Http\StreamController;

/**
 * Central entry point of the application.
 *
 * This class is responsible for:
 * - Instantiating and wiring all dependencies
 * - Registering all routes on the router
 * - Dispatching the incoming HTTP request
 *
 * Usage (in index.php):
 *   (new Application())->run();
 */
final class Application
{
    /**
     * The router that manages all registered routes.
     *
     * @var Router
     */
    private Router $router;

    /**
     * Bootstraps the application and wires all dependencies.
     */
    public function __construct()
    {
        Config::init();

        $storage           = new StoragePath();
        $sessionRepository = new SessionRepository($storage);
        $entryRepository   = new EntryRepository($storage);
        $metricRepository  = new MetricRepository($storage);
        $controller        = new Controller($sessionRepository, $entryRepository, $metricRepository);
        $streamController  = new StreamController($sessionRepository, $entryRepository, $metricRepository);

        $this->router = new Router();
        $this->registerRoutes($controller, $streamController);
    }

    /**
     * Dispatches the incoming HTTP request.
     *
     * @return void
     */
    public function run(): void
    {
        $request = Request::fromGlobals();
        $this->router->dispatch($request);
    }

    /**
     * Registers all application routes.
     *
     * @param Controller       $controller       The HTTP controller.
     * @param StreamController $streamController The SSE stream controller.
     *
     * @return void
     */
    private function registerRoutes(Controller $controller, StreamController $streamController): void
    {
        $this->router->get('/', [$controller, 'dashboard']);

        $this->router->post('/api/session', [$controller, 'createSession']);
        $this->router->delete('/api/session/{id}', [$controller, 'deleteSession']);

        $this->router->post('/api/debug', [$controller, 'storeEntry']);

        $this->router->delete('/api/entry/{id:int}', [$controller, 'deleteEntry']);

        $this->router->post('/api/clear', [$controller, 'clearEntries']);

        $this->router->post('/api/metric', [$controller, 'storeMetric']);

        $this->router->get('/api/stream/{id}', [$streamController, 'handle']);
    }
}
