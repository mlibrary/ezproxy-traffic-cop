<?php
/*
 * Requirements:
 *   - PHP 8.2+
 *   - ext-sodium
 */


final class ClientError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400
    ) {
        parent::__construct($message);
    }
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $body,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Return the temporary pathname for a successful PHP upload.
 */
function uploadedFile(string $field): string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        throw new ClientError("Missing upload field: {$field}");
    }

    $upload = $_FILES[$field];

    if (
        !isset($upload['error'], $upload['tmp_name']) ||
        !is_int($upload['error']) ||
        !is_string($upload['tmp_name'])
    ) {
        throw new ClientError("Malformed upload field: {$field}");
    }

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new ClientError("Upload failed for field: {$field}");
    }

    if (!is_uploaded_file($upload['tmp_name'])) {
        throw new ClientError("Invalid upload source: {$field}");
    }

    return $upload['tmp_name'];
}

function readSmallFile(string $path, int $limit, string $label): string
{
    $size = filesize($path);

    if ($size === false || $size < 1 || $size > $limit) {
        throw new ClientError("Invalid {$label} size");
    }

    $value = file_get_contents($path);

    if ($value === false || strlen($value) !== $size) {
        throw new ClientError("Could not read {$label}");
    }

    return $value;
}

/**
 * Ensure a directory exists and is not a symbolic link.
 */
function ensureDirectory(string $path, int $mode = 0750): void
{
    if (is_link($path)) {
        throw new RuntimeException("Directory path is a symbolic link");
    }

    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create directory");
    }

    if (is_link($path)) {
        throw new RuntimeException("Directory became a symbolic link");
    }
}

/**
 * Remove only a known, server-generated staging tree.
 */
function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        // Never follow links.
        if ($entry->isLink() || $entry->isFile()) {
            if (!unlink($entryPath)) {
                throw new RuntimeException('Could not remove staging entry');
            }
        } elseif ($entry->isDir()) {
            if (!rmdir($entryPath)) {
                throw new RuntimeException('Could not remove staging directory');
            }
        } else {
            throw new RuntimeException('Unexpected staging entry type');
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove staging root');
    }
}

/**
 * Validate and normalize an archive path.
 *
 * Backslash is rejected rather than treated as a separator. This keeps
 * behavior unambiguous if the files are ever copied to another platform.
 */
function normalizeArchivePath(string $raw): string
{
    if ($raw === '' || strlen($raw) > MAX_PATH_LENGTH) {
        throw new ClientError('Invalid archive path length');
    }

    if (
        str_contains($raw, "\0") ||
        str_contains($raw, '\\') ||
        str_starts_with($raw, '/') ||
        preg_match('/[\x00-\x1F\x7F]/', $raw) === 1
    ) {
        throw new ClientError('Unsafe archive pathname');
    }

    // POSIX tar directory entries commonly end with "/".
    $raw = rtrim($raw, '/');

    if ($raw === '') {
        throw new ClientError('Invalid archive pathname');
    }

    $parts = explode('/', $raw);

    if (count($parts) > MAX_PATH_DEPTH) {
        throw new ClientError('Archive path is too deep');
    }

    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new ClientError('Unsafe archive path component');
        }
    }

    $normalized = implode('/', $parts);

    if (strlen($normalized) > MAX_PATH_LENGTH) {
        throw new ClientError('Archive pathname is too long');
    }

    return $normalized;
}

/**
 * Parse a POSIX tar numeric field. Supports ordinary octal fields only.
 * GNU/base-256 numeric extensions are deliberately rejected.
 */
function parseTarOctal(string $field, string $label): int
{
    if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
        throw new ClientError("Unsupported base-256 tar {$label}");
    }

    $value = trim($field, "\0 ");

    if ($value === '') {
        return 0;
    }

    if (preg_match('/\A[0-7]+\z/D', $value) !== 1) {
        throw new ClientError("Invalid tar {$label}");
    }

    $number = octdec($value);

    if (!is_int($number) || $number < 0) {
        throw new ClientError("Invalid tar {$label}");
    }

    return $number;
}

/**
 * Verify the checksum for one 512-byte tar header.
 */
function verifyTarHeaderChecksum(string $header): void
{
    if (strlen($header) !== 512) {
        throw new ClientError('Truncated tar header');
    }

    $stored = parseTarOctal(substr($header, 148, 8), 'checksum');
    $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);

    $unsigned = 0;
    for ($i = 0; $i < 512; $i++) {
        $unsigned += ord($checksumHeader[$i]);
    }

    if ($stored !== $unsigned) {
        throw new ClientError('Invalid tar header checksum');
    }
}

function tarString(string $field): string
{
    $nul = strpos($field, "\0");

    return $nul === false ? $field : substr($field, 0, $nul);
}

/**
 * Check that each path component currently under the staging root is a
 * real directory, never a symbolic link.
 */
function ensureSafeParentDirectories(
    string $stagingRoot,
    string $relativePath
): void {
    $parent = dirname($relativePath);

    if ($parent === '.') {
        return;
    }

    $current = $stagingRoot;

    foreach (explode('/', $parent) as $part) {
        $current .= '/' . $part;

        if (is_link($current)) {
            throw new ClientError('Archive path crosses a symbolic link');
        }

        if (file_exists($current)) {
            if (!is_dir($current)) {
                throw new ClientError(
                    'Archive path conflicts with a non-directory'
                );
            }
        } elseif (!mkdir($current, 0750)) {
            throw new RuntimeException('Could not create output directory');
        }

        if (is_link($current) || !is_dir($current)) {
            throw new RuntimeException('Unsafe output directory');
        }
    }
}

/**
 * Copy exactly $length bytes from one stream to another.
 */
function copyExact($input, $output, int $length): void
{
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = fread($input, min(1024 * 1024, $remaining));

        if ($chunk === false || $chunk === '') {
            throw new ClientError('Truncated tar entry');
        }

        $written = 0;
        $chunkLength = strlen($chunk);

        while ($written < $chunkLength) {
            $count = fwrite($output, substr($chunk, $written));

            if ($count === false || $count === 0) {
                throw new RuntimeException('Could not write extracted file');
            }

            $written += $count;
        }

        $remaining -= $chunkLength;
    }
}

function discardExact($input, int $length): void
{
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = fread($input, min(1024 * 1024, $remaining));

        if ($chunk === false || $chunk === '') {
            throw new ClientError('Truncated tar padding');
        }

        $remaining -= strlen($chunk);
    }
}

/**
 * Extract a deliberately narrow tar subset:
 *
 *   - ustar-compatible regular files and directories
 *   - no links
 *   - no devices, FIFOs, sockets, pax headers, or GNU extensions
 *   - no duplicate paths
 *
 * The archive-provided owner and permission modes are not preserved.
 */
function safelyExtractTar(string $archivePath, string $stagingRoot): array
{
    $input = fopen($archivePath, 'rb');

    if ($input === false) {
        throw new RuntimeException('Could not open archive');
    }

    $seen = [];
    $fileCount = 0;
    $expandedSize = 0;
    $zeroBlocks = 0;

    try {
        while (true) {
            $header = fread($input, 512);

            if ($header === false) {
                throw new RuntimeException('Could not read archive');
            }

            if ($header === '') {
                // Strictly require the conventional two zero blocks.
                throw new ClientError('Tar archive has no end marker');
            }

            if (strlen($header) !== 512) {
                throw new ClientError('Truncated tar header');
            }

            if ($header === str_repeat("\0", 512)) {
                $zeroBlocks++;

                if ($zeroBlocks === 2) {
                    break;
                }

                continue;
            }

            if ($zeroBlocks !== 0) {
                throw new ClientError('Invalid tar end marker');
            }

            verifyTarHeaderChecksum($header);

            $name = tarString(substr($header, 0, 100));
            $size = parseTarOctal(substr($header, 124, 12), 'size');
            $type = $header[156];
            $prefix = tarString(substr($header, 345, 155));
            $magic = substr($header, 257, 6);

            // Accept POSIX ustar and common null-padded ustar magic.
            if ($magic !== "ustar\0" && $magic !== "ustar ") {
                throw new ClientError('Only ustar archives are supported');
            }

            $rawPath = $prefix === '' ? $name : $prefix . '/' . $name;
            $relativePath = normalizeArchivePath($rawPath);

            if (isset($seen[$relativePath])) {
                throw new ClientError('Duplicate archive pathname');
            }

            $seen[$relativePath] = true;
            $fileCount++;

            if ($fileCount > MAX_FILES) {
                throw new ClientError('Archive has too many entries');
            }

            $destination = $stagingRoot . '/' . $relativePath;

            // NUL and "0" both denote ordinary files.
            if ($type === "\0" || $type === '0') {
                if ($size > MAX_FILE_SIZE) {
                    throw new ClientError('Archive entry is too large');
                }

                if ($expandedSize > MAX_EXPANDED_SIZE - $size) {
                    throw new ClientError('Expanded archive is too large');
                }

                $expandedSize += $size;

                ensureSafeParentDirectories(
                    $stagingRoot,
                    $relativePath
                );

                if (file_exists($destination) || is_link($destination)) {
                    throw new ClientError(
                        'Archive entry conflicts with existing path'
                    );
                }

                /*
                 * "x+b" prevents overwriting an existing path. With the
                 * server-generated staging directory and link rejection,
                 * this provides a strong baseline. For hostile multi-user
                 * filesystems, perform extraction in a sandbox using
                 * openat2()/O_NOFOLLOW-style primitives.
                 */
                $output = fopen($destination, 'x+b');

                if ($output === false) {
                    throw new RuntimeException(
                        'Could not create extracted file'
                    );
                }

                try {
                    copyExact($input, $output, $size);

                    if (!fflush($output)) {
                        throw new RuntimeException(
                            'Could not flush extracted file'
                        );
                    }
                } finally {
                    fclose($output);
                }

                if (!chmod($destination, 0640)) {
                    throw new RuntimeException(
                        'Could not set extracted file permissions'
                    );
                }
            } elseif ($type === '5') {
                if ($size !== 0) {
                    throw new ClientError(
                        'Directory tar entry has nonzero size'
                    );
                }

                ensureSafeParentDirectories(
                    $stagingRoot,
                    $relativePath . '/placeholder'
                );

                if (is_link($destination)) {
                    throw new ClientError(
                        'Directory destination is a symbolic link'
                    );
                }

                if (file_exists($destination)) {
                    if (!is_dir($destination)) {
                        throw new ClientError(
                            'Directory conflicts with existing path'
                        );
                    }
                } elseif (!mkdir($destination, 0750)) {
                    throw new RuntimeException(
                        'Could not create archive directory'
                    );
                }

                if (!chmod($destination, 0750)) {
                    throw new RuntimeException(
                        'Could not set directory permissions'
                    );
                }
            } else {
                /*
                 * Reject links, devices, FIFOs, sparse files, pax headers,
                 * GNU long-name records, and all unknown extensions.
                 */
                throw new ClientError(
                    'Unsupported tar entry type'
                );
            }

            $padding = (512 - ($size % 512)) % 512;
            discardExact($input, $padding);
        }

        /*
         * After the two end blocks, permit only zero padding. This rejects
         * hidden trailing archive entries and concatenated archives.
         */
        while (!feof($input)) {
            $trailing = fread($input, 1024 * 1024);

            if ($trailing === false) {
                throw new RuntimeException(
                    'Could not read trailing archive data'
                );
            }

            if ($trailing === '') {
                break;
            }

            if (trim($trailing, "\0") !== '') {
                throw new ClientError(
                    'Nonzero data follows tar end marker'
                );
            }
        }
    } finally {
        fclose($input);
    }

    if ($fileCount === 0) {
        throw new ClientError('Archive is empty');
    }

    return [
        'entries' => $fileCount,
        'expanded_size' => $expandedSize,
    ];
}

function loadAndVerifyManifest(
    string $manifestPath,
    string $signaturePath,
    string $archivePath
): array {
    $manifestBytes = readSmallFile(
        $manifestPath,
        16_384,
        'manifest'
    );
    $signature = readSmallFile(
        $signaturePath,
        SODIUM_CRYPTO_SIGN_BYTES,
        'signature'
    );
    $publicKey = readSmallFile(
        PUBLIC_KEY_PATH,
        SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
        'public key'
    );

    if (
        strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
        strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    ) {
        throw new RuntimeException('Invalid configured signature material');
    }

    if (!sodium_crypto_sign_verify_detached(
        $signature,
        $manifestBytes,
        $publicKey
    )) {
        throw new ClientError(
            'Signature verification failed',
            403
        );
    }

    try {
        $manifest = json_decode(
            $manifestBytes,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new ClientError('Invalid manifest JSON');
    }

    if (!is_array($manifest)) {
        throw new ClientError('Invalid manifest');
    }

    // Reject both missing and unexpected fields.
    $expectedKeys = ['artifact', 'sha256', 'size', 'version'];
    $actualKeys = array_keys($manifest);
    sort($expectedKeys);
    sort($actualKeys);

    if ($actualKeys !== $expectedKeys) {
        throw new ClientError('Unexpected manifest fields');
    }

    if (
        !is_string($manifest['version']) ||
        preg_match(
            '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D',
            $manifest['version']
        ) !== 1 ||
        $manifest['version'] === '.' ||
        $manifest['version'] === '..'
    ) {
        throw new ClientError('Invalid version');
    }

    if ($manifest['artifact'] !== 'payload.tar') {
        throw new ClientError('Unexpected artifact name');
    }

    if (
        !is_string($manifest['sha256']) ||
        preg_match(
            '/\A[a-f0-9]{64}\z/D',
            $manifest['sha256']
        ) !== 1
    ) {
        throw new ClientError('Invalid SHA-256');
    }

    if (
        !is_int($manifest['size']) ||
        $manifest['size'] < 1 ||
        $manifest['size'] > MAX_ARCHIVE_SIZE
    ) {
        throw new ClientError('Invalid artifact size');
    }

    $actualSize = filesize($archivePath);

    if (
        $actualSize === false ||
        $actualSize !== $manifest['size']
    ) {
        throw new ClientError('Artifact size mismatch');
    }

    $actualHash = hash_file('sha256', $archivePath);

    if (
        !is_string($actualHash) ||
        !hash_equals($manifest['sha256'], $actualHash)
    ) {
        throw new ClientError('Artifact digest mismatch');
    }

    return $manifest;
}

function publishRelease(
    string $stagingPath,
    string $version
): string {
    $releasePath = RELEASE_ROOT . '/' . $version;

    if (file_exists($releasePath) || is_link($releasePath)) {
        throw new ClientError(
            'Version already exists',
            409
        );
    }

    /*
     * This is atomic only when staging and releases reside on the same
     * filesystem. It publishes the complete immutable directory.
     */
    if (!rename($stagingPath, $releasePath)) {
        throw new RuntimeException('Could not publish release');
    }

    return $releasePath;
}

function switchCurrent(string $version): void
{
    $link = '/data/current';
    $temporary = '/data/.current-' . bin2hex(random_bytes(8));
    $target = 'releases/' . $version;

    if (!symlink($target, $temporary)) {
        throw new RuntimeException('Could not create current pointer');
    }

    if (!rename($temporary, $link)) {
        @unlink($temporary);
        throw new RuntimeException('Could not switch current pointer');
    }
}
