<?php
declare(strict_types=1);

/**
 * Decline offers mirrored from https://topdebtoptions.com/offerwall.php.
 *
 * These destinations are intentionally fixed to the links on the referenced
 * offerwall. No lead fields, attribution parameters, or other PII are appended
 * to a partner URL.
 */
return [
    'campaigns' => [
        [
            'id' => 'lending_for_bad_credit',
            'name' => 'Lending For Bad Credit',
            'logo' => 'assets/img/offers/lending-for-bad-credit.webp',
            'description' => 'Personal loans from $100 to $40,000 for all credit types.',
            'benefits' => ['Bad credit? You may still qualify', 'Quick online request', 'Funds as soon as the next business day'],
            'cta_text' => 'See If I Qualify',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/5Q9RM9/',
        ],
        [
            'id' => 'lendingtree',
            'name' => 'LendingTree',
            'logo' => 'assets/img/offers/lendingtree.png',
            'description' => 'Shop and compare personal loan options that work best for you.',
            'benefits' => ['Check rates without impacting your credit score', 'See personalized offers in minutes', 'Loans from $1,000 to $50,000'],
            'cta_text' => 'Get Offers',
            'cta_link' => 'https://www.lendingtree.com/lp/personal-loans/dropdown.html?icode=52624&SpId=pm-pl&800num=hide&siteid=&MTAID=6B718&ckmoid=134&ckmreqid=18437793&ckmat=1&sessionid=72affeb0-ab2c-48e7-8e48-0293aa850764&mta=1',
        ],
        [
            'id' => 'experian',
            'name' => 'Experian',
            'logo' => 'assets/img/offers/experian.webp',
            'description' => 'Get your FICO Score for free and start improving your credit.',
            'benefits' => ['See what impacts your credit score', 'Free FICO Score and monitoring', 'Alerts included at no cost'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/27DQ3QC/',
        ],
        [
            'id' => 'upstart',
            'name' => 'Upstart',
            'logo' => 'assets/img/offers/upstart.webp',
            'description' => 'Most borrowers are approved instantly.',
            'benefits' => ['Verify your details in minutes', 'Next-day funding', "Won't affect your credit score"],
            'cta_text' => 'Check My Rate',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/DMBKXN/',
        ],
        [
            'id' => 'lexington_law',
            'name' => 'Lexington Law',
            'logo' => 'assets/img/offers/lexington-law.webp',
            'description' => 'Work toward repairing your credit with professional help.',
            'benefits' => ['Challenge questionable report items', 'Personalized credit repair plan', 'Free credit assessment'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/2498FD4/',
        ],
        [
            'id' => 'usa_grants',
            'name' => 'USA Grants',
            'logo' => 'assets/img/offers/usa-grants.webp',
            'description' => 'Explore grant money you may be eligible to claim.',
            'benefits' => ['See if you qualify for grant money', 'Free to check eligibility', 'Billions in grants available'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/7XDN21/',
            'sponsored' => true,
        ],
        [
            'id' => 'usa_assistance_guide',
            'name' => 'USA Assistance Guide',
            'logo' => 'assets/img/offers/usa-assistance-guide.webp',
            'description' => 'Find cash assistance programs available in your area.',
            'benefits' => ['Financial assistance for everyday expenses', 'Free to check what you qualify for', 'Funds may be available quickly'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/7NG8BZ/',
            'sponsored' => true,
        ],
    ],
];
