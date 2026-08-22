<?php
// Shared mobile navbar styles for the public pages.
// On small screens the navbar quick-links (Services / Programs / Search)
// are restored as a compact icon row under the brand instead of being
// hidden behind the hamburger menu, and the hero gets extra top padding
// so the taller mobile navbar never covers it.
// Place this include AFTER the <nav> block (after navbar_quicklinks.php).
?>
<style>
    /* Compact brand on small screens */
    @media (max-width: 575.98px) {
        .qc-navbar { padding: 0.4rem 0; }
        .qc-navbar .container-fluid { padding-right: 64px; padding-left: 14px; }
        .qc-brand { gap: 9px; }
        .qc-brand img { height: 38px; }
        .qc-brand-text strong { font-size: 0.9rem; line-height: 1.15; }
        .qc-brand-text small { font-size: 0.6rem; letter-spacing: 0.4px; }
    }

    @media (max-width: 359.98px) {
        .qc-brand img { height: 34px; }
        .qc-brand-text strong { font-size: 0.8rem; }
        .qc-brand-text small { font-size: 0.55rem; }
    }

    /* Quick-links row + hero clearance below 992px */
    @media (max-width: 991.98px) {
        section[id] { scroll-margin-top: 130px; }
        .hero-bar { padding-top: 130px; }

        .qc-navbar .container-fluid {
            flex-wrap: wrap;
            padding-left: 14px;
        }

        /* Bring the quick-links back out of display:none and stack them
           as a full-width row under the brand. */
        .qc-navbar .qc-nav-center {
            position: static;
            top: auto;
            right: auto;
            transform: none;
            display: flex;
            flex-wrap: wrap;
            width: 100%;
            justify-content: center;
            gap: 8px;
            margin-top: 6px;
            padding-bottom: 4px;
        }

        .qc-navbar .qc-services-dropdown .dropdown-toggle,
        .qc-navbar .qc-programs-dropdown .dropdown-toggle {
            font-size: 0.78rem;
            padding: 6px 10px;
            gap: 6px;
            border-radius: 7px;
        }

        .qc-navbar .qc-nav-search {
            flex: 1;
            min-width: 0;
        }

        .qc-navbar .qc-search-input {
            width: 100%;
            min-width: 140px;
            padding: 7px 34px 7px 10px;
            font-size: 0.8rem;
        }

        .qc-navbar .qc-search-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            padding: 0;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 8px;
        }

        /* Keep the live-search results inside the phone screen */
        .qc-navbar .qc-search-results {
            min-width: 0;
            width: calc(100vw - 24px);
            max-width: 420px;
        }

        /* Never let the dropdown menus overflow the phone screen.
           Anchor the opened Services/Programs menus to the fixed navbar
           itself (full-width panel below the whole quick-links row).
           !important beats the inline top/left/transform that Popper.js
           writes on .dropdown-menu.show, which otherwise positions the
           menu past the right edge of small screens and on top of the
           buttons/search row (items look overlapping/clipped). */
        .qc-services-dropdown .dropdown-menu,
        .qc-programs-dropdown .dropdown-menu {
            min-width: 0;
            max-width: calc(100vw - 20px);
        }
        /* Un-position the dropdown shells so the opened menus resolve their
           coordinates against the fixed .qc-navbar instead of the small
           half-row .dropdown wrappers. */
        .qc-navbar .qc-services-dropdown,
        .qc-navbar .qc-programs-dropdown {
            position: static;
        }
        .qc-navbar .qc-services-dropdown .dropdown-menu.show,
        .qc-navbar .qc-programs-dropdown .dropdown-menu.show {
            top: 100% !important;
            bottom: auto !important;
            left: 14px !important;
            right: 14px !important;
            transform: none !important;
            margin: 0;
        }
    }

    /* Small phones: Services & Programs as two side-by-side buttons,
       Search on its own full-width row. */
    @media (max-width: 575.98px) {
        section[id] { scroll-margin-top: 160px; }
        .hero-bar { padding-top: 150px; }

        .qc-navbar .qc-nav-center { justify-content: stretch; }

        .qc-navbar .qc-services-dropdown,
        .qc-navbar .qc-programs-dropdown { flex: 1; }

        .qc-navbar .qc-services-dropdown .dropdown-toggle,
        .qc-navbar .qc-programs-dropdown .dropdown-toggle {
            width: 100%;
            justify-content: center;
            font-size: 0.85rem;
        }

        .qc-navbar .qc-nav-search {
            flex-basis: 100%;
            margin-top: 4px;
        }
    }

    /* Hamburger alignment: sit vertically centered beside the Programs
       button (the quick-links row) instead of covering the brand text.
       Values measured against the rendered layout at each breakpoint. */
    @media (max-width: 991.98px) {
        .hamburger-btn { top: 58px !important; }
    }
    @media (max-width: 575.98px) {
        .hamburger-btn {
            top: 49px !important;
            width: 40px !important;
            height: 40px !important;
        }
    }
    @media (max-width: 359.98px) {
        .hamburger-btn { top: 89px !important; }
    }
</style>
