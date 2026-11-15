<?php
declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $tpl, array $vars = [], ?string $layout = null): string
    {
        $content = self::partial($tpl, $vars);
        if ($layout === null) {
            return $content;
        }
        $vars['content'] = $content;
        return self::partial('layout/' . $layout, $vars);
    }

    public static function partial(string $tpl, array $vars = []): string
    {
        $file = APP_ROOT . '/views/' . $tpl . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Brak widoku: ' . $tpl);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        return (string)ob_get_clean();
    }
}
