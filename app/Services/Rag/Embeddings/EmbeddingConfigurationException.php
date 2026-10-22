<?php

namespace App\Services\Rag\Embeddings;

use RuntimeException;

/**
 * A permanent failure: the embedding provider is misconfigured (missing or
 * rejected API key), so retrying won't help until the configuration is fixed.
 */
class EmbeddingConfigurationException extends RuntimeException {}
