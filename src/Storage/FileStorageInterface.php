<?php

namespace ApiGoat\Storage;

/**
 * Backend-agnostic file-storage contract for headless entities declared via
 * the GoatCheese `is_drive_backed` (and future `is_*_backed`) behaviors.
 *
 * Returned arrays use a stable key shape so the emitter and the
 * BackedEntity/Query layer can be backend-agnostic:
 *   id           string  Storage-side identifier (Drive file id, S3 key, …)
 *   name         string  Human label / file name
 *   mimeType     ?string Optional MIME type
 *   size         ?int    Bytes (when known)
 *   webViewLink  ?string Browser-openable URL (when published)
 *   parentScope  ?string Folder/path this item lives under
 *   …            mixed   Backend-specific extra metadata may also appear
 */
interface FileStorageInterface
{
    /**
     * List items within a scope (folder path / namespace / bucket prefix).
     *
     * @param string $scope   Backend-specific addressable container.
     * @param array  $filters Optional: 'name_starts_with', 'mime_type',
     *                        'limit', 'page_token', backend extensions.
     *
     * @return array{items: array<int,array<string,mixed>>, page_token?: ?string, total?: ?int}
     */
    public function list(string $scope, array $filters = []): array;

    /**
     * Fetch a single item's metadata by id.
     *
     * @return array<string,mixed>
     */
    public function get(string $id): array;

    /**
     * Upload bytes into a scope. Returns the created item's metadata
     * (must include `id`).
     *
     * @return array<string,mixed>
     */
    public function upload(string $scope, string $name, string $bytes, string $mimeType): array;

    /**
     * Create a sub-folder/namespace under a scope. Returns the created
     * folder's metadata (must include `id`). Backends without a folder
     * concept may register a prefix and return synthetic metadata.
     *
     * @return array<string,mixed>
     */
    public function createFolder(string $scope, string $name): array;

    /**
     * Patch metadata on an existing item (rename, move, change description,
     * etc.). Backends ignore keys they do not understand. Returns the
     * post-patch metadata.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function update(string $id, array $patch): array;

    /**
     * Delete the item. Returns true on success, false if it was already gone.
     */
    public function delete(string $id): bool;

    /**
     * Promote the item to a share level. Returns the shareable URL.
     * Level vocabulary is backend-specific (e.g. Drive: 'anyone',
     * 'domain'; S3: 'public-read').
     */
    public function share(string $id, string $level): string;
}
