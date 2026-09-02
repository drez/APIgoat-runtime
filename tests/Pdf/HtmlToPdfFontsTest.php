<?php
// Run: php tests/Pdf/HtmlToPdfFontsTest.php
//
// HtmlToPdf project-font support: files under public/fonts/**/<Family>-<Style>.ttf
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

    array_map('unlink', glob($dir . '/inter/*') ?: []);
    array_map('unlink', glob($dir . '/*.ttf') ?: []);
    rmdir($dir . '/inter'); rmdir($dir);

    echo PHP_EOL . ($fail ? "$fail FAILED" : 'all passed') . PHP_EOL;
    exit($fail ? 1 : 0);
}
