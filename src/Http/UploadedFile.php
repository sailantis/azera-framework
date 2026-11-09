<?php

namespace Azera\Http;

/**
 * Represents a single file uploaded with an HTTP multipart request.
 *
 * Created from the $_FILES superglobal by {@see Request::file()} /
 * {@see Request::files()}. Call {@see isValid()} before processing
 * and {@see moveTo()} to persist the file.
 */
class UploadedFile
{
    /**
     * Create a new UploadedFile from raw PHP file upload data.
     *
     * @param string $name    Original client-supplied file name.
     * @param string $type    Client-supplied MIME type (not verified).
     * @param string $tmpName Temporary path on the server.
     * @param int    $error   One of the UPLOAD_ERR_* constants.
     * @param int    $size    File size in bytes.
     */
    public function __construct(
        protected string $name,
        protected string $type,
        protected string $tmpName,
        protected int $error,
        protected int $size
    ) {}

    /**
     * Return the original file name as provided by the client.
     *
     * Do NOT use this value for file system operations without sanitising it first.
     *
     * @return string Client-supplied file name.
     */
    public function clientFilename(): string
    {
        return $this->name;
    }

    /**
     * Return the MIME type as provided by the client (not verified server-side).
     *
     * @return string Client-supplied media type (e.g. "image/jpeg").
     */
    public function clientMediaType(): string
    {
        return $this->type;
    }

    /**
     * Return the file size in bytes as reported by the upload.
     *
     * @return int File size in bytes.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * Check whether the file was uploaded without errors.
     *
     * @return bool True if the upload succeeded (UPLOAD_ERR_OK).
     */
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * Move the uploaded file to a permanent location.
     *
     * @param string $targetPath Destination file path.
     * @throws \RuntimeException If the upload is invalid or the move fails.
     */
    public function moveTo(string $targetPath): void
    {
        if (!$this->isValid()) {
            throw new \RuntimeException("Cannot move invalid uploaded file");
        }

        if (!move_uploaded_file($this->tmpName, $targetPath)) {
            throw new \RuntimeException("Failed to move uploaded file");
        }
    }
}