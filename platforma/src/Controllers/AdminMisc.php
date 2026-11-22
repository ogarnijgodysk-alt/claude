<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\Mail\MailQueue;
use App\Migration\Report;
use App\{Config, Db, Sanitizer, Settings};

final class AdminMisc extends Base
{
    public function quizzes(Request $r): void
    {
        $this->admin();
        $rows = Db::all("SELECT z.*, (SELECT COUNT(*) FROM quiz_questions q WHERE q.quiz_id = z.id) n,
              (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id = z.id) attempts FROM quizzes z ORDER BY z.id");
        foreach ($rows as &$z) {
            $z['lessons'] = Db::all("SELECT l.id lid, l.title, c.title ctitle FROM blocks b JOIN lessons l ON l.id = b.lesson_id JOIN modules m ON m.id = l.module_id JOIN courses c ON c.id = m.course_id
                WHERE b.type = 'quiz' AND b.data LIKE ?", ['%"quiz_id":' . (int)$z['id'] . '}%']);
        }
        $this->page('admin/quizzes', ['rows' => $rows, 'active' => 'quizy', 'title' => 'Quizy'], 'admin');
    }

    public function results(Request $r): void
    {
        $this->admin();
        $only = $r->str('f') === 'review';
        $rows = Db::all('SELECT a.*, z.title qtitle, u.email, u.full_name FROM quiz_attempts a JOIN quizzes z ON z.id = a.quiz_id JOIN users u ON u.id = a.user_id'
            . ($only ? ' WHERE a.needs_review = 1 AND a.reviewed_at IS NULL' : '') . ' ORDER BY a.id DESC LIMIT 100');
        foreach ($rows as &$a) {
            $a['ans'] = json_decode((string)$a['answers'], true) ?: [];
            $a['quiz'] = \App\Quiz::load((int)$a['quiz_id'], false);
        }
        $this->page('admin/results', ['rows' => $rows, 'only' => $only, 'pending' => (int)Db::val('SELECT COUNT(*) FROM quiz_attempts WHERE needs_review = 1 AND reviewed_at IS NULL'), 'active' => 'wyniki', 'title' => 'Wyniki quizów'], 'admin');
    }

    public function review(Request $r, string $id): void
    {
        $this->admin();
        $a = Db::one('SELECT * FROM quiz_attempts WHERE id = ?', [(int)$id]);
        if (!$a) {
            Response::error(404);
        }
        $passed = $r->str('decision') === 'pass' ? 1 : ($r->str('decision') === 'fail' ? 0 : (int)$a['passed']);
        Db::update('quiz_attempts', ['passed' => $passed, 'reviewed_at' => now()], 'id = ?', [$a['id']]);
        $this->flashRedirect('/admin/wyniki?f=review', 'Zapisano ocenę podejścia #' . $a['id'] . '.');
    }

    public function settings(Request $r): void
    {
        $this->admin();
        $s = [];
        foreach (array_keys(Settings::DEFAULTS) as $k) {
            $s[$k] = Settings::get($k);
        }
        $s['extend_url'] = Settings::get('extend_url');
        $this->page('admin/settings', ['s' => $s, 'mail' => ['transport' => Config::get('mail.transport'), 'host' => Config::get('mail.host')], 'queue' => MailQueue::stats(), 'from' => MailQueue::from(), 'maxUpload' => \App\Uploads::maxBytes('file'), 'active' => 'ustawienia', 'title' => 'Ustawienia'], 'admin');
    }

    public function saveSettings(Request $r): void
    {
        $this->admin();
        $notice = trim((string)($r->post['login_notice'] ?? ''));
        Settings::set('login_notice', mb_substr(Sanitizer::text($notice, 2000), 0, 2000));
        Settings::set('login_mode', in_array($r->str('login_mode'), ['email', 'magic', 'password'], true) ? $r->str('login_mode') : 'email');
        Settings::set('login_notice_on', $r->str('login_notice_on') === '1' ? '1' : '0');
        $ttl = $r->int('reset_ttl', 60);
        Settings::set('reset_ttl', (string)(in_array($ttl, [30, 60, 120], true) ? $ttl : 60));
        $from = mb_strtolower($r->str('mail_from'));
        if ($from !== '' && !valid_email($from)) {
            $this->flashRedirect('/admin/ustawienia', 'Adres nadawcy jest nieprawidłowy.', 'warn');
        }
        Settings::set('mail_from', $from);
        Settings::set('mail_from_name', Sanitizer::text($r->str('mail_from_name'), 100) ?: 'Twoja Akademia');
        Settings::set('site_name', Sanitizer::text($r->str('site_name'), 80) ?: 'Twoja Akademia');
        $pm = $r->int('mail_per_minute', 60);
        Settings::set('mail_per_minute', (string)max(1, min(600, $pm)));
        $mb = $r->int('upload_max_mb', 100);
        Settings::set('upload_max_mb', (string)max(1, min(2048, $mb)));
        $ext = trim($r->str('extend_url'));
        Settings::set('extend_url', ($ext === '' || \App\Sanitizer::safeUrl($ext, ['http', 'https', 'mailto'])) ? $ext : '');
        $this->flashRedirect('/admin/ustawienia', 'Zapisano ustawienia.');
    }

    /** Zmiana własnego hasła przez zalogowanego administratora. */
    public function changePassword(Request $r): void
    {
        $me = $this->admin();
        $cur = is_string($r->post['current'] ?? null) ? $r->post['current'] : '';
        $p1 = is_string($r->post['password'] ?? null) ? $r->post['password'] : '';
        $p2 = is_string($r->post['password2'] ?? null) ? $r->post['password2'] : '';
        if (\App\Security::tooMany('login_email', $me['email'], 8, 900)) {
            $this->flashRedirect('/admin/ustawienia#haslo', 'Zbyt wiele prób. Spróbuj za kilkanaście minut.', 'warn');
        }
        if (!empty($me['password_hash']) && !password_verify($cur, $me['password_hash'])) {
            \App\Security::hit('login_email', $me['email']);
            $this->flashRedirect('/admin/ustawienia#haslo', 'Obecne hasło jest nieprawidłowe.', 'warn');
        }
        if ($err = \App\Auth::passwordError($p1, $p2)) {
            $this->flashRedirect('/admin/ustawienia#haslo', $err, 'warn');
        }
        \App\Auth::setPassword((int)$me['id'], $p1);
        \App\Security::log('password_changed', (int)$me['id'], $me['email'], $r->ip());
        // setPassword unieważnia wszystkie sesje; zaloguj ponownie bieżącą
        \App\Session::login(Db::one('SELECT * FROM users WHERE id = ?', [$me['id']]));
        $this->flashRedirect('/admin/ustawienia#haslo', 'Hasło zostało zmienione.');
    }

    public function migration(Request $r): void
    {
        $this->admin();
        $dir = Config::storage('reports');
        $files = glob($dir . '/raport-zgodnosci-*.html') ?: [];
        rsort($files);
        $remote = 0;
        $local = 0;
        foreach (Db::all("SELECT type, data FROM blocks WHERE type IN ('image','file')") as $b) {
            $d = json_decode((string)$b['data'], true) ?: [];
            if ($b['type'] === 'file') {
                (!empty($d['media_id'])) ? $local++ : (!empty($d['url']) ? $remote++ : null);
            } else {
                foreach ($d['images'] ?? [] as $im) {
                    !empty($im['media_id']) ? $local++ : (!empty($im['src']) ? $remote++ : null);
                }
            }
        }
        $this->page('admin/migration', ['reports' => array_map('basename', array_slice($files, 0, 10)), 'remote' => $remote, 'local' => $local, 'active' => 'migracja', 'title' => 'Migracja z Publigo'], 'admin');
    }

    public function report(Request $r): void
    {
        $this->admin();
        $name = basename($r->str('plik'));
        $dir = Config::storage('reports');
        $f = $dir . '/' . $name;
        if (!preg_match('/^(raport-zgodnosci|pliki-do-pobrania)-[\d-]+\.(html|json|csv)$/', $name) || !is_file($f)) {
            $f = Report::latest($dir) ?? '';
        }
        if (!$f || !is_file($f)) {
            Response::error(404, 'Nie ma jeszcze raportu. Uruchom import: php bin/import-publigo.php --dir=...');
        }
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $mime = ['html' => 'text/html; charset=utf-8', 'json' => 'application/json', 'csv' => 'text/csv; charset=utf-8'][$ext] ?? 'text/plain';
        header('Content-Type: ' . $mime);
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
        if ($ext !== 'html') {
            header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        }
        readfile($f);
        exit;
    }
}
