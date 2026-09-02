<?php
namespace ApiGoat\Pdf;

/**
 * Optional companion to PdfHtmlRendererInterface: a custom renderer that
 * knows the document's real name (a quote numbered from its own sequence, a
 * localized document label, the client's name) implements this so the
 * generated file is called "Soumission-0338-KDA.pdf" instead of the generic
 * "<entity>-<pk>.pdf".
 *
 * Precedence in PdfGenerator::canonicalName(): this resolver → the with_pdf
 * `filename` template → "<entity>-<pk>". Return the base name without the
 * extension; it is slugged (PdfNaming::fromTemplate) so any characters are
 * safe. Return '' to fall through to the next rule.
 */
interface PdfNameResolverInterface
{
    public function resolveName(object $record): string;
}
