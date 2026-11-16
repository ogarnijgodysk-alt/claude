<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Access, Auth, ClientQuery, Db, Sanitizer};

final class AdminClients extends Base
{
    private const PER_PAGE = 20;

    public function index(Request $r): void
    {
        $this->admin();
        $q = $r->str('q');
        $cf = $r->str('kurs');
        $page = max(1, $r->int('p', 1));
        $total = ClientQuery::count($q, $cf);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        [$w, $p] = ClientQuery::where($q, $cf);
        $rows = Db::all('SELECT u.id, u.email, u.full_name, u.role, u.created_at, u.password_hash IS NOT NULL AS has_pw FROM users u WHERE ' . $w . ' ORDER BY u.created_at DESC, u.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $p);
        $enr = [];
        if ($rows) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            foreach (Db::all("SELECT e.user_id, e.expires_at, c.id cid, c.short_title, c.title FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id IN ($ids) ORDER BY c.sort_order") as $e) {
                $enr[$e['user_id']][] = $e;
            }
        }
        $this->page('admin/clients', [
            'rows' => $rows, 'enr' => $enr, 'q' => $q, 'cf' => $cf, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'all' => (int)Db::val('SELECT COUNT(*) FROM users'), 'courses' => Db::all('SELECT id, title, short_title, access_amount, access_unit FROM courses ORDER BY sort_order, id'),
            'active' => 'klienci', 'title' => 'Klienci',
        ], 'admin');
    }

    private function courseList(): array
    {
        $cs = Db::all('SELECT id, title, access_amount, access_unit FROM courses ORDER BY sort_order, id');
        foreach ($cs as &$c) {
            $c['default_exp'] = Access::defaultExpiry($c);
        }
        return $cs;
    }

    /** Odczytuje z formularza przypisania: courses[] + exp[courseId]. @return array<int,?string> */
    private function readAssign(Request $r): array
    {
        $out = [];
        $exp = $r->arr('exp');
        foreach ($r->arr('courses') as $cid) {
            $cid = (int)$cid;
            if (!$cid || !Db::val('SELECT 1 FROM courses WHERE id = ?', [$cid])) {
                continue;
            }
            $d = trim((string)($exp[$cid] ?? ''));
            $out[$cid] = (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) ? $d : null;
        }
        return $out;
    }

    public function add(Request $r): void
    {
        $this->admin();
        $email = mb_strtolower($r->str('email'));
        $first = Sanitizer::text($r->str('first_name'), 120);
        $last = Sanitizer::text($r->str('last_name'), 160);
        if ($first === '' || $last === '') {
            $this->flashRedirect('/admin/klienci', 'Wpisz imię i nazwisko.', 'warn');
        }
        if (!valid_email($email)) {
            $this->flashRedirect('/admin/klienci', 'Wpisz poprawny adres e-mail.', 'warn');
        }
        if (Db::val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $this->flashRedirect('/admin/klienci', 'Klient z tym adresem już jest w bazie.', 'warn');
        }
        $uid = Db::insert('users', ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'full_name' => $first . ' ' . $last, 'role' => 'client', 'created_at' => now()]);
        $this->syncEnrollments($uid, $this->readAssign($r));
        if ($r->str('send_mail') === '1') {
            Auth::sendInvite($uid, 'invite', false);
        }
        $this->flashRedirect('/admin/klienci/' . $uid, 'Dodano klienta: ' . $first . ' ' . $last . ($r->str('send_mail') === '1' ? '. E-mail z linkiem trafił do kolejki wysyłki.' : '.'));
    }

    private function syncEnrollments(int $uid, array $want): void
    {
        $have = array_column(Db::all('SELECT course_id, expires_at FROM enrollments WHERE user_id = ?', [$uid]), 'expires_at', 'course_id');
        foreach ($want as $cid => $exp) {
            if (array_key_exists($cid, $have)) {
                if ($have[$cid] !== $exp) {
                    Db::exec('UPDATE enrollments SET expires_at = ? WHERE user_id = ? AND course_id = ?', [$exp, $uid, $cid]);
                }
            } else {
                Db::insert('enrollments', ['user_id' => $uid, 'course_id' => $cid, 'expires_at' => $exp, 'created_at' => now()]);
            }
        }
        foreach (array_diff_key($have, $want) as $cid => $_) {
            Db::exec('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?', [$uid, $cid]);
        }
    }

    public function edit(Request $r, string $id): void
    {
        $this->admin();
        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int)$id]);
        if (!$u) {
            Response::error(404);
        }
        $enr = [];
        foreach (Db::all('SELECT * FROM enrollments WHERE user_id = ?', [$u['id']]) as $e) {
            $enr[(int)$e['course_id']] = $e;
        }
        $attempts = Db::all('SELECT a.*, z.title FROM quiz_attempts a JOIN quizzes z ON z.id = a.quiz_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 30', [$u['id']]);
        $this->page('admin/client', ['c' => $u, 'enr' => $enr, 'courses' => $this->courseList(), 'attempts' => $attempts, 'active' => 'klienci', 'title' => $u['full_name'] ?: $u['email']], 'admin');
    }

    public function save(Request $r, string $id): void
    {
        $me = $this->admin();
        $u = Db::one('SELECT * FROM users WHERE id = ?', [(int)$id]);
        if (!$u) {
            Response::error(404);
        }
        $email = mb_strtolower($r->str('email'));
        $first = Sanitizer::text($r->str('first_name'), 120);
        $last = Sanitizer::text($r->str('last_name'), 160);
        if (!valid_email($email)) {
            $this->flashRedirect('/admin/klienci/' . $u['id'], 'Wpisz poprawny adres e-mail.', 'warn');
        }
        if (Db::val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
            $this->flashRedirect('/admin/klienci/' . $u['id'], 'Ten adres e-mail należy do innego konta.', 'warn');
        }
        $role = $r->str('role') === 'admin' ? 'admin' : 'client';
        if ((int)$u['id'] === (int)$me['id']) {
            $role = 'admin'; // nie odbieramy sobie roli
        }
        if (mb_strtolower((string)$u['email']) !== $email) {
            Db::exec("UPDATE mail_queue SET status = 'failed', last_error = 'Zmiana adresu e-mail konta' WHERE user_id = ? AND status = 'pending'", [$u['id']]);
            Db::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $u['id']]);
        }
        Db::update('users', ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'full_name' => trim($first . ' ' . $last), 'role' => $role], 'id = ?', [$u['id']]);
        $this->syncEnrollments((int)$u['id'], $this->readAssign($r));
        $this->flashRedirect('/admin/klienci/' . $u['id'], 'Zapisano dane klienta, kursy i daty dostępu.');
    }

    public function invite(Request $r, string $id): void
    {
        $this->admin();
        $u = Db::one('SELECT id FROM users WHERE id = ?', [(int)$id]);
        if (!$u) {
            Response::error(404);
        }
        $mid = Auth::sendInvite((int)$u['id'], 'invite', false);
        \App\Mail\MailQueue::flush(1, false, [$mid]);
        $this->flashRedirect('/admin/klienci/' . $u['id'], 'Wysłano (lub zakolejkowano) e-mail z linkiem do ustawienia hasła.');
    }

    public function bulk(Request $r): void
    {
        $this->admin();
        $op = $r->str('op') === 'del' ? 'del' : 'add';
        $cid = $r->int('course_id');
        $course = Db::one('SELECT * FROM courses WHERE id = ?', [$cid]);
        $back = '/admin/klienci?' . http_build_query(array_filter(['q' => $r->str('q'), 'kurs' => $r->str('kurs')]));
        if (!$course) {
            $this->flashRedirect($back, 'Wybierz kurs.', 'warn');
        }
        $ids = $r->str('all') === '1' ? ClientQuery::ids($r->str('q'), $r->str('kurs')) : array_values(array_unique(array_map('intval', $r->arr('ids'))));
        if (!$ids) {
            $this->flashRedirect($back, 'Nie zaznaczono żadnych klientów.', 'warn');
        }
        $n = 0;
        $ext = 0;
        $exp = Access::defaultExpiry($course);
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            if ($op === 'add') {
                $had = (int)Db::val("SELECT COUNT(*) FROM enrollments WHERE course_id = ? AND user_id IN ($in)", [$cid]);
                $new = count($chunk) - $had;
                // nowe przypisania + przedłużenie istniejących (data nigdy nie jest skracana; bezterminowy zostaje bezterminowy)
                $aff = Db::exec("INSERT INTO enrollments (user_id, course_id, expires_at, created_at) SELECT u.id, ?, ?, ? FROM users u WHERE u.id IN ($in)
                    ON DUPLICATE KEY UPDATE expires_at = CASE WHEN enrollments.expires_at IS NULL THEN NULL WHEN VALUES(expires_at) IS NULL THEN NULL WHEN VALUES(expires_at) > enrollments.expires_at THEN VALUES(expires_at) ELSE enrollments.expires_at END", [$cid, $exp, now()]);
                $n += $new;
                $ext += max(0, (int)(($aff - $new) / 2));
            } else {
                $n += Db::exec("DELETE FROM enrollments WHERE course_id = ? AND user_id IN ($in)", [$cid]);
            }
        }
        $msg = 'Kurs „' . $course['title'] . '” ' . ($op === 'add' ? 'przypisano' : 'odebrano') . ': ' . nf($n) . ' ' . ($n === 1 ? 'klient' : 'klientów')
            . ($op === 'add' ? ($ext ? ', przedłużono dostęp: ' . nf($ext) : '') . ' · ' . ($exp ? 'dostęp do ' . fmt_date($exp) : 'dostęp bezterminowy') : '');
        $this->flashRedirect($back, $msg);
    }
}
