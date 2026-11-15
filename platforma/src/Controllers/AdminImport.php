<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\Jobs\ClientImport;
use App\{Db, Spreadsheet};

final class AdminImport extends Base
{
    public function index(Request $r): void
    {
        $this->admin();
        $jobs = Db::all("SELECT * FROM jobs WHERE type = 'clients' ORDER BY id DESC LIMIT 10");
        $this->page('admin/import', ['jobs' => $jobs, 'active' => 'import', 'title' => 'Import klientów', 'scripts' => ['import.js']], 'admin');
    }

    public function upload(Request $r): void
    {
        $u = $this->admin();
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK) {
            $this->flashRedirect('/admin/import', 'Nie udało się wgrać pliku. Sprawdź limit wielkości pliku na serwerze.', 'warn');
        }
        if ((int)$f['size'] > 20 * 1048576) {
            $this->flashRedirect('/admin/import', 'Plik jest za duży (maks. 20 MB).', 'warn');
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        try {
            $rows = Spreadsheet::read((string)$f['tmp_name'], $ext);
        } catch (\Throwable $e) {
            $this->flashRedirect('/admin/import', $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Nie udało się odczytać pliku.', 'warn');
        }
        if (count($rows) < 2) {
            $this->flashRedirect('/admin/import', 'Plik nie zawiera danych (potrzebny wiersz nagłówków i co najmniej jeden wiersz).', 'warn');
        }
        if (count($rows) - 1 > Spreadsheet::MAX_ROWS) {
            $this->flashRedirect('/admin/import', 'Plik ma więcej niż ' . nf(Spreadsheet::MAX_ROWS) . ' wierszy. Podziel go na mniejsze części.', 'warn');
        }
        // wyrównaj liczbę kolumn
        $w = max(array_map('count', $rows));
        $rows = array_map(fn($x) => array_pad($x, $w, ''), $rows);
        $name = bin2hex(random_bytes(12)) . '.csv';
        Spreadsheet::writeCsv(ClientImport::dir() . '/' . $name, $rows);
        $header = $rows[0];
        $payload = ['file' => $name, 'orig' => mb_substr((string)$f['name'], 0, 200), 'header' => $header, 'map' => ClientImport::guessMap($header), 'courses' => [], 'mail' => true];
        $id = Db::insert('jobs', ['type' => 'clients', 'status' => 'new', 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'cursor_pos' => 0, 'total' => count($rows) - 1, 'created_by' => $u['id'], 'created_at' => now(), 'updated_at' => now()]);
        Response::redirect('/admin/import/' . $id);
    }

    private function job(string $id): array
    {
        $j = Db::one("SELECT * FROM jobs WHERE id = ? AND type = 'clients'", [(int)$id]);
        if (!$j) {
            Response::error(404);
        }
        return $j;
    }

    private function mapFrom(Request $r, array $payload): array
    {
        $map = $payload['map'];
        $m = $r->arr('map');
        $w = count($payload['header']);
        foreach (array_keys(ClientImport::FIELDS) as $f) {
            if (isset($m[$f])) {
                $v = (int)$m[$f];
                $map[$f] = ($v >= 0 && $v < $w) ? $v : -1;
            }
        }
        return $map;
    }

    public function progress(Request $r, string $id): void
    {
        $this->admin();
        $j = $this->job($id);
        $p = json_decode((string)$j['payload'], true);
        if ($j['status'] === 'new') {
            $map = $this->mapFrom($r, $p);
            $analysis = $map['email'] >= 0 ? ClientImport::analyze($p, $map) : null;
            $preview = array_slice(\App\Spreadsheet::csv(ClientImport::csvPath($p)), 1, 5);
            $submitted = $r->isPost();
            $sel = $submitted ? array_map('intval', $r->arr('courses')) : [];
            $this->page('admin/import_map', [
                'job' => $j, 'p' => $p, 'map' => $map, 'analysis' => $analysis, 'preview' => $preview, 'sel' => $sel,
                'mail' => $submitted ? $r->str('mail') === '1' : true,
                'courses' => Db::all('SELECT id, title FROM courses ORDER BY sort_order, id'),
                'active' => 'import', 'title' => 'Import klientów',
            ], 'admin');
        }
        $this->page('admin/import_run', ['job' => $j, 'p' => $p, 'state' => ClientImport::state((int)$j['id']), 'result' => json_decode((string)($j['result'] ?: '{}'), true) ?: [], 'active' => 'import', 'title' => 'Import klientów', 'scripts' => ['import.js']], 'admin');
    }

    public function start(Request $r): void
    {
        $this->admin();
        $j = $this->job((string)$r->int('id'));
        if ($j['status'] !== 'new') {
            Response::redirect('/admin/import/' . $j['id']);
        }
        $p = json_decode((string)$j['payload'], true);
        $p['map'] = $this->mapFrom($r, $p);
        if ($p['map']['email'] < 0) {
            $this->flashRedirect('/admin/import/' . $j['id'], 'Wskaż kolumnę z adresem e-mail.', 'warn');
        }
        $p['courses'] = array_values(array_unique(array_filter(array_map('intval', $r->arr('courses')))));
        $p['mail'] = $r->str('mail') === '1';
        Db::update('jobs', ['payload' => json_encode($p, JSON_UNESCAPED_UNICODE), 'status' => 'running', 'result' => json_encode(['added' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => []]), 'updated_at' => now()], 'id = ?', [$j['id']]);
        Response::redirect('/admin/import/' . $j['id']);
    }

    public function tick(Request $r, string $id): void
    {
        $this->admin();
        $j = $this->job($id);
        if ($j['status'] === 'new') {
            Response::json(['ok' => false, 'error' => 'Import nie został uruchomiony.'], 409);
        }
        session_write_close(); // nie blokuj innych żądań admina podczas długiej porcji
        Response::json(['ok' => true] + ClientImport::process((int)$j['id'], 8.0));
    }

    public function errors(Request $r, string $id): void
    {
        $this->admin();
        $j = $this->job($id);
        $res = json_decode((string)($j['result'] ?: '{}'), true) ?: [];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bledy-importu-' . (int)$j['id'] . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['wiersz', 'email', 'problem', 'rodzaj'], ',', '"', '');
        foreach ($res['errors'] ?? [] as $x) {
            fputcsv($out, [$x[0], $x[1], $x[2], 'błąd (pominięty)'], ',', '"', '');
        }
        foreach ($res['warnings'] ?? [] as $x) {
            fputcsv($out, [$x[0], $x[1], $x[2], 'ostrzeżenie'], ',', '"', '');
        }
        exit;
    }
}
