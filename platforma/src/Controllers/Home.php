<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\Session;

final class Home extends Base
{
    public function index(Request $r): void
    {
        $u = Session::user();
        if (!$u) {
            Response::redirect('/logowanie');
        }
        Response::redirect($u['role'] === 'admin' ? '/admin' : '/moje-kursy');
    }
}
