<?php
declare(strict_types=1);

/**
 * Does $name contain $token as a case-insensitive substring?
 *
 * Same convention as buyers.name (see includes/buyers.php): the configured token
 * is short ('DocuPop') and the name being tested is whatever LeadProsper
 * returned ('DocuPop Student Loan Services'), so the token is the needle. An
 * empty token never matches — a blank ROUTING_STUDENT_BUYER has to switch the
 * student band OFF, not match every buyer on the campaign.
 */
function lead_routing_buyer_matches(?string $name, string $token): bool
{
    $name  = trim((string) $name);
    $token = trim($token);

    if ($name === '' || $token === '') {
        return false;
    }

    return stripos($name, $token) !== false;
}

/**
 * Decide the post-submit experience from VERIFIED debt only — Equifax's
 * unsecured total, or its separate student-loan total. Self-assessed debt never
 * upgrades a visitor into a qualified buyer band.
 *
 * The two figures are independent by construction: equifax_trade_is_student()
 * excludes student and education loans from the unsecured total and routes them
 * to student_debt instead, so the two are complementary and never double-count.
 * That independence is the whole reason this function needs both — a consumer
 * with $40k in student loans and $2k of credit-card debt is worth a great deal
 * to a student-loan buyer and almost nothing to a debt-settlement one, and
 * judging them on the $2k alone sent them to the decline offerwall.
 *
 * Band order is deliberate: the student band is tested FIRST, because it is the
 * only one that can be confirmed by an actual sale. Reaching it requires
 * LeadProsper to have named the student buyer as having accepted the lead — not
 * merely that the consumer carries student debt — so the thank-you page names a
 * firm that really did buy the file. The unsecured bands below it are ours to
 * assign and carry no such confirmation.
 *
 * @param int|null    $verifiedDebt  Equifax unsecured total; null when unread.
 * @param bool        $isBot         Bot-suspected submission.
 * @param array       $cfg           ['lead_routing'] config.
 * @param int|null    $studentDebt   Equifax student-loan total; null when unread.
 * @param string|null $acceptedBuyer Buyer LeadProsper reported, or null.
 *
 * @return array{tier:string,buyer:string,decline_offer:bool,has_credit_read:bool,
 *               total_debt:?int,student_debt:?int,student_offer:bool}
 *         student_offer asks the offerwall to surface the student-loan card:
 *         true whenever the consumer carries ANY student debt and did NOT land
 *         in the student band. A lead already sold to the student buyer must
 *         never be offered that same buyer again on the offerwall.
 */
function lead_routing_decision(
    ?int $verifiedDebt,
    bool $isBot,
    array $cfg,
    ?int $studentDebt = null,
    ?string $acceptedBuyer = null
): array {
    $qualifyMin = max(0, (int) ($cfg['qualify_min'] ?? 10000));
    $houseBuyer = trim((string) ($cfg['house_buyer'] ?? 'JG Wentworth'));
    $declineBuyer = trim((string) ($cfg['decline_buyer'] ?? 'United Debt - Under $10k'));
    $studentMin = max(0, (int) ($cfg['student_qualify_min'] ?? 10000));
    $studentBuyer = trim((string) ($cfg['student_buyer'] ?? 'DocuPop'));
    $hasCreditRead = $verifiedDebt !== null;
    $debt = $hasCreditRead ? max(0, $verifiedDebt) : null;
    $student = $studentDebt !== null ? max(0, $studentDebt) : null;

    if ($isBot) {
        return [
            'tier' => 'bot',
            'buyer' => $houseBuyer,
            'decline_offer' => false,
            'has_credit_read' => $hasCreditRead,
            'total_debt' => $debt,
            'student_debt' => $student,
            // A suspected bot gets no offerwall and no student card. Nothing
            // here was necessarily a real consumer, so nothing is worth selling.
            'student_offer' => false,
        ];
    }

    /* Student band. All three conditions, not any: a student-loan balance we
       actually read, at or above the threshold, AND LeadProsper naming the
       student buyer as the one that took the lead. Dropping the buyer test
       would brand the page for a firm that never bought the consumer; dropping
       the threshold would hand a $900 balance to a buyer who does not want it. */
    if ($student !== null
        && $student >= $studentMin
        && lead_routing_buyer_matches($acceptedBuyer, $studentBuyer)
    ) {
        return [
            'tier' => 'student',
            // The buyer as LeadProsper spelled it, not the config token, so
            // buyer_find() matches the registry row on the real name.
            'buyer' => trim((string) $acceptedBuyer),
            // No offerwall: this lead was SOLD. Sending it a wall of competing
            // offers undercuts the buyer who just paid for it.
            'decline_offer' => false,
            'has_credit_read' => $hasCreditRead,
            'total_debt' => $debt,
            'student_debt' => $student,
            'student_offer' => false,
        ];
    }

    if ($debt !== null && $debt >= $qualifyMin) {
        $tier = 'qualified';
        $buyer = $houseBuyer;
        $decline = false;
    } else {
        // InCharge is temporarily disabled. Every verified amount below the
        // qualification threshold, plus every no-read/failure outcome, uses
        // the dedicated United under-$10k buyer branding and DID.
        $tier = 'decline';
        $buyer = $declineBuyer;
        $decline = true;
    }

    return [
        'tier' => $tier,
        'buyer' => $buyer,
        'decline_offer' => $decline,
        'has_credit_read' => $hasCreditRead,
        'total_debt' => $debt,
        'student_debt' => $student,
        /* Any balance at all, deliberately — not $studentMin. The threshold
           above gates a SALE to a buyer who has a minimum; this gates a card on
           an offerwall, which costs nothing to show and helps anyone carrying a
           loan. A consumer below the sale threshold is exactly the one with no
           other offer here worth taking. */
        'student_offer' => $student !== null && $student > 0,
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
/**
 * Attribution keys allowed onto the offerwall URL and usable as {token}s in CTA links.
 *
 * student_debt is deliberately NOT in this list even though decline_offerwall_url()
 * does put it on the URL. Every key here is also a {token} that partner CTA links
 * can interpolate, so adding it would ship the consumer's student-loan balance to
 * third-party destinations on click. The offerwall reads it for its own copy; no
 * partner ever receives it. Keep the two concerns separate.
 */
function offerwall_attribution_keys(): array
{
    return [
        'affid', 'oid', 'source_id', 'ef_transaction_id',
        'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'sub6',
        'lp_subid1', 'lp_subid2', 'lp_subid3', 'lp_subid4', 'lp_subid5', 'lp_subid6',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_creative', 'utm_placement', 'utm_adgroup', 'utm_matchtype',
        'gclid', 'gbraid', 'fbclid', 'ttclid', 'ms_placement', 'ms_publisher',
    ];
}

function decline_offerwall_url(array $lead, array $cfg): string
{
    $base = trim((string) ($cfg['offerwall_base'] ?? 'offerwall.php'));
    if ($base === '') {
        $base = 'offerwall.php';
    }

    $params = [];
    foreach (offerwall_attribution_keys() as $key) {
        $value = trim((string) ($lead[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    /* The one lead-derived value on this URL, and the single exception to the
       no-PII rule above. The offerwall quotes the balance back in the student-loan
       card's copy, and the figure has to survive the thank-you hop to get there:
       thank-you.php rebuilds this URL from its own query string, so a value that
       lives only in the session is gone the moment the visitor opens the wall in
       the separate tab it is designed to open in.
       Paired with $_SESSION['student_debt'], which submit.php also sets — the
       session is the trustworthy copy, this one is a display hint. offerwall.php
       prefers the session and only falls back here, so a hand-edited URL changes
       the number in the copy and nothing else: the card carries no eligibility,
       no pricing and no routing. Never read it for anything that matters.
       Omitted entirely at 0 or absent, so the card simply does not render. */
    $studentDebt = (int) ($lead['student_debt'] ?? 0);
    if ($studentDebt > 0) {
        $params['student_debt'] = $studentDebt;
    }

    if ($params === []) {
        return $base;
    }
    return $base . (str_contains($base, '?') ? '&' : '?')
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
