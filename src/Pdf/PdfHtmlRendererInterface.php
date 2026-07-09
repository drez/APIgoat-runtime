<?php

namespace ApiGoat\Pdf;

/**
 * Contract for `with_pdf: { type: custom, class: ... }` project renderers:
 * given the hydrated record, return the COMPLETE printable HTML document
 * (everything dompdf renders — the behavior adds nothing around it).
 */
interface PdfHtmlRendererInterface
{
    public function render(object $record): string;
}
