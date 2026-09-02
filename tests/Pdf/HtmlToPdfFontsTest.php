<?php
// Run: php tests/Pdf/HtmlToPdfFontsTest.php
//
// HtmlToPdf engines + project-font support: files under public/fonts/**/<Family>-<Style>.ttf
// are parsed into dompdf font registrations, and the document's @font-face
// rules for those families are stripped before dompdf sees them (browsers use
// the rules; dompdf gets the same files from disk and must not re-fetch them
// over HTTP). Pure helpers only — no dompdf needed.

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    require_once __DIR__ . '/../../src/Pdf/HtmlToPdf.php';

    use ApiGoat\Pdf\HtmlToPdf;

    $fail = 0;
    $check = function (string $label, bool $cond) use (&$fail) {
        echo ($cond ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
        if (!$cond) { $fail++; }
    };

    $dir = sys_get_temp_dir() . '/gc-fonts-' . getmypid();
    mkdir($dir . '/inter', 0777, true);
    foreach (['Inter-Regular', 'Inter-Bold', 'Inter-Italic', 'Inter-BoldItalic', 'Inter-Variable', 'notes'] as $n) {
        file_put_contents($dir . '/inter/' . $n . '.ttf', 'x');
    }
    file_put_contents($dir . '/Mono-Regular.ttf', 'x');

    $fonts = HtmlToPdf::projectFontFiles($dir);
    $byName = [];
    foreach ($fonts as $f) { $byName[basename($f['path'])] = $f; }

    $check('finds 4 Inter faces + top-level Mono, skips unknown styles', count($fonts) === 5);
    $check('Regular → normal/normal', ($byName['Inter-Regular.ttf'] ?? null) && $byName['Inter-Regular.ttf']['style'] === 'normal' && $byName['Inter-Regular.ttf']['weight'] === 'normal');
    $check('Bold → normal/bold', ($byName['Inter-Bold.ttf']['weight'] ?? '') === 'bold' && $byName['Inter-Bold.ttf']['style'] === 'normal');
    $check('Italic → italic/normal', ($byName['Inter-Italic.ttf']['style'] ?? '') === 'italic' && $byName['Inter-Italic.ttf']['weight'] === 'normal');
    $check('BoldItalic → italic/bold', ($byName['Inter-BoldItalic.ttf']['style'] ?? '') === 'italic' && $byName['Inter-BoldItalic.ttf']['weight'] === 'bold');
    $check('family is the file prefix', ($byName['Inter-Bold.ttf']['family'] ?? '') === 'Inter' && ($byName['Mono-Regular.ttf']['family'] ?? '') === 'Mono');
    $check('unparseable names skipped', !isset($byName['notes.ttf']) && !isset($byName['Inter-Variable.ttf']));

    $html = "<style>@font-face { font-family: 'Inter'; font-style: normal; font-weight: 700; src: url('https://x/Inter-Bold.ttf') format('truetype'); }\n"
        . "@font-face{font-family:Inter;src:url(https://x/Inter-Regular.ttf);}\n"
        . "@font-face { font-family: 'Other'; src: url('https://x/o.ttf'); }\n"
        . "* { font-family: Inter, 'DejaVu Sans', sans-serif; }</style><p>Inter stays in the stack</p>";
    $out = HtmlToPdf::stripFontFace($html, ['Inter']);
    $check('Inter @font-face rules removed (both spellings)', substr_count($out, '@font-face') === 1);
    $check('other family kept', str_contains($out, "font-family: 'Other'"));
    $check('font stack + body untouched', str_contains($out, "font-family: Inter, 'DejaVu Sans'") && str_contains($out, 'Inter stays in the stack'));
    $check('no families → unchanged', HtmlToPdf::stripFontFace($html, []) === $html);

    // engine selection: preference wins when available, auto walks the order, dompdf is the floor
    $has = fn(array $avail) => fn(string $e) => in_array($e, $avail, true);
    $check('auto → wkhtmltopdf when present', HtmlToPdf::engineFor('auto', $has(['wkhtmltopdf', 'chrome'])) === 'wkhtmltopdf');
    $check('auto → chrome when only chrome', HtmlToPdf::engineFor(null, $has(['chrome'])) === 'chrome');
    $check('auto → dompdf when nothing', HtmlToPdf::engineFor('', $has([])) === 'dompdf');
    $check('explicit chrome honoured', HtmlToPdf::engineFor('chrome', $has(['wkhtmltopdf', 'chrome'])) === 'chrome');
    $check('explicit but missing falls back to auto', HtmlToPdf::engineFor('chrome', $has(['wkhtmltopdf'])) === 'wkhtmltopdf');
    $check('explicit dompdf always allowed', HtmlToPdf::engineFor('dompdf', $has(['wkhtmltopdf'])) === 'dompdf');
    $check('garbage preference → auto', HtmlToPdf::engineFor('foo', $has(['wkhtmltopdf'])) === 'wkhtmltopdf');

    // paper geometry
    $check('toMm units', abs(HtmlToPdf::toMm('1in') - 25.4) < 0.001 && abs(HtmlToPdf::toMm('2cm') - 20) < 0.001 && abs(HtmlToPdf::toMm('96px') - 25.4) < 0.001 && HtmlToPdf::toMm('16') == 16.0 && HtmlToPdf::toMm('x') == 0.0);
    $check('Letter 16mm sides → 695 px', HtmlToPdf::contentWidthPx('Letter', ['18mm', '16mm', '18mm', '16mm']) === 695);
    $check('A4 10mm sides → 718 px', HtmlToPdf::contentWidthPx('A4', ['10mm', '10mm', '10mm', '10mm']) === 718);
    $fit = HtmlToPdf::fitToPaper('<html><head><title>t</title></head><body>x</body></html>', 695, 0.725);
    $check('fitToPaper zooms 1/scale and pins body width', str_contains($fit, 'html{zoom:1.3793}') && str_contains($fit, 'body{width:504px}') && substr_count($fit, '</head>') === 1);
    $check('fitToPaper without <head> prepends', str_starts_with(HtmlToPdf::fitToPaper('<p>x</p>', 695, 1.0), '<style>html{zoom:1}'));
    $conf = HtmlToPdf::fontsConf('/p/public/fonts', '/p/tmp/pdf/fc');
    $check('fontsConf includes system + project dir + cache', str_contains($conf, '/etc/fonts/fonts.conf') && str_contains($conf, '<dir>/p/public/fonts</dir>') && str_contains($conf, '<cachedir>/p/tmp/pdf/fc</cachedir>'));
    $check('fontsConf without project dir', !str_contains(HtmlToPdf::fontsConf(null, '/c'), '<dir>'));

    array_map('unlink', glob($dir . '/inter/*') ?: []);
    array_map('unlink', glob($dir . '/*.ttf') ?: []);
    rmdir($dir . '/inter'); rmdir($dir);

    echo PHP_EOL . ($fail ? "$fail FAILED" : 'all passed') . PHP_EOL;
    exit($fail ? 1 : 0);
}
