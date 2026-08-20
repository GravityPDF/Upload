<?php

/**
 * Upload
 *
 * @author      Josh Lockhart <info@joshlockhart.com>
 * @copyright   2012 Josh Lockhart
 * @link        http://www.joshlockhart.com
 *
 * MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

declare(strict_types=1);

namespace GravityPdf\Upload\Storage;

use InvalidArgumentException;
use GravityPdf\Upload\ErrorCode;
use GravityPdf\Upload\Exception;
use GravityPdf\Upload\Filename;
use GravityPdf\Upload\FileInfoInterface;
use GravityPdf\Upload\StorageInterface;

/**
 * FileSystem Storage
 *
 * Three protections are on by default and each has to be turned off explicitly: existing files
 * are never overwritten (`$overwrite`), the extensions in `getDefaultBlockedExtensions()` are
 * never written (`allowAnyExtension()`), and stored files get `DEFAULT_MODE` rather than
 * whatever the process umask allows (`setMode(null)`).
 *
 * @author  Josh Lockhart <info@joshlockhart.com>
 * @since   1.0.0
 * @package Upload
 */
class FileSystem implements StorageInterface
{
    /**
     * Extensions a web server is commonly configured to execute or read as configuration
     *
     * Also covers client-side binary formats. The list is not exhaustive; an allow-list via
     * `\GravityPdf\Upload\Validation\FileType` is the primary control.
     *
     * @var string[]
     */
    public const EXECUTABLE_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'phtml', 'phtm',
        'phar', 'pht', 'inc',
        'shtml', 'shtm', 'stm',
        'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'ps1',
        'jsp', 'jspx', 'jspf', 'jsw', 'jsv', 'jshtml', 'jar', 'war',
        'asp', 'aspx', 'asa', 'asax', 'ascx', 'ashx', 'asmx', 'cer', 'cshtml', 'vbhtml',
        'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'vbs', 'ws', 'wsf', 'hta',
        'htaccess', 'htpasswd', 'ini', 'conf', 'config',
    ];

    /**
     * Extensions a browser renders as markup or runs as script
     *
     * These are not executed by the server, so the risk is stored XSS: a request for the
     * uploaded file returns markup that runs in the origin serving it, with access to that
     * origin's cookies and storage. SVG counts, because it carries `<script>` and event
     * handler attributes.
     *
     * @var string[]
     */
    public const MARKUP_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'xhtm',
        'svg', 'svgz',
        'xml', 'xsl', 'xslt',
        'js', 'mjs',
        'swf',
        'mht', 'mhtml',
    ];

    /**
     * Permissions applied to a stored file unless `setMode()` says otherwise
     *
     * Owner read/write and group read. `move_uploaded_file()` otherwise leaves the mode to the
     * process umask, which on a typical `022` lands the file world-readable.
     */
    public const DEFAULT_MODE = 0640;

    /**
     * Path to upload destination directory (with trailing slash)
     * @var string
     */
    protected $directory;

    /**
     * Overwrite existing files?
     * @var bool
     */
    protected $overwrite;

    /**
     * Extensions that may never be written (lowercase, without leading dot)
     * @var string[]
     */
    protected $blockedExtensions = [];

    /**
     * Permissions to apply to the stored file, or null to leave the process umask in charge
     * @var int|null
     */
    protected $mode;

    /**
     * May this store a file PHP did not receive as an upload?
     * @var bool
     */
    protected $acceptsUnuploadedFiles = false;

    /**
     * Constructor
     *
     * @throws InvalidArgumentException            If directory does not exist
     * @throws InvalidArgumentException            If directory is not writable
     */
    public function __construct(string $directory, bool $overwrite = false)
    {
        if (!is_dir($directory)) {
            throw new InvalidArgumentException('Directory does not exist');
        }

        if (!is_writable($directory)) {
            throw new InvalidArgumentException('Directory is not writable');
        }

        $this->directory = rtrim($directory, '/') . DIRECTORY_SEPARATOR;
        $this->overwrite = $overwrite;

        $this->blockExtensions(self::getDefaultBlockedExtensions());
        $this->setMode(self::DEFAULT_MODE);
    }

    /**
     * The extensions refused unless `blockExtensions()` is given a list of its own
     *
     * A method rather than a constant because constant expressions cannot call `array_merge()`
     * on PHP 7.3.
     *
     * @return string[]
     */
    public static function getDefaultBlockedExtensions(): array
    {
        return array_merge(self::EXECUTABLE_EXTENSIONS, self::MARKUP_EXTENSIONS);
    }

    /**
     * Refuse to store files with any of these extensions
     *
     * On by default; a last line of defence that applies whatever validations the caller
     * configured, matched against the sanitized extension about to be written.
     *
     * Entries are lowercased, trimmed, stripped of a leading dot and split on any remaining
     * dots, because the deny-list is matched one dot-separated component at a time: `tar.gz`
     * blocks `tar` and `gz` independently rather than nothing at all, and `.php` is taken as
     * `php` rather than quietly blocking nothing.
     *
     * Empty is refused so a missing config key cannot turn the control off;
     * `allowAnyExtension()` is the only way to the empty list, and it is greppable.
     *
     * @param string[] $extensions Extensions with or without leading dots
     * @return FileSystem Self
     * @throws InvalidArgumentException If the list is empty; call `allowAnyExtension()` instead
     *
     * ```php
     * $storage->blockExtensions(array_merge(FileSystem::getDefaultBlockedExtensions(), ['csv']));
     * ```
     */
    public function blockExtensions(array $extensions): FileSystem
    {
        if ($extensions === []) {
            throw new InvalidArgumentException(
                'blockExtensions() cannot be given an empty list. Call allowAnyExtension() to '
                . 'store any extension, including the ones a server executes.'
            );
        }

        $blocked = [];

        foreach ($extensions as $extension) {
            foreach (Filename::normalizeComponents($extension) as $component) {
                $blocked[] = $component;
            }
        }

        $this->blockedExtensions = array_values(array_unique($blocked));

        return $this;
    }

    /**
     * Store any extension, including the ones a server executes
     *
     * The only way to empty the deny-list, so turning a security control off is always this
     * call and never a config value that happened to be missing. Deliberately greppable.
     *
     * @return FileSystem Self
     */
    public function allowAnyExtension(): FileSystem
    {
        $this->blockedExtensions = [];

        return $this;
    }

    /**
     * The extensions currently refused at the write
     *
     * Every entry is lowercase, carries no leading dot and names one dot-separated component,
     * whatever shape it was passed in. An empty array means the deny-list is off.
     *
     * @return string[]
     */
    public function getBlockedExtensions(): array
    {
        return $this->blockedExtensions;
    }

    /**
     * Set the permissions applied to each stored file
     *
     * Defaults to `self::DEFAULT_MODE`. Pass `null` to leave the mode to the process umask,
     * which is what `move_uploaded_file()` does on its own.
     *
     * @return FileSystem Self
     */
    public function setMode(?int $mode): FileSystem
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * The permissions applied to each stored file, or `null` to leave them to the umask
     */
    public function getMode(): ?int
    {
        return $this->mode;
    }

    /**
     * Whether an existing file at the destination is replaced rather than refused
     */
    public function getOverwrite(): bool
    {
        return $this->overwrite;
    }

    /**
     * Upload
     *
     * The file is written to an unpredictable name inside the destination directory and then
     * `rename()`d onto the destination. `rename()` replaces the directory entry rather than
     * following it, so the bytes never travel through a symbolic link someone planted at the
     * destination, and no partial content is ever readable under the final name.
     *
     * With `overwrite = false` — the default — the final name is not free during the transfer:
     * `reserveDestination()` claims it first with an empty file, so that two requests cannot
     * both win it. That placeholder carries the configured mode, and `rename()` replaces it
     * with the finished upload. A process killed mid-transfer therefore leaves a 0-byte file
     * under the name rather than nothing, and the next upload of that name reports a collision
     * until it is cleared. With `overwrite = true` there is no placeholder and no such window.
     *
     * @throws Exception If the file cannot be stored safely
     */
    public function upload(FileInfoInterface $fileInfo): string
    {
        /* `resolveFilename()` is the naming seam a subclass may replace; the refusals below are
           not, and are applied to whatever name it returns. Inside it, an override that said
           nothing about extensions dropped them both. */
        $filename = $this->resolveFilename($fileInfo);
        $this->refuseUnsafeName($filename, $fileInfo);
        $this->refuseReservedWindowsName($filename, $fileInfo);
        $this->refuseBlockedExtensions($filename, $fileInfo);

        $destinationFile = $this->directory . $filename;

        /* `is_file()` follows symlinks, so it cannot see a dangling one. This reports the
           ordinary case with a message that says what is wrong; it is not what makes the write
           safe, because anything checked here can change before the write happens. */
        if (is_link($destinationFile)) {
            throw new Exception('Destination is a symbolic link', $fileInfo, ErrorCode::DESTINATION_IS_SYMLINK);
        }

        $stagingFile = $this->createStagingPath($fileInfo);

        if ($this->overwrite === false) {
            $this->reserveDestination($destinationFile, $fileInfo);
        }

        if ($this->moveIntoStaging($fileInfo->getPathname(), $stagingFile) === false) {
            $this->discardFailedUpload($stagingFile, $destinationFile);

            throw new Exception('File could not be moved to final destination.', $fileInfo, ErrorCode::MOVE_FAILED);
        }

        /* On the staged file, so there is no moment where the finished upload is readable
           under its final name at the umask's mode instead of this one */
        if ($this->mode !== null && @chmod($stagingFile, $this->mode) === false) {
            $this->discardFailedUpload($stagingFile, $destinationFile);

            throw new Exception(
                'Permissions could not be applied to the stored file',
                $fileInfo,
                ErrorCode::CHMOD_FAILED
            );
        }

        if (@rename($stagingFile, $destinationFile) === false) {
            $this->discardFailedUpload($stagingFile, $destinationFile);

            throw new Exception('File could not be moved to final destination.', $fileInfo, ErrorCode::MOVE_FAILED);
        }

        return $destinationFile;
    }

    /**
     * A name in the destination directory that nobody else can predict
     *
     * `move_uploaded_file()` renames when the temporary directory and the upload directory share
     * a file system and falls back to a stream copy when they do not, and that copy follows a
     * symlink at the destination. Sending it to a name an attacker cannot guess is what removes
     * the race; the `rename()` that follows never resolves a link.
     *
     * @throws Exception If the platform cannot produce random bytes
     */
    private function createStagingPath(FileInfoInterface $fileInfo): string
    {
        try {
            $unique = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new Exception(
                'Could not generate a temporary file name',
                $fileInfo,
                ErrorCode::STAGING_NAME_FAILED,
                [],
                0,
                $e
            );
        }

        return $this->directory . 'upload-' . $unique . '.part';
    }

    /**
     * Claim the destination name for this upload before anything is written
     *
     * Protected so a test can reach the branches that need a symlink planted mid-call, which is
     * otherwise a race; not an extension seam.
     *
     * @throws Exception If the name is taken, or cannot be claimed safely
     */
    protected function reserveDestination(string $destinationFile, FileInfoInterface $fileInfo): void
    {
        /* O_EXCL combines the existence check and the create into one operation. Checking
           is_file() first lets two concurrent requests both pass it. */
        $handle = @fopen($destinationFile, 'xb');

        if (!is_resource($handle)) {
            /* `x` also fails when the directory has been removed or its permissions changed
               since the constructor checked it. Calling that a collision sends the caller down
               a rename-and-retry path that cannot succeed. */
            if (file_exists($destinationFile) || is_link($destinationFile)) {
                /* Name the file, because sanitizing is many-to-one: `report?.txt` and
                   `report*.txt` both resolve to `report-.txt`, so a caller told only that
                   something already exists cannot tell which of their names collided. The
                   basename, never the path, and sanitized rather than trusted: it comes from
                   `resolveFilename()`, the naming seam, so an override decides what reaches
                   this line and one that keeps the characters its parent refuses would put
                   them in a message bound for a log.

                   Escape it on output, and do not parse this message: `sanitizeForDisplay()`
                   sanitizes prose, so `"` passes through it, and the quotes are there to show
                   where the name starts and ends rather than to delimit a field. The base
                   `resolveFilename()` rewrites `"` to `-`, so only a seam override hands
                   one down.

                   This message and 'Destination file could not be created' are distinguishable,
                   and the distinction is precisely "does this name exist in the upload
                   directory". Anything that renders a storage exception to whoever submitted
                   the file hands them a free existence oracle for it, one that leaves nothing
                   behind because no file is written. Storage messages are for your logs;
                   `getErrors()` is the list written to be shown. */
                $displayName = Filename::sanitizeForDisplay(basename($destinationFile));

                /* A name that was nothing but those characters leaves an empty pair of quotes,
                   which names nothing */
                if ($displayName === '') {
                    throw new Exception(
                        'A file with that name already exists',
                        $fileInfo,
                        ErrorCode::DESTINATION_EXISTS
                    );
                }

                throw new Exception(
                    'A file named "%1$s" already exists',
                    $fileInfo,
                    ErrorCode::DESTINATION_EXISTS,
                    [$displayName]
                );
            }

            throw new Exception(
                'Destination file could not be created',
                $fileInfo,
                ErrorCode::DESTINATION_NOT_CREATED
            );
        }

        $opened = fstat($handle);
        fclose($handle);

        /* PHP resolves the path through its own stream layer before the exclusive create, so
           `x` follows a symlink where the underlying O_EXCL refuses one. What was just created
           is the destination only if the directory entry is that same file; a symlink has an
           inode of its own, so a mismatch means the name was a link.

           POSIX only. Before PHP 7.4 `stat()` on Windows reports `ino` as 0 and `dev` as the
           drive number, so this comparison degrades to same-drive and detects nothing. Windows
           symlinks need a privilege an uploading process should not hold, so the residual risk
           is small, but the symlink protections in this class are not load-bearing there. */
        $entry = $this->lstatEntry($destinationFile);

        if ($opened === false || $entry === false) {
            /* Not reported as a symlink: neither stat answered, so nothing has been established
               about what the name is. Unlike a mismatch, this branch has to clean up after
               itself — the entry is a real, empty file this call created, and left behind it
               takes the caller's name permanently, so every later upload of it collides. */
            $this->releaseReservation($destinationFile, $opened);

            throw new Exception(
                'Destination file could not be created',
                $fileInfo,
                ErrorCode::DESTINATION_NOT_CREATED
            );
        }

        if ($opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
            /* The write is already refused at this point and nothing of the victim's was
               overwritten, but `x` has created a file at the far end of the link, outside the
               upload directory. Take that back too. */
            $this->releaseReservation($destinationFile, $opened);

            throw new Exception('Destination is a symbolic link', $fileInfo, ErrorCode::DESTINATION_IS_SYMLINK);
        }

        /* `x` creates at `0666 & ~umask`, which is usually world-readable, and the placeholder
           holds the final name for the whole transfer. Not before the inode check above: a
           chmod by path would follow a symlink and re-mode someone else's file. A failure is
           not fatal — the file is empty, so there is nothing to read out of it, and the mode
           that matters is the one applied to the staged file before it is renamed here. */
        if ($this->mode !== null) {
            @chmod($destinationFile, $this->mode);
        }
    }

    /**
     * `lstat()` on the directory entry itself, following no symlink
     *
     * Wrapped so a test can exercise the branch where the stat does not answer, which is
     * otherwise only reachable by racing the file system.
     *
     * @return array<int|string, int>|false
     */
    protected function lstatEntry(string $path)
    {
        return @lstat($path);
    }

    /**
     * Remove the entry a reservation created, wherever `x` mode actually put it
     *
     * @param array<int|string, int>|false $opened `fstat()` of the handle this call opened
     */
    private function releaseReservation(string $destinationFile, $opened): void
    {
        $target = @readlink($destinationFile);

        if ($target === false) {
            /* Not a link, so the name is the file. `unlink()` does not follow one in any case. */
            @unlink($destinationFile);

            return;
        }

        /* A symlink was already here and `x` created its target. Remove that file and only that
           file: the inode has to be the one this call opened, so a link re-pointed between the
           create and this check cannot make us delete a bystander. Failing that test leaves the
           file behind, which is the safe way to be wrong. The link itself stays — it was not
           this upload's to create, so it is not this upload's to remove. */
        if ($opened === false) {
            return;
        }

        if (preg_match('~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $target) !== 1) {
            $target = dirname($destinationFile) . DIRECTORY_SEPARATOR . $target;
        }

        $stat = @lstat($target);

        if ($stat !== false && $stat['dev'] === $opened['dev'] && $stat['ino'] === $opened['ino']) {
            @unlink($target);
        }
    }

    /**
     * Remove what a failed upload left behind
     *
     * The destination is only this upload's to remove when `overwrite` is off, where it holds
     * the placeholder `reserveDestination()` created. With overwriting on, whatever is there
     * belongs to someone else and the upload has not touched it.
     */
    private function discardFailedUpload(string $stagingFile, string $destinationFile): void
    {
        @unlink($stagingFile);

        if ($this->overwrite === false) {
            @unlink($destinationFile);
        }
    }

    /**
     * Reduce a `FileInfoInterface` name to a filename that stays in the upload directory
     *
     * `FileInfoInterface` is a public extension point, so the name arriving here is only as
     * trustworthy as the implementation the caller (or `FileInfo::setFactory()`) installed.
     * This class should not assume sanitizing happened elsewhere.
     *
     * Derivation only: this decides what the name *is*, and `upload()` decides whether it may
     * be written. Overriding this therefore cannot drop a refusal — see `refuseUnsafeName()`.
     */
    protected function resolveFilename(FileInfoInterface $fileInfo): string
    {
        $filename = basename(str_replace('\\', '/', $fileInfo->getNameWithExtension()));

        /* Windows drops trailing dots and spaces when it resolves a name, so `evil.php.` and
           `evil.php ` both open `evil.php`. Take them off here, so that what is checked below
           is the name the file system will actually answer to. */
        $filename = rtrim($filename, " .");

        /* Windows refuses these in a filename, and `:` does something worse than fail on NTFS:
           it names an alternate data stream, so a write to `report.txt:payload` lands beside a
           benign-looking entry that a directory listing sizes at zero. Rewritten rather than
           refused, because POSIX allows every one of them and refusing would reject a name that
           is perfectly ordinary on the system doing the storing. Runs before the checks below,
           so they see the name that will actually be written.

           `FileInfo::sanitizeName()` rewrites this set too, along with `%`, `/` and `\`; keep
           the two in step. */
        $filename = (string) preg_replace('/[<>:"|?*]/', '-', $filename);

        return $filename;
    }

    /**
     * Refuse a name that is not a usable, visible filename
     *
     * `basename()` has removed every separator by the time this runs. What is left to reject
     * are the two directory entries, a name hidden from the shell globbing and directory
     * listings a cleanup script relies on, and the characters that let a name forge a line in
     * a log or a terminal — `Filename::CONTROL_CHARACTERS` read from the constant rather than
     * copied, so this and `FileInfo` cannot drift apart again.
     *
     * The bidi controls are refused for the reason the control characters are: they carry no
     * visual content of their own, so `resume\u{202E}gpj.exe` reads as `resumeexe.jpg` wherever
     * a person sees it while the stored file is still the executable. Refused rather than
     * deleted, because inventing a name is the value object's job — the shipped `FileInfo`
     * deletes exactly this set, so only an implementation of your own arrives here with one.
     * Keep the two in step.
     *
     * A leading dot is refused here rather than left to the deny-list, which does not see one:
     * `Filename::extensionComponents()` drops the empty component before the dot along with the
     * first real one, so `.htaccess` presents no extension to match and `.env` is not on the
     * list in the first place.
     *
     * @throws Exception If the name is not one that may be written
     */
    private function refuseUnsafeName(string $filename, FileInfoInterface $fileInfo): void
    {
        if (
            $filename === ''
            || strpos($filename, '.') === 0
            || Filename::hasControlCharacters($filename)
            || Filename::hasBidiControls($filename)
        ) {
            throw new Exception('Invalid destination file name', $fileInfo, ErrorCode::INVALID_DESTINATION_NAME);
        }
    }

    /**
     * Refuse a name Windows resolves to a device rather than a file
     *
     * See `Filename::RESERVED_WINDOWS_NAMES` for which names these are and why the component
     * before the first dot decides it. Screened here as well as in `FileInfo` because a
     * `FileInfoInterface` is a public extension point and this class does not trust what one
     * returns. It throws rather than blanking the name, because inventing a filename is the
     * value object's job, not storage's.
     *
     * @throws Exception If the name resolves to a device
     */
    private function refuseReservedWindowsName(string $filename, FileInfoInterface $fileInfo): void
    {
        if (Filename::isReservedDeviceComponent(Filename::deviceComponent($filename))) {
            throw new Exception('Invalid destination file name', $fileInfo, ErrorCode::INVALID_DESTINATION_NAME);
        }
    }

    /**
     * Refuse a name carrying a blocked extension anywhere in it
     *
     * Every dot-separated component is checked, not just the last one, because a server does not
     * necessarily read the name the way `pathinfo()` does: Apache's `AddHandler`/`AddType` match
     * any dot component, so `evil.php.jpg` is served as PHP on a configuration that maps `.php`.
     *
     * @throws Exception If any component is on the deny-list
     */
    private function refuseBlockedExtensions(string $filename, FileInfoInterface $fileInfo): void
    {
        $components = Filename::extensionComponents($filename);

        foreach ($components as $component) {
            $component = trim($component);

            if (in_array($component, $this->blockedExtensions, true)) {
                throw new Exception(
                    'Files with the extension "%1$s" cannot be stored',
                    $fileInfo,
                    ErrorCode::BLOCKED_EXTENSION,
                    [$component]
                );
            }
        }
    }

    /**
     * Get directory (without trailing slash)
     */
    public function getDirectory(): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Store files PHP did not receive as uploads
     *
     * `move_uploaded_file()` refuses any source that is not in PHP's own register of files
     * this request received as `multipart/form-data`, and that refusal is a real control: it
     * is what stops a path an attacker steered — `/etc/passwd`, another user's upload, a
     * config file — being moved into the upload directory under a name of their choosing. It
     * is also why a `File` built from `$_FILES` is the only thing this class can store by
     * default, and why `FileList` cannot store anything without this call.
     *
     * Turning it on hands that judgement to the caller, exactly as overriding
     * `FileInfo::isUploadedFile()` does on the input side. **The two go together**: a caller
     * on PSR-7 or a worker runtime needs both, and neither is worth making without a check of
     * their own that says where the file legitimately came from. Nothing else changes — the
     * staged write, the destination reservation, the deny-list, the symlink refusals and the
     * mode all apply as they always did.
     */
    public function acceptFilesNotUploadedByPhp(): FileSystem
    {
        $this->acceptsUnuploadedFiles = true;

        return $this;
    }

    /**
     * Will this store a file PHP did not receive as an upload?
     *
     * `false` unless `acceptFilesNotUploadedByPhp()` was called, so a test or a security scan
     * can assert the policy rather than infer it.
     */
    public function acceptsFilesNotUploadedByPhp(): bool
    {
        return $this->acceptsUnuploadedFiles;
    }

    /**
     * Move the file to the staging path, by whichever route the caller has authorised
     */
    private function moveIntoStaging(string $source, string $stagingFile): bool
    {
        if ($this->acceptsUnuploadedFiles === false) {
            return $this->moveUploadedFile($source, $stagingFile);
        }

        return $this->moveFile($source, $stagingFile);
    }

    /**
     * Move uploaded file
     *
     * This method allows us to stub this method in unit tests to avoid
     * hard dependency on the `move_uploaded_file` function.
     *
     * @return bool
     */
    protected function moveUploadedFile(string $source, string $destination): bool
    {
        return move_uploaded_file($source, $destination);
    }

    /**
     * The same move without the SAPI's assertion about where the file came from, for a source
     * `acceptFilesNotUploadedByPhp()` has authorised
     *
     * `rename()` first, which is atomic within a file system and never resolves a symbolic
     * link at the destination.
     *
     * A tmp directory on tmpfs and an upload directory on disk — the standard container
     * layout — is
     * **not** what the copy below is for: the `rename(2)` syscall does fail with `EXDEV`
     * across two file systems, but PHP's own plain-files wrapper catches that and copies, so
     * `rename()` returns `true` and the bytes arrive. Verified against two real mounts. The
     * copy is for what PHP's own handling does not cover — a source behind a stream wrapper,
     * and any platform where its `rename()` reports the failure rather than absorbing it.
     *
     * Either way the destination here is the staging path and not the final name, because a
     * copy *does* follow a link at the destination: 32 hex characters nobody can predict, in
     * a directory this class already refuses to write a link into.
     *
     * A source left behind because it could not be unlinked does not fail the upload — the
     * bytes are safely at the destination by then, and the caller's tmp directory is theirs
     * to clean up.
     *
     * `private`, unlike its `move_uploaded_file()` counterpart: no test needs to stub it,
     * since a source behind a stream wrapper reaches the copy branch on its own.
     *
     * @return bool
     */
    private function moveFile(string $source, string $destination): bool
    {
        if (@rename($source, $destination) === true) {
            return true;
        }

        if (@copy($source, $destination) === false) {
            return false;
        }

        @unlink($source);

        return true;
    }
}
