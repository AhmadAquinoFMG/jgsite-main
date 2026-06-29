<?php

/**
 * Funnel configuration — JG Wentworth Debt Relief landing page (PHP clone).
 *
 * Everything content-related lives here so the markup in index.php / includes
 * stays clean. Swap branding, steps, options or copy without touching layout.
 */

return [
    // ---- Asset cache-busting -------------------------------------------
    // Bump this whenever CSS/JS changes so browsers/CDNs fetch fresh files.
    // Appended to asset URLs as ?v=… in index.php / thank-you.php.
    'asset_version' => '11',

    // ---- Analytics: Umami -----------------------------------------------
    // Privacy-friendly analytics. Used to measure funnel drop-off (which step
    // visitors leave from) via per-step events fired in assets/js/funnel.js.
    // Leave 'website_id' empty to disable the script entirely.
    //   • Umami Cloud:  src => 'https://cloud.umami.is/script.js'
    //   • Self-hosted:  src => 'https://<your-host>/script.js'
    'umami' => [
        'src'        => 'https://cloud.umami.is/script.js',
        'website_id' => '40f1f6d9-80c1-49cf-b6ef-0280ac052f83',
    ],

    // ---- Branding -------------------------------------------------------
    'brand' => [
        'name'         => 'JG Wentworth',
        'logo_header'  => 'assets/img/JG-Wentworth-logo-header.svg',
        'logo_footer'  => 'assets/img/footer-logo.png',
        'phone'        => '1-888-510-3795',
        'dept'         => 'Debt Solutions',
        'address'      => ['1200 Morris Drive', 'Chesterbrook, PA 19087'],
        'copyright'    => 'Copyright © 2026 The JG Wentworth Company. All rights reserved',
    ],

    // ====================================================================
    //  Main site chrome — for NON-funnel pages (includes/site-header.php
    //  and includes/site-footer.php). The funnel keeps its own stripped
    //  header/footer in includes/header.php + includes/footer.php.
    // ====================================================================

    // ---- Primary navigation (site header) ------------------------------
    // 'dropdown' => true renders a chevron for menus with sub-items.
    'nav_links' => [
        ['label' => 'Structured Settlements', 'href' => '#'],
        ['label' => 'Debt Relief',            'href' => '#'],
        ['label' => 'Home Equity Cashout',    'href' => '#', 'dropdown' => true],
        ['label' => 'Other Products',         'href' => '#', 'dropdown' => true],
        ['label' => 'About Us',               'href' => '#', 'dropdown' => true],
        ['label' => 'Resources',              'href' => '#', 'dropdown' => true],
    ],
    'login_url' => '#',

    // ---- Site footer: "Company" column ---------------------------------
    'footer_company' => [
        'Contact Us'        => '#',
        'Affiliate Program' => '#',
        'Careers'           => '#',
        'Newsroom'          => '#',
        'Shop'              => '#',
    ],

    // ---- Site footer: "Legal Information" column ------------------------
    'footer_legal' => [
        'Terms of Use'                                    => 'https://www.jgwentworth.com/terms-use',
        'Legal Disclosures'                               => '#',
        'Your Privacy Rights'                             => '#',
        'Notice at Collection'                            => 'https://www.jgwentworth.com/notice-at-collection',
        'Privacy Policy'                                  => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Licenses'                                        => 'https://www.jgwentworth.com/licenses',
        'Asset-Backed Securitization'                     => '#',
        'Association for Consumer Debt Relief Disclosure' => '#',
        'Do Not Sell My Personal Information'             => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Debt Resolution Loan Disclosures'                => 'https://www.jgwentworth.com/debt-resolution-loan-disclosures',
    ],

    // ---- Site footer: social links (icons rendered in the include) -----
    // Keys must match the icon set in includes/site-footer.php.
    'social_links' => [
        'facebook'  => '#',
        'tiktok'    => '#',
        'youtube'   => '#',
        'instagram' => '#',
        'x'         => '#',
        'pinterest' => '#',
    ],

    // ---- Post-submit "pre-qualified" page (thank-you.php) ---------------
    'prequal' => [
        'cta_phone'    => '',  // number the assigned specialist line rings
        'hold_minutes' => 5,                 // countdown the file is "held" for
    ],

    // ---- Trust badges (footer) -----------------------------------------
    'badges' => [
        ['src' => 'assets/img/trustpilot-1-300x240.png',         'alt' => 'Trustpilot 4.8/5 Stars'],
        ['src' => 'assets/img/bbb-1-e1741985838229-300x240.png', 'alt' => 'BBB Accredited Business — A+ Rating'],
        ['src' => 'assets/img/google-300x240.png',               'alt' => 'Google 4.5/5 Stars'],
    ],

    // ---- Debt amount options (step 1) ----------------------------------
    'debt_options' => [
        'Less than $10,000',
        '$10,000 - $24,999',
        '$25,000 - $49,999',
        '$50,000 - $100,000',
        'More than $100,000',
    ],

    // ---- Employment status (step 2) ------------------------------------
    'employment_options' => [
        'Full-time',
        'Self-employed',
        'Unemployed',
        'Other',
    ],

    // ---- Annual income before taxes (step 3) ---------------------------
    'income_options' => [
        'Under $30,000',
        'Between $30,000 and $100,000',
        'Over $100,000',
    ],

    // ---- US states (address step) --------------------------------------
    'states' => [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DC' => 'District of Columbia',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'PR' => 'Puerto Rico',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ],

    // ---- Consent / legal copy ------------------------------------------
    'consent' => [
        'contact' => 'By clicking “Submit” you consent to allowing JG Wentworth to contact you as described below.',
        'credit'  => 'By clicking “Submit” you consent to allow JG Wentworth to access your credit report as described below. This will not impact your credit score.',
        'tcpa'    => 'By submitting this form, I am providing JG Wentworth with express written consent to contact me regarding product offerings by SMS/text messages or by using an auto dialer (or automated means) at the phone number(s) provided and such consent is not a condition of a purchase. I further consent to initial contact outside of permissible state and federal call times if made within approximately one hour of submission. Message and data rates may apply. You can opt-out of this service at any time by replying to our last message with STOP. For assistance, please call any number listed on this website. I also consent and agree to JG Wentworth’s Privacy Policy and Terms of Use.',
        'fcra'    => 'Pursuant to the Fair Credit Reporting Act (FCRA), I hereby provide my written instructions and authorization for J.G. Wentworth to obtain a consumer report on me. I understand that J.G. Wentworth has a permissible purpose under the FCRA to request and review my consumer report in connection with a financial transaction. I acknowledge that this request for a consumer report is being made in accordance with my explicit consent and instructions as required under the FCRA.',
    ],

    // ---- Footer legal links (left column, vertical) --------------------
    'legal_links' => [
        'Terms of Use'                        => 'https://www.jgwentworth.com/terms-use',
        'Privacy policy'                      => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Licenses'                            => 'https://www.jgwentworth.com/licenses',
        'Notice at Collection'                => 'https://www.jgwentworth.com/notice-at-collection',
        'Do Not Sell My Personal Information' => 'https://www.jgwentworth.com/jg-wentworth-company-r-consumer-privacy-notice',
        'Loan Disclosures'                    => 'https://www.jgwentworth.com/wp-content/uploads/2024/04/Loan-Disclosures-New.pdf',
        'Debt Resolution Loan Disclosures'    => 'https://www.jgwentworth.com/debt-resolution-loan-disclosures',
    ],

    // ---- Long disclosure block (right column, paragraphs) --------------
    // Static, self-authored legal copy; the two link-bearing paragraphs are
    // trusted HTML rendered verbatim in the footer.
    'disclosures' => [
        '*Program length varies depending on individual situation. Programs are between 24 and 60 months in length. Average graduated clients realize approximate savings of 46% before our program fee and 21% after program fee. This is a Debt resolution program provided by JGW Debt Settlement, LLC (“JGW” or “Us”). JGW offers this program in the following states: AL, AK, AZ, AR, CA, CO, FL, ID, IN, IA, KY, LA, MD, MA, MI, MS, MO, NE, NM, NV, NY, NC, OK, PA, PR, SD, TN, TX, UT, VA, DC. If a consumer residing in any other state contacts Us we may connect them with a law firm that provides debt resolution services in their state. JGW is licensed/registered to provide debt resolution services in states where licensing/registration is required.',

        'Debt resolution program results will vary by individual situation. As such, debt resolution services are not appropriate for everyone. Not all debts are eligible for enrollment. Not all individuals who enroll complete our program for various reasons, including their ability to save sufficient funds. Savings resulting from successful negotiations may result in tax consequences, please consult with a tax professional regarding these consequences. The use of the debt settlement services and the failure to make payments to creditors: (1) Will likely adversely affect your creditworthiness (credit rating/credit score) and make it harder to obtain credit; (2) May result in your being subject to collections or being sued by creditors or debt collectors; and (3) May increase the amount of money you owe due to the accrual of fees and interest by creditors or debt collectors. Failure to pay your monthly bills in a timely manner will result in increased balances and will harm your credit rating. Not all creditors will agree to reduce principal balance, and they may pursue collection, including lawsuits. JGW’s fees are calculated based on a percentage of the debt enrolled in the program. Read and understand the program agreement prior to enrollment.',

        'JG Wentworth does not pay or assume any debts or provide legal, financial, tax advice, or credit repair services. You should consult with independent professionals for such advice or services. Please consult with a bankruptcy attorney for information on bankruptcy.',

        'Client Grievance Procedure: If you are unable to resolve an issue with your Debt Specialist or Client Services Representative, please request to speak with a manager. If you cannot reach a resolution with a manager, please escalate communication via email at <a href="mailto:complaint@jgwentworth.com">complaint@jgwentworth.com</a> or direct mail to the business address listed on our contact page.',

        'List of Licenses can be accessed here: <a href="https://www.jgwentworth.com/licenses" target="_blank">Licenses &ndash; JG Wentworth</a>',
    ],
];
