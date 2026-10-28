<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Session;
use RecupBuild\Core\View;
use RecupBuild\Models\Plans;
use RecupBuild\Models\Rebuilds;
use RecupBuild\Models\Settings;
use RecupBuild\Models\Users;

/**
 * The back office, for the few accounts listed in ADMIN_EMAILS (.env.php). Its own
 * area, with a sidebar (src/Views/admin/_nav.php), kept apart from the app:
 *
 *   GET  /admin                      dashboard: the site at a glance, latest activity
 *   GET  /admin/users                every account, their AI credits, a form to set them
 *   POST /admin/users/{id}/credits   saves those credits
 *   GET  /admin/pricing              the free offer and the Pro price the site shows
 *   POST /admin/pricing              saves them
 *   GET  /admin/legal                the facts of the legal notice shown on /legal
 *   POST /admin/legal                saves them
 *
 * Without a session the pages send the visitor to Google and back here; a signed-in
 * visitor who is not an administrator is sent away as if the area did not exist.
 * Every form carries the session's CSRF token.
 */
final class AdminController extends Controller
{
    public function dashboard(): never
    {
        $this->admin('/admin');
        $pdo = Database::get($this->config);

        View::render('admin/dashboard', 'admin.dashboard.title', [
            'stats' => Users::stats($pdo),
            'accounts' => array_slice(Users::all($pdo), 0, 8),
            'plans' => Plans::latest($pdo, 8),
            'rebuilds' => Rebuilds::latest($pdo, 8),
            'free' => Settings::freeCredits($pdo),
            'activity' => self::activity($pdo),
            'top' => Users::topBuilders($pdo),
        ]);
    }

    public function users(): never
    {
        $this->admin('/admin/users');

        View::render('admin/users', 'admin.users.title', [
            'accounts' => Users::all(Database::get($this->config)),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function setCredits(int $id): never
    {
        $this->admin('/admin/users');
        $this->checkForm('/admin/users');
        $build = self::amount($_POST['build'] ?? null);
        $rebuild = self::amount($_POST['rebuild'] ?? null);
        $saved = $build !== null && $rebuild !== null
            && Users::setCredits(Database::get($this->config), $id, $build, $rebuild);
        Session::flash($saved ? 'admin.creditsSaved' : 'admin.userNotFound');
        self::redirect('/admin/users');
    }

    public function pricing(): never
    {
        $this->admin('/admin/pricing');
        $pdo = Database::get($this->config);

        View::render('admin/pricing', 'admin.pricing.title', [
            'settings' => Settings::all($pdo),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function savePricing(): never
    {
        $this->admin('/admin/pricing');
        $this->checkForm('/admin/pricing');
        $build = self::amount($_POST['free_build_credits'] ?? null);
        $rebuild = self::amount($_POST['free_rebuild_credits'] ?? null);
        $price = self::text($_POST['pro_price'] ?? null);
        if ($build !== null && $rebuild !== null && $price !== null) {
            Settings::save(Database::get($this->config), [
                'free_build_credits' => (string) $build,
                'free_rebuild_credits' => (string) $rebuild,
                'pro_price' => $price,
            ]);
            Session::flash('admin.saved');
        } else {
            Session::flash('admin.invalid');
        }
        self::redirect('/admin/pricing');
    }

    public function legal(): never
    {
        $this->admin('/admin/legal');
        $pdo = Database::get($this->config);

        View::render('admin/legal', 'admin.legal.title', [
            'settings' => Settings::all($pdo),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function saveLegal(): never
    {
        $this->admin('/admin/legal');
        $this->checkForm('/admin/legal');
        $host = self::text($_POST['legal_host'] ?? null);
        $url = self::text($_POST['legal_host_url'] ?? null);
        $contact = self::text($_POST['legal_contact'] ?? null);
        if ($host !== null && $url !== null && $contact !== null && ($contact === '' || filter_var($contact, FILTER_VALIDATE_EMAIL) !== false)) {
            Settings::save(Database::get($this->config), [
                'legal_host' => $host,
                'legal_host_url' => $url,
                'legal_contact' => $contact,
            ]);
            Session::flash('admin.saved');
        } else {
            Session::flash('admin.invalid');
        }
        self::redirect('/admin/legal');
    }

    /** The signed-in administrator, or off to Google; anyone else goes back home. */
    private function admin(string $return): array
    {
        $user = $this->member($return);
        if (!Users::isAdmin(Database::get($this->config), $user['id'])) {
            self::redirect('/');
        }

        return $user;
    }

    /** A credit count posted by the admin: a whole number between 0 and 999. */
    private static function amount(mixed $value): ?int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999]]);

        return $number === false ? null : $number;
    }

    /** A short line of plain text posted by the admin (a price, a host, an e-mail). */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);

        return mb_strlen($text) <= 120 ? $text : null;
    }

    /**
     * Plans and reverse builds per day over the last days, oldest first: the bars
     * of the dashboard's chart. The table names come from this file only.
     *
     * @return list<array{day: string, plans: int, rebuilds: int}>
     */
    private static function activity(\PDO $pdo, int $days = 14): array
    {
        $days = max(7, min(31, $days));
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400) . 'T00:00:00Z';
        $counts = ['plans' => [], 'rebuilds' => []];
        foreach ($counts as $table => $_) {
            $statement = $pdo->prepare("SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS n FROM {$table} WHERE created_at >= :since GROUP BY day");
            $statement->execute(['since' => $since]);
            foreach ($statement->fetchAll() as $row) {
                $counts[$table][(string) $row['day']] = (int) $row['n'];
            }
        }

        $slots = [];
        for ($offset = $days - 1; $offset >= 0; --$offset) {
            $day = gmdate('Y-m-d', time() - $offset * 86400);
            $slots[] = ['day' => $day, 'plans' => $counts['plans'][$day] ?? 0, 'rebuilds' => $counts['rebuilds'][$day] ?? 0];
        }

        return $slots;
    }
}
