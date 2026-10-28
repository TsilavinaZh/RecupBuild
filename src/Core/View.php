<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * The server-rendered pages (src/Views): a template inside the shared layout, in the
 * visitor's language. Templates get `$e` to escape, `$t` to translate and the data
 * of the page; everything printed goes through `$e` or `$t`, never raw.
 *
 * The language: ?lang=fr|en|mg (remembered in a cookie), else English.
 * Malagasy falls back to French for the texts not written yet.
 */
final class View
{
    private const LOCALES = ['fr', 'en', 'mg'];

    /** @param array<string, mixed> $data */
    public static function render(string $template, string $titleKey, array $data = []): never
    {
        $locale = self::locale();
        $words = self::words($locale);
        $t = static function (string $key, array $params = []) use ($words): string {
            $text = $words[$key] ?? $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string) $value, $text);
            }

            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $content = self::capture(dirname(__DIR__) . '/Views/' . $template . '.php', $data + compact('t', 'e', 'locale'));
        $page = self::capture(dirname(__DIR__) . '/Views/layout.php', [
            't' => $t,
            'e' => $e,
            'locale' => $locale,
            'title' => $t($titleKey),
            'content' => $content,
            'user' => Session::user(),
            'flash' => Session::flash(),
            'current' => $template,
            // The app's pages show where the user is: scan, inventory, projects, plan.
            'step' => $data['step'] ?? null,
            'wide' => $data['wide'] ?? false,
        ]);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; frame-src https://www.youtube-nocookie.com; frame-ancestors 'none'");
        echo $page;
        exit;
    }

    /** The language of the page: asked in the URL, remembered, or English. */
    public static function locale(): string
    {
        $asked = $_GET['lang'] ?? null;
        if (is_string($asked) && in_array($asked, self::LOCALES, true)) {
            setcookie('recupbuild_lang', $asked, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);

            return $asked;
        }
        $saved = $_COOKIE['recupbuild_lang'] ?? null;

        return is_string($saved) && in_array($saved, self::LOCALES, true) ? $saved : Request::locale();
    }

    /** @return array<string, string> */
    private static function words(string $locale): array
    {
        $french = require dirname(__DIR__) . '/Views/lang/fr.php';
        $file = dirname(__DIR__) . '/Views/lang/' . $locale . '.php';

        return $locale === 'fr' || !is_file($file) ? $french : (require $file) + $french;
    }

    /** @param array<string, mixed> $vars */
    private static function capture(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
