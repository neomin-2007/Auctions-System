<?php

require_once __DIR__ . "/AuctionErrorCode.php";

class Router {

    private $routes = [
        "GET"  => [],
        "POST" => []
    ];

    private $config;

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function get($path, $handler) {
        $this->routes["GET"][$path] = $handler;
    }

    public function post($path, $handler) {
        $this->routes["POST"][$path] = $handler;
    }

    public function run() {

        $method = $_SERVER["REQUEST_METHOD"];
        $uri    = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

        if (!isset($this->routes[$method][$uri])) {
            http_response_code(404);
            echo json_encode(["error" => "Route not found"]);
            return;
        }

        $handler = $this->routes[$method][$uri];

        try {

            if (is_array($handler)) {

                $class      = $handler[0];
                $methodName = $handler[1];

                global $auctionService;

                $controller = new $class($auctionService, $this->config);
                $controller->$methodName();
                return;
            }

            call_user_func($handler);

        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                "error" => $e->getMessage(),
                "code"  => $e->getCode() ?: AuctionErrorCode::INVALID_INPUT
            ]);

        } catch (RuntimeException $e) {
            $code    = $e->getCode();
            $isRateLimit = $code === AuctionErrorCode::AUCTION_RATE_LIMIT_REACHED;
            http_response_code($isRateLimit ? 429 : 409);
            echo json_encode([
                "error" => $e->getMessage(),
                "code"  => $code ?: 0
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Internal server error", "code" => 0]);
        }
    }
}
