<?php

namespace ApiGoat\Pdf;

/**
 * HTML → PDF bytes via dompdf. Generalized from apicrm's QuotePdf: DejaVu Sans
 * (bundled with dompdf) so accented French renders correctly; letter portrait.
 *
 * dompdf is a PROJECT composer dependency (apicrm ships ^2, apigoatacc ^3 —
 * the API used here is stable across both). The runtime soft-requires it so
 * projects without with_pdf don't pay the dependency.
 */
final class HtmlToPdf
{
    public function render(string $html): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException(
                'with_pdf requires dompdf: add "dompdf/dompdf": "^3.1" (or ^2) to the project composer.json and run composer update.'
            );
        }

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
