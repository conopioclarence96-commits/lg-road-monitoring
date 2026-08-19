/**
 * Landing page core scripts (index.php)
 * - Smooth scrolling for same-page anchor links
 * - Navbar background on scroll
 * - Scroll-reveal animations via IntersectionObserver
 * - Before & After project comparison slider
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

    document.querySelectorAll('.update-card, .stat-card, .service-card, .before-after-card').forEach(function (card) {
        card.classList.add('scroll-animate');
        observer.observe(card);
    });

    // Before & After Comparison Slider
    document.querySelectorAll('[data-slider]').forEach(function (slider) {
        var imgBefore = slider.querySelector('.img-before');
        var handle = slider.querySelector('[data-handle]');
        var isDragging = false;

        function updateSlider(x) {
            var rect = slider.getBoundingClientRect();
            var pos = ((x - rect.left) / rect.width) * 100;
            pos = Math.max(0, Math.min(100, pos));

            imgBefore.style.clipPath = 'inset(0 ' + (100 - pos) + '% 0 0)';
            handle.style.left = pos + '%';
        }

        // Mouse events
        slider.addEventListener('mousedown', function (e) {
            isDragging = true;
            updateSlider(e.clientX);
            slider.style.cursor = 'grabbing';
        });

        document.addEventListener('mousemove', function (e) {
            if (!isDragging) return;
            e.preventDefault();
            updateSlider(e.clientX);
        });

        document.addEventListener('mouseup', function () {
            if (isDragging) {
                isDragging = false;
                slider.style.cursor = 'ew-resize';
            }
        });

        // Touch events
        slider.addEventListener('touchstart', function (e) {
            isDragging = true;
            updateSlider(e.touches[0].clientX);
        }, { passive: true });

        slider.addEventListener('touchmove', function (e) {
            if (!isDragging) return;
            e.preventDefault();
            updateSlider(e.touches[0].clientX);
        }, { passive: false });

        slider.addEventListener('touchend', function () {
            isDragging = false;
        });

        // Animate handle on load
        setTimeout(function () {
            var start = 0;
            var target = 50;
            var duration = 800;
            var startTime = performance.now();

            function animate(time) {
                var elapsed = time - startTime;
                var progress = Math.min(elapsed / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                var current = start + (target - start) * eased;

                imgBefore.style.clipPath = 'inset(0 ' + (100 - current) + '% 0 0)';
                handle.style.left = current + '%';

                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            }
            requestAnimationFrame(animate);
        }, 300);
    });
})();
