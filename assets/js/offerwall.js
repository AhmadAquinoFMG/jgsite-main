(function () {
    'use strict';

    function eventProps(card) {
        return {
            offer_id: card.getAttribute('data-offer-id') || '',
            offer_name: card.getAttribute('data-offer-name') || '',
            offer_position: Number(card.getAttribute('data-offer-position') || 0),
            is_sponsored: card.getAttribute('data-offer-sponsored') === 'true'
        };
    }

    function track(name, props) {
        if (typeof window.jgTrack === 'function') window.jgTrack(name, props || {});
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.offer-card'));
        track('event_view_offerwall', { offer_count: cards.length });

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    track('event_offer_impression', eventProps(entry.target));
                    observer.unobserve(entry.target);
                });
            }, { threshold: 0.5 });
            cards.forEach(function (card) { observer.observe(card); });
        }

        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('[data-offer-cta]');
            if (!link) return;
            var card = link.closest('.offer-card');
            if (!card) return;

            var props = eventProps(card);
            props.cta_text = link.getAttribute('data-cta-text') || '';
            track('event_offer_click', props);
        });
    });
}());
