<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * Settings read from the environment and from backend-php/.env.php (never committed).
 * That file starts with "<?php exit; ?>": if a web server ever runs it, it prints nothing.
 */
final class Config
{
    /** The photo is analysed at this size at most: enough to see the pieces, cheap in tokens. */
    public const MAX_EDGE_PX = 1600;
    public const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;
    public const VISION_TIMEOUT_S = 120;

    public string $metaApiKey = '';
    public string $metaApiBase = 'https://api.meta.ai/v1';
    public string $visionModel = 'muse-spark-1.3';

    /** "Sign in with Google" done by the server (see GoogleAuth). The secret never leaves it. */
    public string $googleClientId = '';
    public string $googleClientSecret = '';
    /** Must be listed as is under "Authorized redirect URIs"; empty: derived from the request. */
    public string $googleRedirectUri = '';

    /** The SQLite file: in the storage folder, outside the site, so it is never served. */
    public string $databasePath = '';

    /** @var list<string> origins allowed by CORS (or "*" for any) */
    public array $allowedOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173'];

    /**
     * $envFile is backend-php/.env.php; a plain .env next to it is still read, for servers
     * already set up to refuse it (deploy/), but .env.php is the one to use.
     */
    public static function load(string $envFile): self
    {
        DotEnv::load($envFile);
        DotEnv::load(preg_replace('/\.php$/', '', $envFile) ?? $envFile);

        $config = new self();
        // One key serves every Meta model. MODEL_API_KEY is the name Meta's documentation
        // uses; MUSE_IMAGE_API_KEY is the one this repository started with.
        $config->metaApiKey = self::read('MODEL_API_KEY') ?: self::read('MUSE_IMAGE_API_KEY');
        $config->metaApiBase = self::read('META_API_BASE', $config->metaApiBase);
        $config->visionModel = self::read('VISION_MODEL', $config->visionModel);

        $config->googleClientId = self::read('GOOGLE_CLIENT_ID');
        $config->googleClientSecret = self::read('GOOGLE_CLIENT_SECRET');
        $config->googleRedirectUri = self::read('GOOGLE_REDIRECT_URI');
        $config->databasePath = self::read('DATABASE_PATH') ?: self::storageDir() . '/recupbuild.sqlite';

        $origins = self::read('ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173');
        $config->allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $origins)), static fn (string $o): bool => $o !== ''));

        return $config;
    }

    /**
     * Where the database and the photos live: STORAGE_PATH, or recupbuild-storage/ next to
     * the project folder. Never inside it: PHP's built-in server serves every file there.
     */
    public static function storageDir(): string
    {
        return rtrim(self::read('STORAGE_PATH') ?: dirname(__DIR__, 3) . '/recupbuild-storage', '/\\');
    }

    private static function read(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}