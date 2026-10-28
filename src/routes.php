<?php

declare(strict_types=1);

/**
 * Every route of RecupBuild and the controller action answering it.
 *
 * Pages (rendered by the server, src/Views) for people, and a JSON API under /api/.
 * Every /api/ route but /api/health needs a signed-in user (the model is paid for);
 * the app's pages send visitors to Google first. The controllers check both.
 */

use RecupBuild\Controllers\AdminController;
use RecupBuild\Controllers\AuthController;
use RecupBuild\Controllers\HealthController;
use RecupBuild\Controllers\HistoryController;
use RecupBuild\Controllers\HomeController;
use RecupBuild\Controllers\PlanController;
use RecupBuild\Controllers\PlanPageController;
use RecupBuild\Controllers\PricingController;
use RecupBuild\Controllers\ProfileController;
use RecupBuild\Controllers\RebuildController;
use RecupBuild\Controllers\ScanController;
use RecupBuild\Controllers\WorkspaceController;
use RecupBuild\Core\Router;

return static function (Router $router): void {
    // ------------------------------------------------------------------ pages

    $router->get('/', [HomeController::class, 'home']);
    $router->get('/about', [HomeController::class, 'about']);
    $router->get('/legal', [HomeController::class, 'legal']);
    $router->get('/cgu', [HomeController::class, 'cgu']);
    $router->get('/privacy', [HomeController::class, 'privacy']);
    $router->get('/pricing', [PricingController::class, 'show']);

    // The app: photo, inventory, projects, then the plan.
    $router->get('/scan', [WorkspaceController::class, 'scan']);
    $router->post('/scan', [WorkspaceController::class, 'upload']);
    $router->get('/scan/photo', [WorkspaceController::class, 'photo']);
    $router->post('/scan/reset', [WorkspaceController::class, 'reset']);
    $router->get('/inventory', [WorkspaceController::class, 'inventory']);
    $router->post('/inventory', [WorkspaceController::class, 'saveInventory']);
    $router->get('/projects', [WorkspaceController::class, 'projects']);
    $router->post('/projects/choose', [WorkspaceController::class, 'choose']);
    $router->get('/plans/{id}', [PlanPageController::class, 'show']);
    $router->get('/plans/{id}/photo', [PlanPageController::class, 'photo']);
    $router->get('/history', [PlanPageController::class, 'history']);

    // The reverse build: from a finished object to how to make it.
    $router->get('/rebuild', [RebuildController::class, 'form']);
    $router->post('/rebuild', [RebuildController::class, 'analyse']);
    $router->get('/rebuilds/{id}', [RebuildController::class, 'show']);
    $router->get('/rebuilds/{id}/photo', [RebuildController::class, 'photo']);

    // The account.
    $router->get('/profile', [ProfileController::class, 'show']);
    $router->post('/profile/plans/{id}/delete', [ProfileController::class, 'deletePlan']);
    $router->post('/profile/rebuilds/{id}/delete', [ProfileController::class, 'deleteRebuild']);
    $router->post('/profile/logout', [ProfileController::class, 'logout']);

    // The back office: its own area with a sidebar (ADMIN_EMAILS in .env.php).
    $router->get('/admin', [AdminController::class, 'dashboard']);
    $router->get('/admin/users', [AdminController::class, 'users']);
    $router->post('/admin/users/{id}/credits', [AdminController::class, 'setCredits']);
    $router->get('/admin/pricing', [AdminController::class, 'pricing']);
    $router->post('/admin/pricing', [AdminController::class, 'savePricing']);
    $router->get('/admin/legal', [AdminController::class, 'legal']);
    $router->post('/admin/legal', [AdminController::class, 'saveLegal']);

    // Sign in with Google, done by the server.
    $router->get('/auth/google', [AuthController::class, 'start']);
    $router->get('/auth/google/callback', [AuthController::class, 'callback']);
    $router->post('/auth/logout', [AuthController::class, 'logout']);

    // -------------------------------------------------------------------- API

    $router->get('/api/health', [HealthController::class, 'show']);
    $router->get('/api/me', [AuthController::class, 'me']);
    $router->post('/api/scan', [ScanController::class, 'scan']);
    $router->post('/api/reverse', [ScanController::class, 'reverse']);
    $router->post('/api/projects', [PlanController::class, 'projects']);
    $router->post('/api/plan', [PlanController::class, 'plan']);
    $router->get('/api/history', [HistoryController::class, 'index']);
    $router->get('/api/history/{id}', [HistoryController::class, 'show']);
    $router->delete('/api/history/{id}', [HistoryController::class, 'destroy']);

    // Any other page: "page not found" (any other /api/ path: a JSON 404).
    $router->fallback('/api/', [HomeController::class, 'notFound']);
};
