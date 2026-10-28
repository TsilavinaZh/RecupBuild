<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Http;
use RecupBuild\Core\Session;
use RecupBuild\Errors\AuthError;
use RecupBuild\Models\Settings;
use RecupBuild\Models\Users;
use RecupBuild\Services\GoogleAuth;
use RecupBuild\Services\Mailer;

/**
 * "Sign in with Google" done by the server (see Services/GoogleAuth):
 *
 *   GET  /auth/google           redirects to Google (?return=/path to come back to)
 *   GET  /auth/google/callback  where Google sends the user back
 *   GET  /api/me                the signed-in user, or 401
 *   POST /auth/logout           signs out
 */
final class AuthController extends Controller
{
    public function start(): never
    {
        if ($this->config->googleClientId === '' || $this->config->googleClientSecret === '') {
            Http::fail('auth_not_configured', 503);
        }
        Session::start();
        $_SESSION['oauth'] = [
            'state' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(16)),
            'return' => self::localPath($_GET['return'] ?? '/'),
        ];
        header('Location: ' . GoogleAuth::authUrl($this->config, $this->redirectUri(), $_SESSION['oauth']['state'], $_SESSION['oauth']['nonce']), true, 302);
        exit;
    }

    public function callback(): never
    {
        Session::start();
        $pending = $_SESSION['oauth'] ?? null;
        unset($_SESSION['oauth']);
        $back = is_array($pending) ? $pending['return'] : '/';

        // The state proves the answer belongs to the sign-in this browser started.
        $state = $_GET['state'] ?? null;
        if (!is_array($pending) || !is_string($state) || !hash_equals($pending['state'], $state)) {
            self::failed($back, 'state missing or different');
        }
        $code = $_GET['code'] ?? null;
        if (!is_string($code) || $code === '') {
            self::failed($back, 'no code (' . (is_string($_GET['error'] ?? null) ? $_GET['error'] : 'unknown') . ')');
        }

        try {
            $idToken = GoogleAuth::exchange($this->config, $code, $this->redirectUri());
            $google = GoogleAuth::claims($idToken, $this->config->googleClientId, $pending['nonce'], time());
            // The account is kept in the database: created at the first sign-in, refreshed after.
            // ADMIN_EMAILS says which accounts open /admin; the free offer comes from the
            // settings the admin keeps on /admin/pricing (granted at the first sign-in only).
            $pdo = Database::get($this->config);
            $isAdmin = in_array(strtolower($google['email'] ?? ''), $this->config->adminEmails, true);
            $user = Users::signedIn($pdo, $google, null, $isAdmin, Settings::freeCredits($pdo));
            Session::signIn($user);
            // The one and only e-mail of the site: welcome at the first sign-in (never blocks it).
            if (!empty($user['first'])) {
                self::welcome($user);
            }
        } catch (AuthError $error) {
            self::failed($back, $error->getMessage());
        } catch (\PDOException $error) {
            self::failed($back, 'database: ' . $error->getMessage());
        }
        header('Location: ' . $back, true, 302);
        exit;
    }

    public function me(): never
    {
        header('Cache-Control: no-store');
        $user = Session::user();
        if ($user === null) {
            Http::fail('unauthorized', 401);
        }
        Http::json(200, ['user' => $user]);
    }

    public function logout(): never
    {
        Session::signOut();
        http_response_code(204);
        exit;
    }

    /** Where Google sends the user back: the configured URI, or this very server's callback. */
    private function redirectUri(): string
    {
        if ($this->config->googleRedirectUri !== '') {
            return $this->config->googleRedirectUri;
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/google/callback';
    }

    /** A path of this site to come back to after sign-in: never another site. */
    private static function localPath(mixed $value): string
    {
        return is_string($value) && preg_match('#^/(?!/)[^\\\s]*$#', $value) === 1 ? $value : '/';
    }

    /** Back where the user came from, saying the sign-in did not go through. */
    private static function failed(string $back, string $why): never
    {
        error_log('[recupbuild] Google sign-in failed: ' . $why);
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'auth=failed', true, 302);
        exit;
    }

    /**
     * The welcome e-mail at the first sign-in, over SMTP (Services\Mailer): a
     * small styled card in the look of the site (paper, forest, copper), with a
     * plain-text twin for clients that refuse HTML. It never breaks the sign-in:
     * a failure is only written to the log. No e-mail address, no SMTP: nothing.
     */
    private function welcome(array $user): void
    {
        $to = (string) ($user['email'] ?? '');
        if ($to === '' || !Mailer::configured($this->config)) {
            return;
        }
        $name = trim((string) ($user['name'] ?? ''));
        $site = $this->siteOrigin();
        $scan = $site . '/scan';
        $safeName = htmlspecialchars($name !== '' ? $name : 'à vous', ENT_QUOTES, 'UTF-8');
        $safeScan = htmlspecialchars($scan, ENT_QUOTES, 'UTF-8');
        $safeSite = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');

        $text = "Bonjour {$name},\n\n"
            . "Votre compte RecupBuild est prêt : scannez vos planches et obtenez un plan de coupe.\n\n"
            . "Commencer : {$scan}\n\n"
            . "— RecupBuild\n{$site}\n";
        $html = $this->welcomeHtml($safeName, $safeScan, $safeSite);
        // The logo is attached by cid: clients strip data URIs, they show this one.
        $logo = $this->logoBytes();

        $error = Mailer::send(
            $this->config,
            $to,
            'Bienvenue sur RecupBuild',
            $text,
            $html,
            $logo === null ? [] : ['logo' => ['type' => 'image/png', 'data' => $logo]],
        );
        if ($error !== null) {
            error_log('[recupbuild] welcome e-mail failed: ' . $error);
        }
    }

    /**
     * The styled body of the welcome e-mail: every style inlined (mail clients
     * drop <style> blocks), the colours and type of the site, the logo in the
     * header, one clear button.
     */
    private function welcomeHtml(string $name, string $scan, string $site): string
    {
        $font = '-apple-system, BlinkMacSystemFont, Segoe UI, Helvetica, Arial, sans-serif';
        $mono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';

        return <<<HTML
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#e8dfcf;font-family:{$font};color:#1f2a24;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8dfcf;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#f6f3ec;border:1px solid #d4cbb8;border-radius:14px;overflow:hidden;">
        <tr>
          <td style="background:#2f6b4f;padding:22px 28px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              <tr>
                <td style="padding-right:12px;vertical-align:middle;">
                  <img src="cid:logo" alt="" width="40" height="40" style="display:block;border:0;border-radius:10px;">
                </td>
                <td style="vertical-align:middle;">
                  <p style="margin:0;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#c9ddd1;font-family:{$mono};">RecupBuild</p>
                  <p style="margin:6px 0 0;font-size:22px;font-weight:700;color:#f6f3ec;">Bienvenue sur RecupBuild</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 28px 8px;">
            <p style="margin:0 0 14px;font-size:16px;line-height:1.55;">Bonjour {$name},</p>
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3d4a44;">
              Votre compte est prêt. Scannez une pile de planches&nbsp;: l’IA mesure le bois,
              découpe les pièces et vous rend un plan de coupe à suivre.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
              <tr>
                <td style="background:#2f6b4f;border-radius:8px;">
                  <a href="{$scan}" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#f6f3ec;text-decoration:none;">
                    Scanner mes planches →
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:18px 0 0;font-size:13px;line-height:1.55;color:#5c6b64;">
              Offre de démarrage&nbsp;: des builds et rebuilds gratuits, à voir sur
              <a href="{$site}/pricing" style="color:#9a4719;">la page des tarifs</a>.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px 26px;">
            <p style="margin:0;padding-top:16px;border-top:1px solid #e0d7c6;font-size:12px;line-height:1.5;color:#7a857f;">
              RecupBuild — du tas de bois au plan de coupe.<br>
              <a href="{$site}" style="color:#2f6b4f;">{$site}</a>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /** The logo (images/logo.png, drawn from favicon.svg) as bytes for the cid: picture. */
    private function logoBytes(): ?string
    {
        $file = dirname(__DIR__, 2) . '/images/logo.png';
        $bytes = is_file($file) ? file_get_contents($file) : false;

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /** The origin of this site (https://host), for the links inside the e-mail. */
    private function siteOrigin(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] !== 'off');

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
