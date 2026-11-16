<?php
declare(strict_types=1);
/** Testy jednostkowe bez bazy: php tests/run.php */
require __DIR__ . '/../src/bootstrap.php';

use App\{Access, Gutenberg, Phpass, Quiz, Sanitizer, Spreadsheet, Uploads, Video};
use App\Jobs\ClientImport;
use App\Mail\Smtp;

$pass = 0;
$fail = 0;
function ok(bool $c, string $name): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $name\n";
    }
}
function same(mixed $a, mixed $b, string $name): void
{
    ok($a === $b, $name . ' (got ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ', want ' . json_encode($b, JSON_UNESCAPED_UNICODE) . ')');
}

// --- Video
same(Video::vimeo('https://vimeo.com/824517390'), ['id' => '824517390', 'hash' => null], 'vimeo id');
same(Video::vimeo('https://vimeo.com/980820359/f27a0ab3ee?share=copy'), ['id' => '980820359', 'hash' => 'f27a0ab3ee'], 'vimeo private hash');
same(Video::vimeo('https://player.vimeo.com/video/600844717?badge=0&amp;autopause=0&amp;h=c975e441c0'), ['id' => '600844717', 'hash' => 'c975e441c0'], 'vimeo player + h');
same(Video::vimeo('https://vimeo.com/channels/staffpicks/123456789')['id'] ?? null, '123456789', 'vimeo channels');
same(Video::vimeo('https://example.com/123456789'), null, 'vimeo foreign');
foreach (['https://www.youtube.com/watch?v=M7lc1UVf-VE', 'https://youtu.be/M7lc1UVf-VE?t=3', 'https://www.youtube.com/embed/M7lc1UVf-VE', 'https://youtube.com/shorts/M7lc1UVf-VE', 'https://www.youtube.com/watch?feature=share&v=M7lc1UVf-VE'] as $u) {
    same(Video::youtube($u), 'M7lc1UVf-VE', 'yt ' . $u);
}
ok(str_contains(Video::youtubeEmbed('M7lc1UVf-VE'), 'youtube-nocookie.com'), 'yt nocookie');
same(Video::genericEmbed('https://open.spotify.com/embed/episode/51A47vw5bSKZDRMXI8Rdcy?utm_source=generator'), 'https://open.spotify.com/embed/episode/51A47vw5bSKZDRMXI8Rdcy', 'spotify');
same(Video::genericEmbed('https://evil.example/embed/episode/51A47vw5bSKZDRMXI8Rdcy'), null, 'embed whitelist');

// --- Sanitizer
$x = Sanitizer::html('<p onclick="x()">Hej <script>alert(1)</script><b style="color:red">tekst</b> <a href="javascript:alert(1)">zły</a> <a href="https://ok.pl">dobry</a><iframe src="https://evil"></iframe><img src="x" onerror="alert(1)"><img src="https://a.pl/i.png"></p>');
ok(!str_contains($x, 'script') && !str_contains($x, 'onclick') && !str_contains($x, 'javascript:') && !str_contains($x, 'iframe') && !str_contains($x, 'onerror') && !str_contains($x, 'style='), 'sanitizer strips dangerous: ' . $x);
ok(str_contains($x, 'href="https://ok.pl"') && str_contains($x, 'rel="noopener noreferrer"'), 'sanitizer keeps safe link');
ok(str_contains(Sanitizer::html('<table><tr><td colspan="2">a</td></tr></table>'), '<table>'), 'sanitizer keeps tables');
ok(!str_contains(Sanitizer::html('<h1>T</h1>'), '<h1'), 'h1 -> h2');
same(Sanitizer::text('<b>A</b> &amp; B   c'), 'A & B c', 'text()');
ok(Sanitizer::isEmptyHtml('<p>&nbsp;</p>') && !Sanitizer::isEmptyHtml('<p><img src="https://a/b.png"></p>'), 'isEmptyHtml');
same(Sanitizer::safeUrl('JaVaScRiPt:alert(1)'), null, 'safeUrl js');
same(Sanitizer::safeUrl('data:text/html;base64,AAA'), null, 'safeUrl data');
same(Sanitizer::safeUrl('/plik/5'), '/plik/5', 'safeUrl relative');

// --- Gutenberg
$g = Gutenberg::convert('<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->
<!-- wp:embed {"url":"https://vimeo.com/111111111/abcdef1234?share=copy","providerNameSlug":"vimeo"} --><figure><div>https://vimeo.com/111111111</div></figure><!-- /wp:embed -->
<!-- wp:paragraph --><p>B [trenerka]</p><!-- /wp:paragraph -->
<!-- wp:embed {"url":"https://youtu.be/M7lc1UVf-VE","providerNameSlug":"youtube"} --><figure></figure><!-- /wp:embed -->
<!-- wp:html --><iframe src="https://player.vimeo.com/video/222222222?h=aabbccdd11&amp;badge=0"></iframe><!-- /wp:html -->
<!-- wp:gallery --><figure class="wp-block-gallery"><!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="https://cdn.idealms.net/a.png" alt="a"/></figure><!-- /wp:image --><!-- wp:image {"id":2} --><figure class="wp-block-image"><img src="https://cdn.idealms.net/b.png" alt="b"/><figcaption>Podpis</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->
<!-- wp:file {"id":9,"href":"https://cdn.idealms.net/x.pdf"} --><div class="wp-block-file"><a href="https://cdn.idealms.net/x.pdf">Karta</a><object data="x"></object></div><!-- /wp:file -->
<!-- wp:html --><iframe src="https://open.spotify.com/embed/episode/51A47vw5bSKZDRMXI8Rdcy"></iframe><!-- /wp:html -->
<!-- wp:table --><figure class="wp-block-table"><table><tr><td>t</td></tr></table></figure><!-- /wp:table -->
<!-- wp:embed {"url":"https://nelson-x.pl/p","providerNameSlug":"x"} --><figure></figure><!-- /wp:embed -->');
same(array_map(fn($b) => $b['type'], $g['blocks']), ['text', 'vimeo', 'text', 'youtube', 'vimeo', 'image', 'file', 'embed', 'text'], 'gutenberg order/types');
same(count($g['blocks'][5]['data']['images']), 2, 'gallery 2 images');
same($g['blocks'][5]['data']['images'][1]['caption'], 'Podpis', 'gallery caption');
ok(!str_contains($g['blocks'][2]['content'], 'trenerka'), 'shortcode removed');
ok(str_contains($g['blocks'][8]['content'], '<table>') && str_contains($g['blocks'][8]['content'], 'nelson-x.pl'), 'table kept + embed as link');
same($g['blocks'][6]['data']['name'], 'Karta', 'file name');
$types = array_column($g['warnings'], 'type');
ok(in_array('shortcode_trenerka', $types) && in_array('spotify_osadzenie', $types) && in_array('osadzenie_jako_link', $types), 'warnings');
same(Gutenberg::convert('<!-- wp:paragraph --><p>&nbsp;</p><!-- /wp:paragraph -->')['blocks'], [], 'empty lesson -> no blocks');

// --- Access
same(Access::defaultExpiry(['access_amount' => 12, 'access_unit' => 'months'], '2026-11-15'), '2027-11-15', '12 months');
same(Access::defaultExpiry(['access_amount' => 2, 'access_unit' => 'years'], '2026-11-15'), '2028-11-15', '2 years');
same(Access::defaultExpiry(['access_amount' => null, 'access_unit' => 'months'], '2026-11-15'), null, 'unlimited');
same(Access::status(null)['state'], 'unlimited', 'status unlimited');
same(Access::status(date('Y-m-d', strtotime('-1 day')))['state'], 'expired', 'status expired');
same(Access::status(today())['state'], 'soon', 'last day still valid');
same(Access::status(date('Y-m-d', strtotime('+30 days')))['state'], 'soon', '30 days = soon');
same(Access::status(date('Y-m-d', strtotime('+31 days')))['state'], 'active', '31 days = active');

// --- Quiz
$quiz = ['pass_percent' => 70, 'questions' => [
    ['id' => 1, 'type' => 'single', 'answers' => [['id' => 10, 'is_correct' => 0], ['id' => 11, 'is_correct' => 1]]],
    ['id' => 2, 'type' => 'multi', 'answers' => [['id' => 20, 'is_correct' => 1], ['id' => 21, 'is_correct' => 1], ['id' => 22, 'is_correct' => 0]]],
    ['id' => 3, 'type' => 'text', 'answers' => []],
]];
$r = Quiz::grade($quiz, [1 => [11], 2 => [20, 21]]);
same([$r['pct'], $r['pass'], $r['manual']], [100, true, 1], 'quiz all correct');
$r = Quiz::grade($quiz, [1 => [11], 2 => [20]]);
same([$r['pct'], $r['pass']], [50, false], 'quiz partial multi is wrong');
$r = Quiz::grade($quiz, [1 => [10], 2 => [20, 21, 22]]);
same($r['pct'], 0, 'quiz all wrong');

// --- Phpass (wektor z biblioteki phpass)
ok(Phpass::check('test12345', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'), 'phpass vector');
ok(!Phpass::check('zle', '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0'), 'phpass wrong');

// --- Spreadsheet
same(Spreadsheet::parseDate('15.03.2027'), '2027-03-15', 'date pl');
same(Spreadsheet::parseDate('2027-3-5'), '2027-03-05', 'date iso loose');
same(Spreadsheet::parseDate('46100'), gmdate('Y-m-d', (46100 - 25569) * 86400), 'date excel serial');
same(Spreadsheet::parseDate('31.02.2027'), null, 'date invalid');
$tmp = sys_get_temp_dir() . '/t' . bin2hex(random_bytes(4)) . '.xlsx';
$z = new ZipArchive();
$z->open($tmp, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<Types/>');
$z->addFromString('xl/sharedStrings.xml', '<sst><si><t>email</t></si><si><t>imie</t></si><si><t>a@b.pl</t></si><si><t>Łukasz</t></si></sst>');
$z->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1"><v>5</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c><c r="C2" t="inlineStr"><is><t>x</t></is></c></row></sheetData></worksheet>');
$z->close();
same(Spreadsheet::xlsx($tmp), [['email', 'imie', '', '5'], ['a@b.pl', 'Łukasz', 'x']], 'xlsx read');
unlink($tmp);

// --- ClientImport::parseCourses
$titles = ['ai creator brand od zera' => [1 => 1], 'tiktok shop' => [2 => 2], 'meta ads, dla sklepów' => [3 => 3], 'szkoła psiemian' => [4 => 4, 5 => 5]];
same(ClientImport::parseCourses('AI Creator Brand od zera, TikTok Shop', $titles), [[1, 2], []], 'courses by comma');
same(ClientImport::parseCourses('Meta Ads, dla sklepów', $titles), [[3], []], 'course title containing comma');
same(ClientImport::parseCourses('Nie ma, TikTok Shop', $titles)[1], ['Nie ma'], 'unknown course');
same(count(ClientImport::parseCourses('Szkoła Psiemian', $titles)[1]), 1, 'ambiguous title reported');

// --- Smtp::build
$m = Smtp::build('a@b.pl', 'Zażółć gęślą', "Treść\nlinia", '<p>x</p>', 'kursy@x.pl', 'Akademia');
ok(str_contains($m, 'multipart/alternative') && str_contains($m, 'Subject: =?UTF-8?B?') && str_contains($m, 'To: <a@b.pl>'), 'smtp message headers');

// --- Uploads::verify
$png = sys_get_temp_dir() . '/v.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
ok(Uploads::verify($png, 'png', Uploads::detect($png)) === 'image/png', 'real png ok');
ok(Uploads::verify($png, 'jpg', Uploads::detect($png)) === null, 'png as jpg rejected');
ok(Uploads::verify($png, 'pdf', Uploads::detect($png)) === null, 'png as pdf rejected');
file_put_contents($png, '<?php echo 1;');
ok(Uploads::verify($png, 'png', Uploads::detect($png)) === null && Uploads::verify($png, 'pdf', Uploads::detect($png)) === null && Uploads::verify($png, 'docx', Uploads::detect($png)) === null, 'php disguised rejected');
unlink($png);

// --- regresje z przeglądu kodu
$tmp = sys_get_temp_dir() . '/t' . bin2hex(random_bytes(4)) . '.xlsx';
$z = new ZipArchive();
$z->open($tmp, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<Types/>');
$z->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c t="inlineStr"><is><t>a@b.pl</t></is></c><c t="inlineStr"><is><t>Jan</t></is></c></row></sheetData></worksheet>');
$z->close();
same(Spreadsheet::xlsx($tmp), [['a@b.pl', 'Jan']], 'xlsx bez atrybutu r w komórkach');
unlink($tmp);
$m = Smtp::build('a@b.pl', 'T', 'x', null, 'k@x.pl', 'Ogarnij Go, akademia');
ok(str_contains($m, 'From: "Ogarnij Go, akademia" <k@x.pl>'), 'nazwa nadawcy z przecinkiem jest cytowana');
same(Access::status(date('Y-m-d', strtotime('+3 days')))['days'], 3, 'dni do końca bez błędu strefy');
same(Uploads::verify('/dev/null', 'webp', 'image/webp'), 'image/webp', 'webp');

echo "\nTesty: $pass OK, $fail błędów\n";
exit($fail ? 1 : 0);
