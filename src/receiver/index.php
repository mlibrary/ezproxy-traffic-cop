<?php
declare(strict_types=1);
require_once(getenv("APP_HOME") . '/lib/transmission/setup.php');

/*
 * Requirements:
 *   - PHP 8.2+
 *   - ext-sodium
 *
 * Operational assumptions:
 *   - /data/staging and /data/releases are on the same filesystem/PVC.
 *   - /data/staging, /data/releases, and /data/deploy.lock are writable.
 *   - /run/secrets/uploader-ed25519.pub is a raw 32-byte public key.
 *   - The web server limits request size and execution time independently.
 */

$stagingPath = null;
$lock = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new ClientError('Method not allowed', 405);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_starts_with(
        strtolower($contentType),
        'multipart/form-data'
    )) {
        throw new ClientError(
            'Content-Type must be multipart/form-data',
            415
        );
    }

    /*
     * Caller authentication—OIDC, mTLS, or another strong mechanism—
     * should be enforced by the ingress/authenticating proxy before PHP.
     */
    $manifestPath = uploadedFile('manifest');
    $signaturePath = uploadedFile('signature');
    $archivePath = uploadedFile('artifact');

    $manifest = loadAndVerifyManifest(
        $manifestPath,
        $signaturePath,
        $archivePath
    );

    ensureDirectory(STAGING_ROOT);
    ensureDirectory(RELEASE_ROOT);

    $lock = fopen(LOCK_PATH, 'c');

    if ($lock === false) {
        throw new RuntimeException('Could not open deployment lock');
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        throw new ClientError(
            'Another deployment is in progress',
            409
        );
    }

    /*
     * Check under the lock to make replay/idempotency behavior predictable.
     */
    $existingRelease = RELEASE_ROOT . '/' . $manifest['version'];

    if (file_exists($existingRelease) || is_link($existingRelease)) {
        throw new ClientError(
            'Version already exists',
            409
        );
    }

    $stagingPath = STAGING_ROOT . '/' . bin2hex(random_bytes(16));

    if (!mkdir($stagingPath, 0750)) {
        throw new RuntimeException('Could not create staging directory');
    }

    $result = safelyExtractTar($archivePath, $stagingPath);

    /*
     * Optionally validate application-specific requirements here, for
     * example requiring data/index.json and validating its schema.
     */

    $releasePath = publishRelease(
        $stagingPath,
        $manifest['version']
    );

    switchCurrent($manifest['version']);
    $stagingPath = null;

    respond(201, [
        'status' => 'published',
        'version' => $manifest['version'],
        'sha256' => $manifest['sha256'],
        'entries' => $result['entries'],
        'expanded_size' => $result['expanded_size'],
        'release' => basename($releasePath),
    ]);
} catch (ClientError $error) {
    if ($stagingPath !== null) {
        try {
            removeTree($stagingPath);
        } catch (Throwable) {
            error_log('Failed to clean staging directory');
        }
    }

    respond($error->status, [
        'status' => 'rejected',
        'error' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    if ($stagingPath !== null) {
        try {
            removeTree($stagingPath);
        } catch (Throwable) {
            error_log('Failed to clean staging directory');
        }
    }

    error_log(sprintf(
        'Deployment receiver failure: %s',
        $error->getMessage()
    ));

    respond(500, [
        'status' => 'error',
        'error' => 'Internal server error'
    ]);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
