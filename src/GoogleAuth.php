<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * "Sign in with Google" done by the server (OAuth 2.0 authorization code flow,
 * OpenID Connect), with no library:
 *
 *   1. authUrl(): the browser goes to Google with a random state and nonce;
 *   2. Google sends it back to the callback with a one-time code;
 *   3. exchange(): the server trades that code for tokens, with the client secret;
 *   4. claims(): the ID token says who the user is, once its claims are checked.
 *
 * The ID token comes straight from Google's token endpoint over TLS, authenticated
 * with the client secret: OpenID Connect Core 3.1.3.7 allows trusting it without
 * checking its signature. Its claims (issuer, audience, expiry, nonce) are checked.
 */
final class GoogleAuth
{
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Where Google sends the user, asking for their identity, e-mail and name. */
    public static function authUrl(Config $config, string $redirectUri, string $state, string $nonce): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => $config->googleClientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Trades the one-time code for tokens and returns the ID token.
     *
     * @throws AuthError
     */
    public static function exchange(Config $config, string $code, string $redirectUri): string
    {
        $client = curl_init(self::TOKEN);
        if ($client === false) {
            throw new AuthError('curl_init failed');
        }
        curl_setopt_array($client, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $config->googleClientId,
                'client_secret' => $config->googleClientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($client);
        $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
        $error = curl_error($client);
        curl_close($client);

        if ($response === false) {
            throw new AuthError('token endpoint unreachable: ' . $error);
        }
        $body = json_decode((string) $response, true);
        if ($status !== 200 || !is_array($body) || !is_string($body['id_token'] ?? null)) {
            // Google's error codes (invalid_grant, redirect_uri_mismatch...) say what went wrong, never a secret.
            throw new AuthError('token endpoint answered HTTP ' . $status . ': ' . (is_array($body) ? (string) ($body['error'] ?? '') : ''));
        }

        return $body['id_token'];
    }

    /**
     * The user the ID token describes, once its claims hold.
     *
     * @return array{sub: string, email: ?string, name: string, picture: ?string}
     *
     * @throws AuthError
     */
    public static function claims(string $idToken, string $clientId, string $nonce, int $now): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new AuthError('the ID token is not a JWT');
        }
        $payload = json_decode(self::base64url($parts[1]), true);
        if (!is_array($payload)) {
            throw new AuthError('the ID token payload is not JSON');
        }

        $audience = $payload['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $checks = [
            'issuer' => in_array($payload['iss'] ?? null, self::ISSUERS, true),
            'audience' => in_array($clientId, $audiences, true),
            'expiry' => is_numeric($payload['exp'] ?? null) && (int) $payload['exp'] > $now - 60,
            'nonce' => is_string($payload['nonce'] ?? null) && hash_equals($nonce, $payload['nonce']),
            'subject' => is_string($payload['sub'] ?? null) && $payload['sub'] !== '',
        ];
        foreach ($checks as $claim => $holds) {
            if (!$holds) {
                throw new AuthError('the ID token ' . $claim . ' does not match');
            }
        }

        $email = is_string($payload['email'] ?? null) && ($payload['email_verified'] ?? false) === true ? $payload['email'] : null;
        $name = is_string($payload['name'] ?? null) && trim($payload['name']) !== '' ? trim($payload['name']) : ($email ?? '');

        return [
            'sub' => $payload['sub'],
            'email' => $email,
            'name' => $name,
            'picture' => is_string($payload['picture'] ?? null) ? $payload['picture'] : null,
        ];
    }

    private static function base64url(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
