<?php

/**
 * Everflow offer routing.
 *
 * One place that answers "which Everflow offer does this visitor belong to?",
 * so the click (index.php -> assets/js/tracking/everflow.js) and the conversion
 * (thank-you.php) can never disagree and fire against different offers.
 *
 * The answer comes from affid alone — see config.php ['everflow'] for why, and
 * for the affid -> offer table itself.
 */

/**
 * Resolve the Everflow offer id for an affid.
 *
 * @param  string|null $affid  Raw ?affid= value (or the one stashed at submit).
 * @param  array       $cfg    config.php ['everflow'] block.
 * @return string|null         Offer id, or null when the visitor must not be
 *                             sent to Everflow at all (no/blank affid).
 */
function everflow_offer_for_affid(?string $affid, array $cfg): ?string
{
    $affid = trim((string) $affid);
    if ($affid === '') {
        return null;    // unattributed traffic — Everflow stays untouched
    }

    $firstParty = $cfg['first_party_affids'] ?? [];

    return in_array($affid, $firstParty, true)
        ? (string) ($cfg['offer_first_party'] ?? '')
        : (string) ($cfg['offer_third_party'] ?? '');
}
