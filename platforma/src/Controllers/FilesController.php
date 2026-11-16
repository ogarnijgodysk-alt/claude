<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Access, Auth, Db, Uploads};

final class FilesController extends Base
{
    /** Czy użytkownik ma prawo do pliku: admin, albo zapisany na kurs (nieprzeterminowany), w którym plik występuje. */
    private function allowed(array $user, int $mediaId): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        $courses = array_map(fn($id) => ['course_id' => $id], Uploads::usage($mediaId)['courses']);
        foreach ($courses as $c) {
            if (Access::canEnter($user, (int)$c['course_id'])) {
                return true;
            }
        }
        return false;
    }

    private function serve(int $id, bool $inlineImage): never
    {
        $u = Auth::requireLogin();
        $m = Db::one('SELECT * FROM media WHERE id = ?', [$id]);
        if (!$m || !$this->allowed($u, $id)) {
            Response::error(404);
        }
        $isImage = in_array($m['mime'], ['image/jpeg', 'image/png', 'image/gif'], true);
        if ($inlineImage && !$isImage) {
            Response::error(404);
        }
        Response::file(Uploads::path($m), $m['original_name'], $m['mime'], !$inlineImage);
    }

    public function download(Request $r, string $id): void
    {
        $this->serve((int)$id, false);
    }

    public function image(Request $r, string $id): void
    {
        $this->serve((int)$id, true);
    }
}
