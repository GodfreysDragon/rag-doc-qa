<?php

namespace App\Services\Rag\Extraction;

use RuntimeException;

/**
 * A permanent failure: retrying the same file will not help.
 */
class UnprocessableDocumentException extends RuntimeException {}
