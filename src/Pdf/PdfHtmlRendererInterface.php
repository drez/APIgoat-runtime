<?php

namespace ApiGoat\Pdf;

/**
 * Contract for `with_pdf: { type: custom, class: ... }` project renderers:
 * given the hydrated record, return the COMPLETE printable HTML document
 * (everything dompdf renders — the behavior adds nothing around it).
 *
 * $lang is the resolved render locale (fr_CA | en_US) the caller wants the
 * document produced in — supplied by gc_pdf_preview/gc_regenerate_pdf's
 * `lang` argument, else the record's own document language. Implementations
 * MUST honor it for add_i18n content; null means "the record's own language".
 */
interface PdfHtmlRendererInterface
{
    public function render(object $record, ?string $lang = null): string;
}
