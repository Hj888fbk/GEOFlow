<?php

namespace App\Services\Content;

use App\Models\ContentSourceFile;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class HengjiaContentSourceSynchronizer
{
    /**
     * @param  list<string>|null  $rootKeys
     * @return array{scanned:int,created:int,updated:int,skipped:int,failures:list<array<string,string>>,dry_run:bool}
     */
    public function sync(?array $rootKeys = null, bool $dryRun = false): array
    {
        $result = ['scanned' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failures' => [], 'dry_run' => $dryRun];
        $roots = (array) config('hengjia-content.source_roots', []);
        if ($rootKeys !== null) {
            $roots = array_intersect_key($roots, array_flip($rootKeys));
        }

        foreach ($roots as $rootKey => $definition) {
            $rootPath = $this->canonicalDirectory((string) ($definition['path'] ?? ''));
            if ($rootPath === null) {
                $result['failures'][] = ['root' => (string) $rootKey, 'reason' => 'approved_root_unavailable'];

                continue;
            }

            foreach ((array) ($definition['subpaths'] ?? []) as $subpath) {
                $relativeBase = $this->normalizeRelativePath((string) ($subpath['path'] ?? ''));
                $scanPath = $this->canonicalDirectory($rootPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeBase));
                if ($scanPath === null || ! $this->within($scanPath, $rootPath)) {
                    $result['failures'][] = ['root' => (string) $rootKey, 'reason' => 'approved_subpath_unavailable:'.$relativeBase];

                    continue;
                }

                $this->scanDirectory((string) $rootKey, $rootPath, $scanPath, (array) $subpath, $dryRun, $result);
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $result */
    private function scanDirectory(string $rootKey, string $rootPath, string $scanPath, array $definition, bool $dryRun, array &$result): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! $file->isReadable()) {
                continue;
            }
            $result['scanned']++;

            $absolutePath = $file->getRealPath();
            if (! is_string($absolutePath) || ! $this->within($absolutePath, $rootPath)) {
                $result['skipped']++;

                continue;
            }

            $relativePath = $this->normalizeRelativePath(substr($absolutePath, strlen(rtrim($rootPath, '\\/')) + 1));
            if ($this->excluded($relativePath) || ! $this->allowedExtension($file)) {
                $result['skipped']++;

                continue;
            }

            $size = max(0, (int) $file->getSize());
            if ($size > (int) config('hengjia-content.source_sync.max_file_bytes', 25 * 1024 * 1024)) {
                $result['skipped']++;
                $result['failures'][] = ['root' => $rootKey, 'reason' => 'file_too_large:'.$relativePath];

                continue;
            }

            $sha256 = hash_file('sha256', $absolutePath);
            if (! is_string($sha256)) {
                $result['failures'][] = ['root' => $rootKey, 'reason' => 'hash_failed:'.$relativePath];

                continue;
            }

            $lastModifiedAt = date(DATE_ATOM, $file->getMTime());
            $fileAttributes = [
                'sha256' => $sha256,
                'file_size' => $size,
                'mime_type' => $this->mimeType($absolutePath),
                'source_date' => date('Y-m-d', $file->getMTime()),
                'last_synced_at' => now(),
            ];
            $pathHash = hash('sha256', Str::lower($relativePath));
            $existing = ContentSourceFile::query()
                ->where('source_root_key', $rootKey)
                ->where('path_hash', $pathHash)
                ->first();

            if ($dryRun) {
                $result[$existing ? 'updated' : 'created']++;

                continue;
            }

            DB::transaction(function () use (
                $rootKey,
                $pathHash,
                $relativePath,
                $fileAttributes,
                $lastModifiedAt,
                $definition,
            ): void {
                $locked = ContentSourceFile::query()
                    ->where('source_root_key', $rootKey)
                    ->where('path_hash', $pathHash)
                    ->lockForUpdate()
                    ->first();
                if (! $locked) {
                    ContentSourceFile::query()->create([
                        'source_root_key' => $rootKey,
                        'path_hash' => $pathHash,
                        'relative_path' => $relativePath,
                        'source_version' => null,
                        'evidence_status' => (string) ($definition['evidence_status'] ?? ContentSourceFile::STATUS_INTERNAL_CONFIRMED),
                        'public_permission' => (string) ($definition['public_permission'] ?? ContentSourceFile::PERMISSION_INTERNAL),
                        'is_approved' => (bool) ($definition['approved'] ?? false),
                        'metadata' => ['last_modified_at' => $lastModifiedAt],
                    ] + $fileAttributes);

                    return;
                }

                $contentChanged = ! hash_equals((string) $locked->sha256, (string) $fileAttributes['sha256']);
                $metadata = (array) $locked->metadata;
                $metadata['last_modified_at'] = $lastModifiedAt;
                if ($contentChanged) {
                    unset($metadata['approved_by_admin_id'], $metadata['approved_at']);
                    $metadata['approval_invalidated_at'] = now()->toAtomString();
                    $metadata['approval_invalidated_reason'] = 'source_file_hash_changed';
                    $metadata['previous_sha256'] = (string) $locked->sha256;
                }

                $updates = ['relative_path' => $relativePath, 'metadata' => $metadata] + $fileAttributes;
                if ($contentChanged) {
                    $updates += [
                        'source_version' => null,
                        'evidence_status' => (string) ($definition['evidence_status'] ?? ContentSourceFile::STATUS_INTERNAL_CONFIRMED),
                        'public_permission' => ContentSourceFile::PERMISSION_INTERNAL,
                        'is_approved' => false,
                    ];
                }
                $locked->forceFill($updates)->save();
            });
            $result[$existing ? 'updated' : 'created']++;
        }
    }

    private function canonicalDirectory(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || ! is_dir($path)) {
            return null;
        }
        $real = realpath($path);

        return is_string($real) ? rtrim($real, '\\/') : null;
    }

    private function within(string $path, string $root): bool
    {
        $path = Str::lower(str_replace('\\', '/', $path));
        $root = rtrim(Str::lower(str_replace('\\', '/', $root)), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function excluded(string $relativePath): bool
    {
        $segments = array_map(Str::lower(...), explode('/', $relativePath));
        $excluded = array_map(static fn (mixed $item): string => Str::lower((string) $item), (array) config('hengjia-content.source_sync.excluded_segments', []));
        if (array_intersect($segments, $excluded) !== []) {
            return true;
        }

        $extension = Str::lower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
        $excludedExtensions = array_map(
            static fn (mixed $item): string => Str::lower(ltrim((string) $item, '.')),
            (array) config('hengjia-content.source_sync.excluded_extensions', []),
        );
        if ($extension !== '' && in_array($extension, $excludedExtensions, true)) {
            return true;
        }

        $basename = (string) pathinfo($relativePath, PATHINFO_BASENAME);
        foreach ((array) config('hengjia-content.source_sync.excluded_name_patterns', []) as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    private function allowedExtension(SplFileInfo $file): bool
    {
        $allowed = (array) config('hengjia-content.source_sync.extensions', []);

        return in_array(Str::lower($file->getExtension()), $allowed, true);
    }

    private function mimeType(string $path): string
    {
        $type = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return is_string($type) && $type !== '' ? $type : 'application/octet-stream';
    }
}
