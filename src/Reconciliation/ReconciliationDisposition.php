<?php
/**
 * Reconciliation result dispositions.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Reconciliation;

/**
 * Normalized result of one Bachs/local reconciliation attempt.
 */
enum ReconciliationDisposition: string {
	/** The host application already contained the verified payment. */
	case ALREADY_APPLIED = 'already_applied';

	/** Reconciliation successfully repaired local application state. */
	case RECOVERED = 'recovered';

	/** Provider state is not yet authoritatively successful. */
	case NO_ACTION = 'no_action';

	/** A temporary provider or local failure can be retried later. */
	case RETRYABLE_FAILURE = 'retryable_failure';

	/** Exact evidence or local state requires human review. */
	case REQUIRES_REVIEW = 'requires_review';

	/** The requested intent does not exist. */
	case NOT_FOUND = 'not_found';
}
