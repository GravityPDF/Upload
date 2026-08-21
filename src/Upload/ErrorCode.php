<?php

/**
 * Upload
 *
 * @author      Gravity PDF
 * @copyright   2026 Gravity PDF
 * @link        https://github.com/GravityPDF/Upload
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

namespace GravityPdf\Upload;

/**
 * The stable identifier behind every message this library shows an end user
 *
 * `getErrors()` returns prose. Render it, but do not branch on it: it gets translated, and
 * the wording changes between releases. These codes do neither. Match one to react to a
 * particular failure, or to look up wording of your own.
 *
 * A code is not a message id. The message id is the English string, so it changes whenever the
 * wording does. `File::getErrorDetails()` and `Exception::getErrorCode()` give you both.
 *
 * @since   4.0.0
 *
 * @package Upload
 */
final class ErrorCode
{
    /** No code was supplied. What `Exception::getErrorCode()` answers for a throw of your own */
    public const NONE = '';

    /* The eight `UPLOAD_ERR_*` outcomes, as reported for an entry that never became a file */

    public const INI_SIZE = 'ini_size';
    public const FORM_SIZE = 'form_size';
    public const PARTIAL = 'partial';
    public const NO_FILE = 'no_file';
    public const NO_TMP_DIR = 'no_tmp_dir';
    public const CANT_WRITE = 'cant_write';
    public const EXTENSION_STOPPED = 'extension_stopped';
    public const UNKNOWN_TRANSFER_ERROR = 'unknown_transfer_error';

    /* The collection */

    /** `$_FILES` (or a `FileList`) held a shape this library cannot read */
    public const MALFORMED_UPLOAD = 'malformed_upload';

    /** `FileInfoInterface::isUploadedFile()` answered false */
    public const NOT_AN_UPLOADED_FILE = 'not_an_uploaded_file';

    /** A validation threw something other than `Upload\Exception`, and was absorbed */
    public const VALIDATION_INCOMPLETE = 'validation_incomplete';

    /** A validation rejected the file without naming a code of its own */
    public const VALIDATION_REJECTED = 'validation_rejected';

    /** `upload()` refused the batch because at least one file was rejected */
    public const VALIDATION_FAILED = 'validation_failed';

    /** `upload()` refused the batch because there was nothing in it */
    public const NO_FILES = 'no_files';

    /* The shipped validations */

    public const SIZE_UNKNOWN = 'size_unknown';
    public const SIZE_TOO_SMALL = 'size_too_small';
    public const SIZE_TOO_LARGE = 'size_too_large';
    public const EXTENSION_NOT_ALLOWED = 'extension_not_allowed';
    public const MIMETYPE_NOT_ALLOWED = 'mimetype_not_allowed';
    public const FILE_CONTENTS_MISMATCH = 'file_contents_mismatch';

    /* Storage. These reach a caller as an `Upload\Exception` and never as a `getErrors()`
       entry, and they are not translated: a storage message names the destination and is
       written for your log, not for whoever submitted the file. The code is here so a caller
       can tell a name collision from a refusal without reading the message. */

    public const DESTINATION_IS_SYMLINK = 'destination_is_symlink';
    public const DESTINATION_EXISTS = 'destination_exists';
    public const DESTINATION_NOT_CREATED = 'destination_not_created';
    public const INVALID_DESTINATION_NAME = 'invalid_destination_name';
    public const BLOCKED_EXTENSION = 'blocked_extension';
    public const MOVE_FAILED = 'move_failed';
    public const CHMOD_FAILED = 'chmod_failed';
    public const STAGING_NAME_FAILED = 'staging_name_failed';

    /**
     * The code for an `UPLOAD_ERR_*` constant
     *
     * `UPLOAD_ERR_OK` answers `UNKNOWN_TRANSFER_ERROR` along with every code PHP does not
     * define, because this is only ever asked about an entry that failed: a successful one
     * has no failure to name. That matches the `Unknown error` message the same entry gets.
     */
    public static function forUploadError(int $errorCode): string
    {
        $codes = [
            UPLOAD_ERR_INI_SIZE => self::INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => self::FORM_SIZE,
            UPLOAD_ERR_PARTIAL => self::PARTIAL,
            UPLOAD_ERR_NO_FILE => self::NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR => self::NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => self::CANT_WRITE,
            UPLOAD_ERR_EXTENSION => self::EXTENSION_STOPPED,
        ];

        return $codes[$errorCode] ?? self::UNKNOWN_TRANSFER_ERROR;
    }
}
