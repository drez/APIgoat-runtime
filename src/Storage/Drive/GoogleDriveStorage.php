<?php

namespace ApiGoat\Storage\Drive;

use ApiGoat\Storage\Drive\Exceptions\AuthFailed;
use ApiGoat\Storage\FileStorageInterface;

/**
 * Google Drive implementation of FileStorageInterface.
 *
 * Scopes are folder paths like "CRM/JohnDoe". Resolved to Drive folder ids
 * lazily, creating intermediate folders as needed under the user's Drive
 * root. Caches resolved path→id in-process for the request.
 *
 * Subject impersonation: the storage is constructed for a specific
 * Workspace user (Domain-Wide Delegation). All ops act as that user.
 *
 * Adapted from /var/www/gc/p/apicrm/.admin/src/App/Domains/Google/DriveStorage.php
 * which is contact-coupled (its `upload` takes contactId/contactName and
 * caches a per-contact folder id in a MySQL table). This runtime version is
 * generic — the folder is just a path; resolution doesn't touch MySQL.
 */
class GoogleDriveStorage implements FileStorageInterface
{
    private const SCOPES      = [GoogleClientFactory::SCOPE_DRIVE_FILE];
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const FILES_URL   = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink';
    private const META_FIELDS = 'id,name,mimeType,size,webViewLink,parents,modifiedTime';

    private GoogleClientFactory $google;
    private string $userEmail;

    /**
     * Shared Drive id, or '' for classic per-user My Drive mode. When set, the
     * folder tree is anchored at this Shared Drive (org-owned, visible to every
     * drive member) instead of each impersonated user's private My Drive root,
     * and every request carries the Shared-Drive opt-in params Drive v3 requires.
     */
    private string $driveId;

    /** path → folderId, in-process cache. */
    private array $folderCache = [];

    public function __construct(GoogleClientFactory $google, string $userEmail, string $driveId = '')
    {
        if ($userEmail === '') {
            throw new AuthFailed('GoogleDriveStorage requires a non-empty userEmail (DWD subject)');
        }
        $this->google = $google;
        $this->userEmail = $userEmail;
        $this->driveId = trim($driveId);
    }

    public static function forUser(string $userEmail): self
    {
        return new self(
            GoogleClientFactory::fromEnv(),
            $userEmail,
            GoogleClientFactory::sharedDriveIdFromEnv()
        );
    }

    /**
     * Root folder id for path resolution: the Shared Drive id when configured
     * (a Shared Drive's id doubles as its root folder id), else 'root' — the
     * impersonated user's My Drive root.
     */
    private function rootId(): string
    {
        return $this->driveId !== '' ? $this->driveId : 'root';
    }

    /**
     * Append the Shared-Drive opt-in query params Drive v3 requires. No-op in
     * My Drive mode ($driveId === ''), so existing projects are byte-unchanged.
     * $isSearch adds the corpora/driveId/includeItemsFromAllDrives trio that
     * files.list needs to actually traverse the Shared Drive.
     */
    private function withDriveParams(string $url, bool $isSearch = false): string
    {
        if ($this->driveId === '') {
            return $url;
        }
        $sep = strpos($url, '?') === false ? '?' : '&';
        $params = 'supportsAllDrives=true';
        if ($isSearch) {
            $params .= '&includeItemsFromAllDrives=true'
                . '&corpora=drive&driveId=' . rawurlencode($this->driveId);
        }
        return $url . $sep . $params;
    }

    public function list(string $scope, array $filters = []): array
    {
        $folderId = $this->resolveScope($scope, /*create*/ false);
        if ($folderId === null) {
            return ['items' => [], 'total' => 0];
        }

        $q = ["'{$folderId}' in parents", 'trashed=false'];

        if (!empty($filters['name_starts_with'])) {
            // Drive q syntax: name contains '<prefix>' — Drive doesn't expose
            // true prefix matching, but `contains` returns a superset; we
            // post-filter for exactness below.
            $q[] = "name contains '" . addslashes((string) $filters['name_starts_with']) . "'";
        }
        if (!empty($filters['mime_type'])) {
            $q[] = "mimeType='" . addslashes((string) $filters['mime_type']) . "'";
        }

        $perPage = (int) ($filters['limit'] ?? 100);
        if ($perPage < 1) { $perPage = 100; }
        if ($perPage > 1000) { $perPage = 1000; }

        $orderBy = !empty($filters['order_by']) ? $this->mapOrder((string) $filters['order_by'], (string) ($filters['order_dir'] ?? 'ASC')) : 'name';

        $url = self::FILES_URL
            . '?q=' . rawurlencode(implode(' and ', $q))
            . '&fields=' . rawurlencode("nextPageToken,files({$this->fieldsList()})")
            . '&pageSize=' . $perPage
            . '&orderBy=' . rawurlencode($orderBy);
        if (!empty($filters['page_token'])) {
            $url .= '&pageToken=' . rawurlencode((string) $filters['page_token']);
        }

        $resp = $this->google->get($this->withDriveParams($url, /*search*/ true), self::SCOPES, $this->userEmail);
        $files = $resp['files'] ?? [];

        // Post-filter for true name prefix when requested.
        if (!empty($filters['name_starts_with'])) {
            $prefix = (string) $filters['name_starts_with'];
            $files = array_values(array_filter($files, fn($f) => isset($f['name']) && stripos((string) $f['name'], $prefix) === 0));
        }

        $items = array_map(fn($f) => $this->normalize($f, $scope), $files);
        return [
            'items'      => $items,
            'page_token' => $resp['nextPageToken'] ?? null,
        ];
    }

    public function get(string $id): array
    {
        $resp = $this->google->get(
            $this->withDriveParams(self::FILES_URL . '/' . rawurlencode($id) . '?fields=' . rawurlencode($this->fieldsList())),
            self::SCOPES,
            $this->userEmail
        );
        return $this->normalize($resp, null);
    }

    public function upload(string $scope, string $name, string $bytes, string $mimeType): array
    {
        $folderId = $this->resolveScope($scope, /*create*/ true);

        $metadata = ['name' => $name, 'parents' => [$folderId]];
        if ($mimeType !== '') {
            $metadata['mimeType'] = $mimeType;
        }

        $resp = $this->google->uploadMultipart(
            $this->withDriveParams(self::UPLOAD_URL),
            $metadata,
            $bytes,
            $mimeType !== '' ? $mimeType : 'application/octet-stream',
            self::SCOPES,
            $this->userEmail
        );

        // upload endpoint returns id,name,mimeType,size,webViewLink (per the URL fields= param).
        return $this->normalize($resp, $scope);
    }

    /**
     * Create a folder named $name under the folder path $scope (creating
     * intermediate folders as needed). Returns the new folder's normalized
     * metadata. No-op-safe: if a folder with that name already exists under
     * the scope, Drive will create a second one (Drive allows duplicate
     * names) — callers that need uniqueness should check first.
     */
    public function createFolder(string $scope, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new AuthFailed('createFolder: empty folder name');
        }
        $parentId = $this->resolveScope($scope, /*create*/ true);
        $created = $this->google->post(
            $this->withDriveParams(self::FILES_URL . '?fields=' . rawurlencode(self::META_FIELDS)),
            ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]],
            self::SCOPES,
            $this->userEmail
        );
        if (empty($created['id'])) {
            throw new AuthFailed("Drive folder creation returned no id for '{$name}'");
        }
        // Seed the path cache so subsequent navigation into the new folder
        // within this request resolves without a directory round-trip.
        $childPath = trim($scope, '/') === '' ? $name : (trim($scope, '/') . '/' . $name);
        $this->folderCache[$childPath] = (string) $created['id'];
        return $this->normalize($created, $scope);
    }

    public function update(string $id, array $patch): array
    {
        // FileStorageInterface uses stable keys (name, mimeType, …); Drive
        // accepts the same keys on PATCH /files/{id}. Pass through.
        $payload = [];
        foreach (['name', 'mimeType', 'description'] as $k) {
            if (array_key_exists($k, $patch)) {
                $payload[$k] = $patch[$k];
            }
        }
        if (empty($payload)) {
            return $this->get($id);
        }
        $resp = $this->google->patch(
            $this->withDriveParams(self::FILES_URL . '/' . rawurlencode($id) . '?fields=' . rawurlencode($this->fieldsList())),
            $payload,
            self::SCOPES,
            $this->userEmail
        );
        return $this->normalize($resp, null);
    }

    public function delete(string $id): bool
    {
        return $this->google->delete(
            $this->withDriveParams(self::FILES_URL . '/' . rawurlencode($id)),
            self::SCOPES,
            $this->userEmail
        );
    }

    public function share(string $id, string $level): string
    {
        $role = 'reader';
        // SECURITY (review R6): never silently world-publish on an unrecognized
        // level. 'domain' = same-workspace; 'public'/'anyone' = anyone-with-link
        // (explicit opt-in only); anything else is a caller error, not public.
        if ($level === 'domain') {
            $type = 'domain';
        } elseif ($level === 'public' || $level === 'anyone') {
            $type = 'anyone';
        } else {
            throw new \InvalidArgumentException("share(): unknown level '{$level}' (use 'domain' or 'public')");
        }
        $this->google->post(
            $this->withDriveParams(self::FILES_URL . '/' . rawurlencode($id) . '/permissions'),
            ['role' => $role, 'type' => $type],
            self::SCOPES,
            $this->userEmail
        );
        $meta = $this->google->get(
            $this->withDriveParams(self::FILES_URL . '/' . rawurlencode($id) . '?fields=webViewLink'),
            self::SCOPES,
            $this->userEmail
        );
        if (empty($meta['webViewLink'])) {
            throw new AuthFailed("Drive file {$id} returned no webViewLink after share");
        }
        return (string) $meta['webViewLink'];
    }

    /**
     * Resolve a path like "CRM/JohnDoe" to a Drive folder id, optionally
     * creating intermediate folders. Returns null when $create=false and
     * the path doesn't exist.
     */
    private function resolveScope(string $scope, bool $create): ?string
    {
        $scope = trim($scope, '/');
        if ($scope === '') {
            return $this->rootId();
        }
        if (isset($this->folderCache[$scope])) {
            return $this->folderCache[$scope];
        }

        $parent = $this->rootId();
        $accumPath = '';
        foreach (explode('/', $scope) as $segment) {
            if ($segment === '') { continue; }
            $accumPath = $accumPath === '' ? $segment : ($accumPath . '/' . $segment);
            if (isset($this->folderCache[$accumPath])) {
                $parent = $this->folderCache[$accumPath];
                continue;
            }

            $q = sprintf(
                "name='%s' and mimeType='%s' and '%s' in parents and trashed=false",
                addslashes($segment),
                self::FOLDER_MIME,
                $parent
            );
            $resp = $this->google->get(
                $this->withDriveParams(self::FILES_URL . '?q=' . rawurlencode($q) . '&fields=files(id,name)&pageSize=1', /*search*/ true),
                self::SCOPES,
                $this->userEmail
            );
            $found = $resp['files'][0]['id'] ?? null;
            if ($found) {
                $parent = $found;
                $this->folderCache[$accumPath] = $found;
                continue;
            }
            if (!$create) {
                return null;
            }
            $created = $this->google->post(
                $this->withDriveParams(self::FILES_URL . '?fields=id'),
                ['name' => $segment, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parent]],
                self::SCOPES,
                $this->userEmail
            );
            if (empty($created['id'])) {
                throw new AuthFailed("Drive folder creation returned no id for '{$segment}'");
            }
            $parent = (string) $created['id'];
            $this->folderCache[$accumPath] = $parent;
        }
        return $parent;
    }

    /** Normalize Drive's response to the FileStorageInterface stable shape. */
    private function normalize(array $f, ?string $parentScope): array
    {
        return [
            'id'           => (string) ($f['id'] ?? ''),
            'name'         => (string) ($f['name'] ?? ''),
            'mimeType'     => $f['mimeType'] ?? null,
            'size'         => isset($f['size']) ? (int) $f['size'] : null,
            'webViewLink'  => $f['webViewLink'] ?? null,
            'parentScope'  => $parentScope,
            'modifiedTime' => $f['modifiedTime'] ?? null,
        ];
    }

    private function fieldsList(): string
    {
        return self::META_FIELDS;
    }

    private function mapOrder(string $column, string $dir): string
    {
        $dir = strtoupper($dir) === 'DESC' ? ' desc' : '';
        // Drive orderBy whitelist: name, modifiedTime, createdTime, folder,
        // quotaBytesUsed, viewedByMeTime, recency, sharedWithMeTime,
        // starred. Anything else falls back to 'name'.
        $allowed = ['name', 'modifiedTime', 'createdTime', 'folder', 'quotaBytesUsed', 'viewedByMeTime', 'recency', 'sharedWithMeTime', 'starred'];
        return in_array($column, $allowed, true) ? ($column . $dir) : 'name';
    }
}
