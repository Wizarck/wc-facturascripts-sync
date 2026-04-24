<?php
/**
 * @package WcFacturascriptsSync\Core
 */

namespace WcFacturascriptsSync\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by FsClient when an API call ultimately fails (after retries).
 */
class FsApiException extends \RuntimeException {}
