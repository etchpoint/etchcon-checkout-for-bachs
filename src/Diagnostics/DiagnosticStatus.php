<?php
/**
 * Diagnostic status values.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Diagnostics;

/**
 * Severity/state for a safe operational diagnostic check.
 */
enum DiagnosticStatus: string {
	/** Check passed. */
	case HEALTHY = 'healthy';

	/** Check needs attention but does not necessarily block payments. */
	case WARNING = 'warning';

	/** Check failed and may block payment processing. */
	case ERROR = 'error';

	/** Informational state. */
	case INFO = 'info';
}
