<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/** What search engines read: the public pages, and the pages that need an account. */
final class Seo
{
    /** @var list<string> */
    private const PUBLIC_PAGES = ['/', '/about', '/pricing', '/legal', '/cgu', '/privacy'];

    /** @var list<string> */
    private const PRIVATE_PAGES = ['/try', '/scan', '/inventory', '/projects', '/plans/', '/rebuild', '/rebuilds/', '/history', '/profile', '/admin', '/auth/', '/api/'];

    public static function origin(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function robots(string $origin): string
    {
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PAGES as $page) {
            $lines[] = 'Disallow: ' . $page;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    public static function sitemap(string $origin): string
    {
        $urls = '';
        foreach (self::PUBLIC_PAGES as $page) {
            $urls .= '  <url><loc>' . htmlspecialchars($origin . $page, ENT_XML1) . "</loc></url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $urls . "</urlset>\n";
    }
}
