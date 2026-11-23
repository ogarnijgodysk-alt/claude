<?php
declare(strict_types=1);

use App\Controllers\{AdminClients, AdminCourses, AdminImport, AdminMisc, AuthController, FilesController, Home, StudentController};

return function (App\Router $r): void {
    $r->get('/', [Home::class, 'index']);
    $r->get('/logowanie', [AuthController::class, 'loginForm']);
    $r->post('/logowanie', [AuthController::class, 'login']);
    $r->post('/logowanie/email', [AuthController::class, 'emailLogin']);
    $r->post('/logowanie/link', [AuthController::class, 'linkRequest']);
    $r->get('/logowanie/link/{token}', [AuthController::class, 'linkConfirmForm']);
    $r->post('/logowanie/link/{token}', [AuthController::class, 'linkConfirm']);
    $r->post('/wyloguj', [AuthController::class, 'logout']);
    $r->get('/haslo', [AuthController::class, 'forgotForm']);
    $r->post('/haslo', [AuthController::class, 'forgot']);
    $r->get('/haslo/{token}', [AuthController::class, 'resetForm']);
    $r->post('/haslo/{token}', [AuthController::class, 'reset']);

    $r->get('/moje-kursy', [StudentController::class, 'courses']);
    $r->get('/kurs/{id}', [StudentController::class, 'course']);
    $r->get('/kurs/{id}/lekcja/{lid}', [StudentController::class, 'lesson']);
    $r->post('/lekcja/{lid}/ukoncz', [StudentController::class, 'toggleDone']);
    $r->post('/quiz/{bid}/wyslij', [StudentController::class, 'quizSubmit']);
    $r->get('/plik/{id}', [FilesController::class, 'download']);
    $r->get('/obraz/{id}', [FilesController::class, 'image']);

    // panel admina
    $r->get('/admin', [AdminCourses::class, 'index']);
    $r->post('/admin/kursy', [AdminCourses::class, 'create']);
    $r->get('/admin/kurs/{id}', [AdminCourses::class, 'editor']);
    $r->get('/admin/lekcja/{id}', [AdminCourses::class, 'lessonRedirect']);
    $r->post('/admin/api/{action}', [AdminCourses::class, 'api']);
    $r->post('/admin/upload', [AdminCourses::class, 'upload']);
    $r->get('/admin/quizy', [AdminMisc::class, 'quizzes']);
    $r->get('/admin/wyniki', [AdminMisc::class, 'results']);
    $r->post('/admin/wyniki/{id}/ocena', [AdminMisc::class, 'review']);

    $r->get('/admin/klienci', [AdminClients::class, 'index']);
    $r->post('/admin/klienci/dodaj', [AdminClients::class, 'add']);
    $r->get('/admin/klienci/{id}', [AdminClients::class, 'edit']);
    $r->post('/admin/klienci/{id}', [AdminClients::class, 'save']);
    $r->post('/admin/klienci/{id}/zaproszenie', [AdminClients::class, 'invite']);
    $r->post('/admin/klienci-hurt', [AdminClients::class, 'bulk']);

    $r->get('/admin/import', [AdminImport::class, 'index']);
    $r->post('/admin/import/plik', [AdminImport::class, 'upload']);
    $r->post('/admin/import/start', [AdminImport::class, 'start']);
    $r->get('/admin/import/{id}', [AdminImport::class, 'progress']);
    $r->post('/admin/import/{id}', [AdminImport::class, 'progress']);
    $r->post('/admin/import/{id}/tick', [AdminImport::class, 'tick']);
    $r->get('/admin/import/{id}/bledy', [AdminImport::class, 'errors']);

    $r->get('/admin/ustawienia', [AdminMisc::class, 'settings']);
    $r->post('/admin/ustawienia', [AdminMisc::class, 'saveSettings']);
    $r->post('/admin/haslo', [AdminMisc::class, 'changePassword']);
    $r->get('/admin/migracja', [AdminMisc::class, 'migration']);
    $r->post('/admin/migracja/pliki', [AdminMisc::class, 'migrationUpload']);
    $r->post('/admin/migracja/import', [AdminMisc::class, 'migrationRun']);
    $r->post('/admin/migracja/usun', [AdminMisc::class, 'migrationDelete']);
    $r->get('/admin/migracja/raport', [AdminMisc::class, 'report']);
};
