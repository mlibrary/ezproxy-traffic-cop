<?php

final class UploadException extends RuntimeException
{
}

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit($exitCode);
}

function requireReadableFile(string $path, string $label): string
{
    if (
        $path === '' ||
        !is_file($path) ||
        is_link($path) ||
        !is_readable($path)
    ) {
        throw new UploadException("Invalid or unreadable {$label}: {$path}");
    }

    $realPath = realpath($path);

    if ($realPath === false) {
        throw new UploadException(
            "Could not resolve {$label}: {$path}"
        );
    }

    return $realPath;
}

function validateVersion(string $version): string
{
    if (
        preg_match(
            '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D',
            $version
        ) !== 1 ||
        $version === '.' ||
        $version === '..'
    ) {
        throw new UploadException(
            'Version must be 1–100 characters using only ' .
            'letters, digits, period, underscore, or hyphen'
        );
    }

    return $version;
}

function validateHttpsUrl(string $url): string
{
    $parts = parse_url($url);

    if ($parts['host'] !== 'localhost' && (
        $parts === false ||
        ($parts['scheme'] ?? '') !== 'https' ||
        !isset($parts['host']) ||
        $parts['host'] === '' ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        isset($parts['fragment'])
    )) {
        throw new UploadException(
            'Upload URL must be an HTTPS URL without embedded credentials'
        );
    }

    return $url;
}

/**
 * Read a raw Ed25519 private key.
 *
 * This expects the 64-byte secret-key format used by PHP Sodium's
 * sodium_crypto_sign_detached(), not a PEM-encoded key.
 */
function readSigningKey(string $path): string
{
    $path = requireReadableFile($path, 'private signing key');

    $key = file_get_contents($path);

    if (
        $key === false ||
        strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
    ) {
        throw new UploadException(
            'Signing key must be a raw ' .
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES .
            '-byte Ed25519 secret key'
        );
    }

    return $key;
}

/**
 * Create deterministic manifest bytes.
 *
 * The receiver verifies the exact bytes, so do not decode and re-encode
 * the manifest after signing.
 */
function createSignedManifest(
    string $archivePath,
    string $version,
    string $privateKey
): array {
    $size = filesize($archivePath);

    if (
        $size === false ||
        $size < 1 ||
        $size > MAX_ARCHIVE_SIZE
    ) {
        throw new UploadException(
            'Archive size is outside the allowed range'
        );
    }

    $sha256 = hash_file('sha256', $archivePath);

    if (!is_string($sha256)) {
        throw new UploadException(
            'Could not calculate archive SHA-256'
        );
    }

    /*
     * Keep this key set identical to the receiver's expected fields.
     * PHP preserves insertion order when encoding associative arrays.
     */
    $manifest = [
        'artifact' => 'payload.tar',
        'sha256' => $sha256,
        'size' => $size,
        'version' => $version,
    ];

    $manifestBytes = json_encode(
        $manifest,
        JSON_THROW_ON_ERROR |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    $signature = sodium_crypto_sign_detached(
        $manifestBytes,
        $privateKey
    );

    return [
        'manifest' => $manifest,
        'manifest_bytes' => $manifestBytes,
        'signature' => $signature,
    ];
}

/**
 * Write sensitive temporary upload material using restrictive permissions.
 *
 * CURLStringFile could carry the in-memory manifest and signature, but
 * temporary files work across a wider range of supported PHP/cURL versions.
 */
function writeTemporaryFile(
    string $directory,
    string $prefix,
    string $contents
): string {
    $path = tempnam($directory, $prefix);

    if ($path === false) {
        throw new UploadException('Could not create temporary file');
    }

    try {
        if (!chmod($path, 0600)) {
            throw new UploadException(
                'Could not restrict temporary-file permissions'
            );
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new UploadException(
                'Could not open temporary file'
            );
        }

        try {
            $length = strlen($contents);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite(
                    $handle,
                    substr($contents, $offset)
                );

                if ($written === false || $written === 0) {
                    throw new UploadException(
                        'Could not write temporary file'
                    );
                }

                $offset += $written;
            }

            if (!fflush($handle)) {
                throw new UploadException(
                    'Could not flush temporary file'
                );
            }
        } finally {
            fclose($handle);
        }

        return $path;
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
}

/**
 * Upload the three multipart fields.
 *
 * Authentication options:
 *   - bearerToken for OIDC/OAuth access token
 *   - clientCert and clientKey for mTLS
 *
 * The options may be used together if infrastructure requires both.
 */
function uploadRelease(
    string $url,
    string $archivePath,
    string $manifestPath,
    string $signaturePath,
    ?string $bearerToken = null,
    ?string $clientCert = null,
    ?string $clientKey = null,
    ?string $clientKeyPassword = null,
    ?string $caFile = null
): array {
    $curl = curl_init();

    if ($curl === false) {
        throw new UploadException(
            'Could not initialize libcurl'
        );
    }

    $responseBody = '';
    $responseTooLarge = false;

    /*
     * CURLFile instructs libcurl to create a multipart/form-data request.
     * Do not manually set Content-Type; libcurl supplies the boundary.
     */
    $postFields = [
        'manifest' => new CURLFile(
            $manifestPath,
            'application/json',
            'manifest.json'
        ),
        'signature' => new CURLFile(
            $signaturePath,
            'application/octet-stream',
            'manifest.sig'
        ),
        'artifact' => new CURLFile(
            $archivePath,
            'application/x-tar',
            'payload.tar'
        ),
    ];

    $headers = [
        'Accept: application/json',
        'Expect:',
    ];

    if ($bearerToken !== null) {
        if (
            $bearerToken === '' ||
            str_contains($bearerToken, "\r") ||
            str_contains($bearerToken, "\n")
        ) {
            throw new UploadException(
                'Invalid bearer token'
            );
        }

        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => $headers,

        /*
         * Never follow redirects while carrying credentials or a signed
         * upload. A redirect is returned as an error to the caller.
         */
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,

        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => TOTAL_TIMEOUT_SECONDS,

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        /*
         * Fail only for transport errors here. Keeping FAILONERROR false
         * lets us capture the receiver's JSON body on HTTP 4xx/5xx.
         */
        CURLOPT_FAILONERROR => false,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_USERAGENT => 'volume-sync-uploader/1.0',

        /*
         * Limit the response captured in memory. Returning 0 from the
         * callback aborts the transfer if the receiver exceeds the limit.
         */
        CURLOPT_WRITEFUNCTION => static function (
            CurlHandle $handle,
            string $chunk
        ) use (&$responseBody, &$responseTooLarge): int {
            if (
                strlen($responseBody) + strlen($chunk) >
                RESPONSE_LIMIT
            ) {
                $responseTooLarge = true;
                return 0;
            }

            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];

    /*
     * Require TLS 1.2 or later. TLS 1.3 remains available when supported.
     */
    if (defined('CURL_SSLVERSION_TLSv1_2')) {
        $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
    }

    if ($caFile !== null) {
        $options[CURLOPT_CAINFO] = requireReadableFile(
            $caFile,
            'CA certificate'
        );
    }

    if (($clientCert === null) !== ($clientKey === null)) {
        throw new UploadException(
            'Both client certificate and client key are required for mTLS'
        );
    }

    if ($clientCert !== null && $clientKey !== null) {
        $options[CURLOPT_SSLCERT] = requireReadableFile(
            $clientCert,
            'client certificate'
        );
        $options[CURLOPT_SSLKEY] = requireReadableFile(
            $clientKey,
            'client private key'
        );

        if (
            $clientKeyPassword !== null &&
            $clientKeyPassword !== ''
        ) {
            $options[CURLOPT_KEYPASSWD] = $clientKeyPassword;
        }
    }

    if (!curl_setopt_array($curl, $options)) {
        curl_close($curl);
        throw new UploadException(
            'Could not configure libcurl'
        );
    }

        $ok = curl_exec($curl);
        $curlErrorNumber = curl_errno($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );
        $effectiveUrl = (string) curl_getinfo(
            $curl,
            CURLINFO_EFFECTIVE_URL
        );

        if ($responseTooLarge) {
            throw new UploadException(
                'Receiver response exceeded the configured limit'
            );
        }

        if ($ok === false || $curlErrorNumber !== CURLE_OK) {
            throw new UploadException(
                sprintf(
                    'Upload transport failure (%d): %s',
                    $curlErrorNumber,
                    $curlError
                )
            );
        }

        $json = null;

        if ($responseBody !== '') {
            try {
                $decoded = json_decode(
                    $responseBody,
                    true,
                    32,
                    JSON_THROW_ON_ERROR
                );

                if (is_array($decoded)) {
                    $json = $decoded;
                }
            } catch (JsonException) {
                // Preserve the raw bounded body for diagnostics.
            }
        }

        return [
            'status' => $status,
            'effective_url' => $effectiveUrl,
            'body' => $responseBody,
            'json' => $json,
        ];
}
