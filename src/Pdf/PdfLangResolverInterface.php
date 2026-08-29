<?php

namespace ApiGoat\Pdf;

/**
 * Optional companion to PdfHtmlRendererInterface: a custom renderer that
 * resolves the document language itself (e.g. falling back through
 * contact/company language when the record's own lang is empty) implements
 * this so the pipeline REPORTS AND STORES the language actually rendered.
 *
 * Without it, the stored/reported lang comes from TemplateRenderer::lang()
 * (override → record lang column → fr_CA), which can disagree with what a
 * richer renderer produced — an English PDF recorded as French.
 *
 * $lang is the raw caller override (possibly null), exactly what render()
 * receives; return the locale the document WILL BE (or was) rendered in.
 */
interface PdfLangResolverInterface
{
    public function resolveLang(object $record, ?string $lang = null): string;
}
