<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\View;
use RecupBuild\Models\Settings;

/**
 * The public pages:
 *
 *   GET /        the home page (the heap that becomes a plan, the steps, the projects)
 *   GET /about   the story of the project, born at the Meta hackathon
 *   GET /legal   the legal notice (the few facts the admin keeps on /admin/legal)
 *   GET /cgu     the terms of use
 *   GET /privacy the privacy policy
 *   any other    "page not found"
 */
final class HomeController extends Controller
{
    public function home(): never
    {
        View::render('home', 'home.title', ['wide' => true]);
    }

    public function about(): never
    {
        View::render('about', 'about.title');
    }

    public function legal(): never
    {
        $settings = Settings::all(Database::get($this->config));

        View::render('legal', 'legal.title', ['legal' => $settings]);
    }

    public function cgu(): never
    {
        View::render('cgu', 'cgu.title');
    }

    public function privacy(): never
    {
        View::render('privacy', 'privacy.title');
    }

    public function notFound(): never
    {
        http_response_code(404);
        View::render('notfound', 'notfound.title');
    }
}
