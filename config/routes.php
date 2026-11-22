<?php

declare(strict_types=1);

use App\Http\Controller\Admin\AuthenticationController;
use App\Http\Controller\Admin\BlacklistController;
use App\Http\Controller\Admin\PermitCheckController;
use App\Http\Controller\Admin\PermitController as AdminPermitController;
use App\Http\Controller\Admin\PermitGenerationController;
use App\Http\Controller\PermitController;
use App\Http\Controller\SiteController;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Middleware\AdminSessionMiddleware;
use App\Http\Middleware\PermitCheckMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [SiteController::class, 'home'])->setName('home');
    $app->get('/health', [SiteController::class, 'health'])->setName('health');
    $app->get('/permit', [SiteController::class, 'permitRedirect']);
    $app->get('/permit/', [SiteController::class, 'permitRedirect']);
    $app->get('/permit/index.php', [SiteController::class, 'permitRedirect']);
    $app->get('/p/check', [PermitController::class, 'verify'])->setName('verify')->add(PermitCheckMiddleware::class);
    $app->get('/p/check.php', [PermitController::class, 'verify'])->add(PermitCheckMiddleware::class);
    $app->group('/admin', function (RouteCollectorProxy $admin): void {
        $admin->get('', [AuthenticationController::class, 'index']);
        $admin->get('/', [AuthenticationController::class, 'index']);
        $admin->map(['GET', 'POST'], '/login', [AuthenticationController::class, 'login']);
        $admin->group('', function (RouteCollectorProxy $protected): void {
            $protected->map(['GET', 'POST'], '/permits/generate', [PermitGenerationController::class, 'generate'])
                ->setName('permit');
            $protected->map(['GET', 'POST'], '/blacklist', [BlacklistController::class, 'index']);
            $protected->get('/checks', [PermitCheckController::class, 'index']);
            $protected->post('/checks/blacklist', [PermitCheckController::class, 'blacklist']);
            $protected->get('/permits', [AdminPermitController::class, 'index']);
            $protected->post('/permits/{fingerprint:[a-f0-9]{64}}/reprint', [AdminPermitController::class, 'reprint']);
            $protected->post('/logout', [AuthenticationController::class, 'logout']);
        })->add(AdminAuthenticationMiddleware::class);
    })->add(AdminSessionMiddleware::class);
};
