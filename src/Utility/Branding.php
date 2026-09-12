<?php

namespace ApiGoat\Utility;

use ApiGoat\Ai\AiConfig;

/**
 * Project identity for unauthenticated chrome — currently the OAuth
 * login/consent pages, which deliberately bypass BuilderLayout (raw PSR-7,
 * no session, no asset pipeline) and therefore cannot reuse its branding.
 *
 * Product name ladder (first non-blank wins):
 *
 *   1. `branding_product_name` config row — the one an operator can change
 *      without a deploy (read via AiConfig::config(), which despite the Ai
 *      namespace is the fleet-wide fail-soft config-table reader: memoized
 *      single read, absent table/model degrades to the fallback)
 *   2. _SITE_TITLE
 *   3. ucfirst(_PROJECT_NAME)
 *   4. 'App'
 *
 * (2-4 is the same chain BuilderLayout uses for the PWA title.)
 *
 * Logo/favicon are file/constant based, mirroring the admin login page
 * (goatcheese FormTemplates): LOGO_URL_LOGIN wins only when its file exists
 * under public/img/, and a missing default file yields '' so callers can
 * skip the tag instead of rendering a broken image.
 *
 * Every constant is defined()-guarded: this must be callable from CLI and
 * tests where the project config was never loaded.
 */
final class Branding
{
    public const PRODUCT_NAME_CONFIG_ROW = 'branding_product_name';

    public static function productName(): string
    {
        $fromConfig = AiConfig::config(self::PRODUCT_NAME_CONFIG_ROW);
        if (\is_string($fromConfig) && \trim($fromConfig) !== '') {
            return \trim($fromConfig);
        }
        if (\defined('_SITE_TITLE') && (string) _SITE_TITLE !== '') {
            return (string) _SITE_TITLE;
        }
        if (\defined('_PROJECT_NAME') && (string) _PROJECT_NAME !== '') {
            return \ucfirst((string) _PROJECT_NAME);
        }

        return 'App';
    }

    /** Login-page logo URL, or '' when no logo file exists. */
    public static function logoUrl(): string
    {
        $imgDir = \defined('_INSTALL_PATH') ? _INSTALL_PATH . 'public/img/' : null;
        if ($imgDir === null) {
            return '';
        }
        if (\defined('LOGO_URL_LOGIN') && \is_file($imgDir . LOGO_URL_LOGIN)) {
            return (string) LOGO_URL_LOGIN;
        }
        if (\defined('_SITE_URL') && \is_file($imgDir . 'logo-admin.png')) {
            return _SITE_URL . 'public/img/logo-admin.png';
        }

        return '';
    }

    /** Favicon URL, or '' when the file is absent. */
    public static function faviconUrl(): string
    {
        if (
            \defined('_INSTALL_PATH') && \defined('_SITE_URL')
            && \is_file(_INSTALL_PATH . 'public/img/fav-2.1.png')
        ) {
            return _SITE_URL . 'public/img/fav-2.1.png';
        }

        return '';
    }
}
