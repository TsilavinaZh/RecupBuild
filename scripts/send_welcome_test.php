<?php

declare(strict_types=1);

// One-off: send the styled welcome e-mail to a real address (CLI only).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use RecupBuild\Core\Config;
use RecupBuild\Services\Mailer;

$config = Config::load(dirname(__DIR__) . '/.env.php');
$to = $argv[1] ?? 'tsiory.aina69@gmail.com';
$site = 'https://recupbuild.tendry.mg';
$name = 'Tsiory';

// Same body as AuthController::welcomeHtml (kept in sync by hand for this test).
$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeScan = htmlspecialchars($site . '/scan', ENT_QUOTES, 'UTF-8');
$safeSite = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
$font = '-apple-system, BlinkMacSystemFont, Segoe UI, Helvetica, Arial, sans-serif';
$mono = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
$logoBytes = (string) file_get_contents(dirname(__DIR__) . '/images/logo.png');

$text = "Bonjour {$name},\n\n"
    . "Votre compte RecupBuild est prêt : scannez vos planches et obtenez un plan de coupe.\n\n"
    . "Commencer : {$site}/scan\n\n"
    . "— RecupBuild\n{$site}\n";

$html = <<<HTML
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
            <p style="margin:0 0 14px;font-size:16px;line-height:1.55;">Bonjour {$safeName},</p>
            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3d4a44;">
              Votre compte est prêt. Scannez une pile de planches&nbsp;: l’IA mesure le bois,
              découpe les pièces et vous rend un plan de coupe à suivre.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">
              <tr>
                <td style="background:#2f6b4f;border-radius:8px;">
                  <a href="{$safeScan}" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#f6f3ec;text-decoration:none;">
                    Scanner mes planches →
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:18px 0 0;font-size:13px;line-height:1.55;color:#5c6b64;">
              Offre de démarrage&nbsp;: des builds et rebuilds gratuits, à voir sur
              <a href="{$safeSite}/pricing" style="color:#9a4719;">la page des tarifs</a>.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 28px 26px;">
            <p style="margin:0;padding-top:16px;border-top:1px solid #e0d7c6;font-size:12px;line-height:1.5;color:#7a857f;">
              RecupBuild — du tas de bois au plan de coupe.<br>
              <a href="{$safeSite}" style="color:#2f6b4f;">{$safeSite}</a>
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

if (!Mailer::configured($config)) {
    fwrite(STDERR, "SMTP not configured in .env.php\n");
    exit(1);
}

$error = Mailer::send(
    $config,
    $to,
    'Bienvenue sur RecupBuild',
    $text,
    $html,
    $logoBytes === '' ? [] : ['logo' => ['type' => 'image/png', 'data' => $logoBytes]],
);
if ($error !== null) {
    fwrite(STDERR, "FAIL: {$error}\n");
    exit(1);
}

echo "SENT OK -> {$to}\n";
