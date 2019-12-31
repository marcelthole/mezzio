<?php

/**
 * @see       https://github.com/mezzio/mezzio for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Router;

use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Fig\Http\Message\StatusCodeInterface as StatusCode;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Application;
use Mezzio\Middleware;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\Router\AuraRouter;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\LaminasRouter;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\Middleware\PathBasedRoutingMiddleware as RouteMiddleware;
use Mezzio\Router\RouteResult;
use Mezzio\Router\RouterInterface;
use MezzioTest\ContainerTrait;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class IntegrationTest extends TestCase
{
    use ContainerTrait;

    /** @var Response */
    private $response;

    /** @var callable */
    private $responseFactory;

    /** @var RouterInterface|ObjectProphecy */
    private $router;

    /** @var ContainerInterface|ObjectProphecy */
    private $container;

    public function setUp()
    {
        $this->response  = new Response();
        $this->responseFactory = function () {
            return $this->response;
        };
        $this->router    = $this->prophesize(RouterInterface::class);
        $this->container = $this->mockContainerInterface();
    }

    public function getApplication()
    {
        return $this->createApplicationFromRouter($this->router->reveal());
    }

    public function createApplicationFromRouter(RouterInterface $router)
    {
        $container = new MiddlewareContainer($this->container->reveal());
        $factory = new MiddlewareFactory($container);
        $pipeline = new MiddlewarePipe();
        $routeMiddleware = new RouteMiddleware($router);
        $runner = $this->prophesize(RequestHandlerRunner::class)->reveal();
        return new Application(
            $factory,
            $pipeline,
            $routeMiddleware,
            $runner
        );
    }

    /**
     * Get the router adapters to test
     */
    public function routerAdapters()
    {
        return [
            'aura'       => [AuraRouter::class],
            'fast-route' => [FastRouteRouter::class],
            'laminas'        => [LaminasRouter::class],
        ];
    }

    /**
     * Create an Application object with 2 routes, a GET and a POST
     * using Application::get() and Application::post()
     *
     * @param string $adapter
     * @param string $getName
     * @param string $postName
     * @return Application
     */
    private function createApplicationWithGetPost($adapter, $getName = null, $postName = null)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));

        $app->get('/foo', function ($req, $handler) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware GET');
            return $this->response->withBody($stream);
        }, $getName);
        $app->post('/foo', function ($req, $handler) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware POST');
            return $this->response->withBody($stream);
        }, $postName);

        return $app;
    }

    /**
     * Create an Application object with 2 routes, a GET and a POST
     * using Application::route()
     *
     * @param string $adapter
     * @param string $getName
     * @param string $postName
     * @return Application
     */
    private function createApplicationWithRouteGetPost($adapter, $getName = null, $postName = null)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));

        $app->route('/foo', function ($req, $handler) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware GET');
            return $this->response->withBody($stream);
        }, ['GET'], $getName);
        $app->route('/foo', function ($req, $handler) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware POST');
            return $this->response->withBody($stream);
        }, ['POST'], $postName);

        return $app;
    }

    /**
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingDoesNotMatchMethod($adapter)
    {
        $app = $this->createApplicationWithGetPost($adapter);
        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'DELETE'], [], '/foo', 'DELETE');
        $result  = $app->process($request, $handler->reveal());

        $this->assertSame(StatusCode::STATUS_METHOD_NOT_ALLOWED, $result->getStatusCode());
        $headers = $result->getHeaders();
        $this->assertSame(['GET,POST'], $headers['Allow']);
    }

    /**
     * @see https://github.com/zendframework/zend-expressive/issues/40
     * @group 40
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingWithSamePathWithoutName($adapter)
    {
        $app = $this->createApplicationWithGetPost($adapter);
        $app->pipe(new DispatchMiddleware());

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware GET', (string) $result->getBody());

        $request  = new ServerRequest(['REQUEST_METHOD' => 'POST'], [], '/foo', 'POST');
        $result   = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware POST', (string) $result->getBody());
    }

    /**
     * @see https://github.com/zendframework/zend-expressive/issues/40
     * @group 40
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingWithSamePathWithName($adapter)
    {
        $app = $this->createApplicationWithGetPost($adapter, 'foo-get', 'foo-post');
        $app->pipe(new DispatchMiddleware());

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware GET', (string) $result->getBody());

        $request = new ServerRequest(['REQUEST_METHOD' => 'POST'], [], '/foo', 'POST');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware POST', (string) $result->getBody());
    }

    /**
     * @see https://github.com/zendframework/zend-expressive/issues/40
     * @group 40
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingWithSamePathWithRouteWithoutName($adapter)
    {
        $app = $this->createApplicationWithRouteGetPost($adapter);
        $app->pipe(new DispatchMiddleware());

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware GET', (string) $result->getBody());

        $request = new ServerRequest(['REQUEST_METHOD' => 'POST'], [], '/foo', 'POST');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware POST', (string) $result->getBody());
    }

    /**
     * @see https://github.com/zendframework/zend-expressive/issues/40
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingWithSamePathWithRouteWithName($adapter)
    {
        $app = $this->createApplicationWithRouteGetPost($adapter, 'foo-get', 'foo-post');
        $app->pipe(new DispatchMiddleware());

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware GET', (string) $result->getBody());

        $request = new ServerRequest(['REQUEST_METHOD' => 'POST'], [], '/foo', 'POST');
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals('Middleware POST', (string) $result->getBody());
    }

    /**
     * @see https://github.com/zendframework/zend-expressive/issues/40
     * @group 40
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testRoutingWithSamePathWithRouteWithMultipleMethods($adapter)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));
        $app->pipe(new DispatchMiddleware());

        $response = clone $this->response;
        $app->route('/foo', function ($req, $handler) use ($response) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware GET, POST');
            return $response->withBody($stream);
        }, ['GET', 'POST']);

        $deleteResponse = clone $this->response;
        $app->route('/foo', function ($req, $handler) use ($deleteResponse) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware DELETE');
            return $deleteResponse->withBody($stream);
        }, ['DELETE']);

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());
        $this->assertEquals('Middleware GET, POST', (string) $result->getBody());

        $request = new ServerRequest(['REQUEST_METHOD' => 'POST'], [], '/foo', 'POST');
        $result  = $app->process($request, $handler->reveal());
        $this->assertEquals('Middleware GET, POST', (string) $result->getBody());

        $request = new ServerRequest(['REQUEST_METHOD' => 'DELETE'], [], '/foo', 'DELETE');
        $result  = $app->process($request, $handler->reveal());
        $this->assertEquals('Middleware DELETE', (string) $result->getBody());
    }

    public function routerAdaptersForHttpMethods()
    {
        $allMethods = [
            RequestMethod::METHOD_GET,
            RequestMethod::METHOD_POST,
            RequestMethod::METHOD_PUT,
            RequestMethod::METHOD_DELETE,
            RequestMethod::METHOD_PATCH,
            RequestMethod::METHOD_HEAD,
            RequestMethod::METHOD_OPTIONS,
        ];
        foreach ($this->routerAdapters() as $adapterName => $adapter) {
            $adapter = array_pop($adapter);
            foreach ($allMethods as $method) {
                $name = sprintf('%s-%s', $adapterName, $method);
                yield $name => [$adapter, $method];
            }
        }
    }

    /**
     * @dataProvider routerAdaptersForHttpMethods
     *
     * @param string $adapter
     * @param string $method
     */
    public function testMatchWithAllHttpMethods($adapter, $method)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));
        $app->pipe(new DispatchMiddleware());

        // Add a route with Mezzio\Router\Route::HTTP_METHOD_ANY
        $response = clone $this->response;
        $app->route('/foo', function ($req, $handler) use ($response) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware');
            return $response->withBody($stream);
        });

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => $method], [], '/foo', $method);
        $result  = $app->process($request, $handler->reveal());
        $this->assertEquals('Middleware', (string) $result->getBody());
    }

    public function allowedMethod()
    {
        return [
            'aura-head'          => [AuraRouter::class, RequestMethod::METHOD_HEAD],
            'aura-options'       => [AuraRouter::class, RequestMethod::METHOD_OPTIONS],
            'fast-route-head'    => [FastRouteRouter::class, RequestMethod::METHOD_HEAD],
            'fast-route-options' => [FastRouteRouter::class, RequestMethod::METHOD_OPTIONS],
            'laminas-head'           => [LaminasRouter::class, RequestMethod::METHOD_HEAD],
            'laminas-options'        => [LaminasRouter::class, RequestMethod::METHOD_OPTIONS],
        ];
    }

    public function notAllowedMethod()
    {
        return [
            'aura-get'          => [AuraRouter::class, RequestMethod::METHOD_GET],
            'aura-post'         => [AuraRouter::class, RequestMethod::METHOD_POST],
            'aura-put'          => [AuraRouter::class, RequestMethod::METHOD_PUT],
            'aura-delete'       => [AuraRouter::class, RequestMethod::METHOD_DELETE],
            'aura-patch'        => [AuraRouter::class, RequestMethod::METHOD_PATCH],
            'fast-route-post'   => [FastRouteRouter::class, RequestMethod::METHOD_POST],
            'fast-route-put'    => [FastRouteRouter::class, RequestMethod::METHOD_PUT],
            'fast-route-delete' => [FastRouteRouter::class, RequestMethod::METHOD_DELETE],
            'fast-route-patch'  => [FastRouteRouter::class, RequestMethod::METHOD_PATCH],
            'laminas-get'           => [LaminasRouter::class, RequestMethod::METHOD_GET],
            'laminas-post'          => [LaminasRouter::class, RequestMethod::METHOD_POST],
            'laminas-put'           => [LaminasRouter::class, RequestMethod::METHOD_PUT],
            'laminas-delete'        => [LaminasRouter::class, RequestMethod::METHOD_DELETE],
            'laminas-patch'         => [LaminasRouter::class, RequestMethod::METHOD_PATCH],
        ];
    }

    /**
     * @dataProvider allowedMethod
     *
     * @param string $adapter
     * @param string $method
     */
    public function testAllowedMethodsWhenOnlyPutMethodSet($adapter, $method)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));
        $app->pipe(new ImplicitHeadMiddleware($this->responseFactory, function () {
        }));
        $app->pipe(new ImplicitOptionsMiddleware($this->responseFactory));
        $app->pipe(new DispatchMiddleware());

        // Add a PUT route
        $app->put('/foo', function ($req, $res, $next) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware');
            return $res->withBody($stream);
        });

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request  = new ServerRequest(['REQUEST_METHOD' => $method], [], '/foo', $method);
        $result   = $app->process($request, $handler->reveal());

        $this->assertEquals(StatusCode::STATUS_OK, $result->getStatusCode());
        $this->assertEquals('', (string) $result->getBody());
    }

    /**
     * @dataProvider allowedMethod
     *
     * @param string $adapter
     * @param string $method
     */
    public function testAllowedMethodsWhenNoHttpMethodsSet($adapter, $method)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));

        // This middleware is used just to check that request has successful RouteResult
        $middleware = $this->prophesize(MiddlewareInterface::class);
        $middleware->process(Argument::that(function (ServerRequestInterface $req) {
            $routeResult = $req->getAttribute(RouteResult::class);

            Assert::assertInstanceOf(RouteResult::class, $routeResult);
            Assert::assertTrue($routeResult->isSuccess());
            Assert::assertFalse($routeResult->isMethodFailure());

            return true;
        }), Argument::any())->will(function (array $args) {
            return $args[1]->handle($args[0]);
        });

        $app->pipe($middleware->reveal());

        if ($method === 'HEAD') {
            $app->pipe(new ImplicitHeadMiddleware($this->responseFactory, function () {
            }));
        }
        if ($method === 'OPTIONS') {
            $app->pipe(new ImplicitOptionsMiddleware($this->responseFactory));
        }
        $app->pipe(new DispatchMiddleware());

        // Add a route with empty array - NO HTTP methods
        $app->route('/foo', function ($req, $res, $next) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware');
            return $res->withBody($stream);
        }, []);

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->willReturn($this->response);

        $request = new ServerRequest(['REQUEST_METHOD' => $method], [], '/foo', $method);
        $result  = $app->process($request, $handler->reveal());

        $this->assertEquals(StatusCode::STATUS_OK, $result->getStatusCode());
        $this->assertEquals('', (string) $result->getBody());
    }

    /**
     * @dataProvider notAllowedMethod
     *
     * @param string $adapter
     * @param string $method
     */
    public function testNotAllowedMethodWhenNoHttpMethodsSet($adapter, $method)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));
        $app->pipe(new MethodNotAllowedMiddleware($this->responseFactory));
        $app->pipe(new DispatchMiddleware());

        // Add a route with empty array - NO HTTP methods
        $app->route('/foo', function ($req, $res, $next) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware');
            return $res->withBody($stream);
        }, []);

        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle(Argument::type(ServerRequest::class))
            ->shouldNotBeCalled();

        $request = new ServerRequest(['REQUEST_METHOD' => $method], [], '/foo', $method);
        $result  = $app->process($request, $handler->reveal());
        $this->assertEquals(StatusCode::STATUS_METHOD_NOT_ALLOWED, $result->getStatusCode());
        $this->assertNotContains('Middleware', (string) $result->getBody());
    }

    /**
     * @group 74
     *
     * @dataProvider routerAdapters
     *
     * @param string $adapter
     */
    public function testWithOnlyRootPathRouteDefinedRoutingToSubPathsShouldDelegate($adapter)
    {
        $router = new $adapter();
        $app = $this->createApplicationFromRouter($router);
        $app->pipe(new RouteMiddleware($router));

        $response = clone $this->response;
        $app->route('/', function ($req, $handler) use ($response) {
            $stream = new Stream('php://temp', 'w+');
            $stream->write('Middleware');
            return $response->withBody($stream);
        }, ['GET']);

        $expected = $this->response->withStatus(StatusCode::STATUS_NOT_FOUND);
        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler
            ->handle(Argument::type(ServerRequest::class))
            ->willReturn($expected);

        $request = new ServerRequest(['REQUEST_METHOD' => 'GET'], [], '/foo', 'GET');
        $result  = $app->process($request, $handler->reveal());
        $this->assertSame($expected, $result);
    }
}
