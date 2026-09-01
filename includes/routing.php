<?php
declare(strict_types=1);

/**
 * Decide the post-submit experience from verified unsecured debt only.
 * Self-assessed debt never upgrades a visitor into a qualified buyer band.
 *
 * @return array{tier:string,buyer:string,decline_offer:bool,has_credit_read:bool,total_debt:?int}
 */
function lead_routing_decision(?int $verifiedDebt, bool $isBot, array $cfg): array
{
    $qualifyMin = max(0, (int) ($cfg['qualify_min'] ?? 10000));
    $inchargeMin = max(0, (int) ($cfg['incharge_min'] ?? 5000));
    $inchargeMax = max($inchargeMin, (int) ($cfg['incharge_max'] ?? 9999));
    $houseBuyer = trim((string) ($cfg['house_buyer'] ?? 'JG Wentworth'));
    $inchargeBuyer = trim((string) ($cfg['incharge_buyer'] ?? 'InCharge'));
    $hasCreditRead = $verifiedDebt !== null;
    $debt = $hasCreditRead ? max(0, $verifiedDebt) : null;

    if ($isBot) {
        return [
            'tier' => 'bot',
            'buyer' => $houseBuyer,
            'decline_offer' => false,
            'has_credit_read' => $hasCreditRead,
            'total_debt' => $debt,
        ];
    }

    if ($debt !== null && $debt >= $qualifyMin) {
        $tier = 'qualified';
        $buyer = $houseBuyer;
        $decline = false;
    } elseif ($debt !== null && $debt >= $inchargeMin && $debt <= $inchargeMax) {
        $tier = 'incharge';
        $buyer = $inchargeBuyer;
        // This band stays on its InCharge-branded thank-you page while the
        // separate decline-options tab is offered as requested.
        $decline = true;
    } else {
        // Includes verified $0-$4,999 and every no-read/failure outcome.
        $tier = 'decline';
        $buyer = $houseBuyer;
        $decline = true;
    }

    return [
        'tier' => $tier,
        'buyer' => $buyer,
        'decline_offer' => $decline,
        'has_credit_read' => $hasCreditRead,
        'total_debt' => $debt,
    ];
}

/** Recover the verified debt used for routing from a stored lead replay. */
function lead_stored_verified_debt(array $lead): ?int
{
    if (trim((string) ($lead['total_debt_source'] ?? '')) === '') {
        return null;
    }

    foreach (['jgw_total_debt', 'total_debt'] as $field) {
        if (array_key_exists($field, $lead) && $lead[$field] !== null && $lead[$field] !== '') {
            return max(0, (int) round((float) $lead[$field]));
        }
    }
    return null;
}

/**
 * Build the local offerwall URL with attribution only. No identity/contact data
 * is allowed into this URL, and the offerwall never appends these values to a
 * partner destination.
 */
function decline_offerwall_url(array $lead, array $cfg): string
{
    $base = trim((string) ($cfg['offerwall_base'] ?? 'offerwall.php'));
    if ($base === '') {
        $base = 'offerwall.php';
    }

    $allowed = [
        'affid', 'oid', 'source_id',
        'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'sub6',
        'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5', 'lp_subid6',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
        'gclid', 'gbraid', 'fbclid', 'ttclid', 'ms_placement', 'ms_publisher',
    ];
    $params = [];
    foreach ($allowed as $key) {
        $value = trim((string) ($lead[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    if ($params === []) {
        return $base;
    }
    return $base . (str_contains($base, '?') ? '&' : '?')
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
