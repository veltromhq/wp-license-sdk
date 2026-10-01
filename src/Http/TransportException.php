<?php

namespace Veltrom\License\Http;

use RuntimeException;

/**
 * The licensing server could not be reached. Never a licensing failure
 * (sdk-wordpress.md "Error handling rules", rule 1).
 */
final class TransportException extends RuntimeException
{
}
