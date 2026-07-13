<?php

namespace ApiGoat\Sync\Http;

/** Absolute URLs for the OAuth dance (same _SITE_URL idiom as OAuthMetadataService). */
final class SyncUrls
{
    public static function callback(): string
    {
        return rtrim((string) (defined('_SITE_URL') ? _SITE_URL : ''), '/') . '/Sync/callback';
    }

    public static function status(): string
    {
        return rtrim((string) (defined('_SITE_URL') ? _SITE_URL : ''), '/') . '/Sync/status';
    }
}
