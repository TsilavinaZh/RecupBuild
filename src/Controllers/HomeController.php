<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\View;

/**
 * The public pages:
 *
 *   GET /        the home page (the heap that becomes a plan, the steps, the projects)
 *   GET /about   the story of the project, born at the Meta hackathon
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

    public function notFound(): never
    {
        http_response_code(404);
        View::render('notfound', 'notfound.title');
    }
}
