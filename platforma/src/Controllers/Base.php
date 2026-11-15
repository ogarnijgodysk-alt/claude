<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\{Request, Response};
use App\{Auth, Session, Settings, View};

abstract class Base
{
    protected function page(string $tpl, array $vars, string $layout): never
    {
        $vars += ['user' => Session::user(), 'flash' => Session::flash(), 'siteName' => Settings::get('site_name', 'Twoja Akademia')];
        Response::html(View::render($tpl, $vars, $layout));
    }

    protected function admin(): array
    {
        return Auth::requireAdmin();
    }

    protected function flashRedirect(string $to, string $msg, string $type = 'ok'): never
    {
        Session::flash($msg, $type);
        Response::redirect($to);
    }
}
