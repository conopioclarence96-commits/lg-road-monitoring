/**
 * Landing page core scripts (index.php)
 * - Smooth scrolling for same-page anchor links
 * - Navbar background on scroll
 * - Scroll-reveal animations via IntersectionObserver
 */
(function () {
    'use strict';

    // Smooth scrolling for navigation links
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Navbar background on scroll
    window.addEventListener('scroll', function () {
        var navbar = document.querySelector('.navbar');
        if (navbar) {
            if (window.scrollY > 100) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        }
    });

    // Animate elements on scroll - using class toggle to prevent flash on refresh
    var observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
            }
        });
    }, observerOptions);

    document.querySelectorAll('.update-card, .stat-card, .service-card').forEach(function (card) {
        card.classList.add('scroll-animate');
        observer.observe(card);
    });

})();
