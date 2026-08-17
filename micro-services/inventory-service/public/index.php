<?php

require __DIR__ . '/../bootstrap.php';

use Inventory\Config;
use Inventory\Controller\InventoryController;
use Inventory\Db\Database;
use Inventory\Domain\InsufficientStock;
use Inventory\Domain\InvalidRequest;
use Inventory\Domain\SkuNotFound;
use Inventory\Http\MethodNotAllowed;
use Inventory\Http\Router;
use Inventory\Repository\InventoryRepository;
use Inventory\Tracing\OtlpHttpExporter;
use Inventory\Tracing\Span;
use Inventory\Tracing\TraceContext;
use Inventory\Tracing\Tracer;

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path === false || $path === null) {
    $path = '/';
}

// Untraced: healthchecks poll every few seconds and would swamp real traffic.
if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(array('status' => 'UP', 'service' => Config::serviceName()));
    exit;
}

$context = TraceContext::fromServer($_SERVER);

$exporter = new OtlpHttpExporter(
    Config::otlpTracesEndpoint(),
    array(
        'service.name' => Config::serviceName(),
        'service.version' => Config::serviceVersion(),
        'deployment.environment' => Config::deploymentEnvironment(),
        'telemetry.sdk.name' => 'inventory-service-tracer',
        'telemetry.sdk.language' => 'php',
        'telemetry.sdk.version' => Config::serviceVersion(),
        'host.name' => gethostname(),
        'process.runtime.name' => 'php',
        'process.runtime.version' => PHP_VERSION,
    ),
    'inventory-service-tracer',
    Config::serviceVersion(),
    Config::otlpTimeoutMs()
);

$tracer = new Tracer($context, $exporter);

register_shutdown_function(function () use ($tracer) {
    $tracer->flush();
});

$database = new Database(
    $tracer,
    Config::dbHost(),
    Config::dbPort(),
    Config::dbName(),
    Config::dbUser(),
    Config::dbPassword()
);
$controller = new InventoryController(new InventoryRepository($database), $tracer);

$router = new Router();
$router->add('GET', '/api/inventory/{sku}', array($controller, 'show'));
$router->add('POST', '/api/inventory/{sku}/reserve', array($controller, 'reserve'));

$route = null;
$routingError = null;
try {
    $route = $router->match($method, $path);
} catch (MethodNotAllowed $e) {
    $routingError = $e;
}

$routeTemplate = $route !== null ? $route['template'] : null;
$spanName = $routeTemplate !== null ? $method . ' ' . $routeTemplate : $method;

$serverSpan = $tracer->startSpan($spanName, Span::KIND_SERVER, array(
    'http.request.method' => $method,
    'url.path' => $path,
    'url.scheme' => empty($_SERVER['HTTPS']) ? 'http' : 'https',
    'http.route' => $routeTemplate,
    'server.address' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null,
    'server.port' => isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : null,
    'client.address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
    'user_agent.original' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
));

$status = 500;
$payload = array('error' => 'Internal Server Error');

try {
    if ($routingError !== null) {
        throw $routingError;
    }
    if ($route === null) {
        $status = 404;
        $payload = array('error' => 'Not Found', 'message' => 'No route for ' . $path);
    } else {
        list($status, $payload) = call_user_func(
            $route['handler'],
            $route['params'],
            readJsonBody()
        );
    }
} catch (InvalidRequest $e) {
    $status = 400;
    $payload = array('error' => 'Bad Request', 'message' => $e->getMessage());
} catch (SkuNotFound $e) {
    $status = 404;
    $payload = array('error' => 'Not Found', 'message' => $e->getMessage());
} catch (MethodNotAllowed $e) {
    $status = 405;
    $payload = array('error' => 'Method Not Allowed', 'message' => $e->getMessage());
} catch (InsufficientStock $e) {
    $status = 409;
    $payload = array('error' => 'Conflict', 'message' => $e->getMessage());
} catch (\Throwable $e) {
    error_log('[inventory] unhandled: ' . $e->getMessage());
    $status = 500;
    $payload = array('error' => 'Internal Server Error', 'message' => 'Unexpected error');
    $serverSpan->recordException($e, Tracer::nowNanos());
}

$serverSpan->setAttribute('http.response.status_code', $status);

// Only 5xx is an error. 2xx must stay unset per the HTTP conventions, which is
// also what the Java agent does; setting OK here would split status-grouped
// metric queries across the two services.
if ($status >= 500) {
    $serverSpan->setStatus(Span::STATUS_ERROR, isset($payload['message']) ? $payload['message'] : '');
}

$tracer->endSpan($serverSpan);

http_response_code($status);
header('Content-Type: application/json');
header('X-Trace-Id: ' . $tracer->traceId());
echo json_encode($payload);

function readJsonBody()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidRequest('Request body is not valid JSON: ' . json_last_error_msg());
    }
    if (!is_array($decoded)) {
        throw new InvalidRequest('Request body must be a JSON object');
    }

    return $decoded;
}
