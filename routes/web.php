<?php
declare(strict_types=1);

use School\Core\Request;
use School\Core\Response;
use School\Core\Router;

return static function (Router $router): void {
    $router->get('/school', static fn (Request $request): Response => Response::redirect('/school/login.php'));
};
