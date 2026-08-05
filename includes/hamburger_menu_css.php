<?php
// Shared hamburger menu styles for the public landing pages.
// Emits its own <style> block. Works on every landing page regardless of
// the CSS variables that page defines (fallbacks keep it consistent).
?>
<style>
    /* Hamburger Menu */
    .hamburger-btn {
        position: fixed;
        top: 16px;
        right: 18px;
        z-index: 10002;
        width: 46px;
        height: 46px;
        border: 2px solid rgba(255,255,255,0.6);
        border-radius: 12px;
        background: rgba(255,255,255,0.12);
        backdrop-filter: blur(4px);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 5px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .hamburger-btn:hover {
        background: rgba(255,255,255,0.25);
        transform: scale(1.05);
    }

    .hamburger-btn .bar {
        display: block;
        width: 22px;
        height: 2.5px;
        background: #fff;
        border-radius: 3px;
        transition: all 0.3s ease;
    }

    .hamburger-btn.active .bar:nth-child(1) {
        transform: translateY(7.5px) rotate(45deg);
    }

    .hamburger-btn.active .bar:nth-child(2) {
        opacity: 0;
    }

    .hamburger-btn.active .bar:nth-child(3) {
        transform: translateY(-7.5px) rotate(-45deg);
    }

    .menu-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.35s ease, visibility 0.35s ease;
        z-index: 10000;
    }

    .menu-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .side-menu {
        position: fixed;
        top: 0;
        right: -320px;
        width: 300px;
        max-width: 85vw;
        height: 100vh;
        background: linear-gradient(135deg, var(--primary-color, #1e3c72) 0%, var(--secondary-color, #2a5298) 100%);
        box-shadow: -5px 0 25px rgba(0,0,0,0.35);
        z-index: 10001;
        transition: right 0.35s ease;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
    }

    .side-menu.open {
        right: 0;
    }

    .side-menu-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 20px 24px;
        border-bottom: 1px solid rgba(255,255,255,0.15);
    }

    .side-menu-header img {
        height: 40px;
        width: auto;
        border-radius: 6px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    }

    .side-menu-header h4 {
        color: #fff;
        font-size: 1rem;
        font-weight: 600;
        margin: 0;
        line-height: 1.3;
    }

    .side-menu-nav {
        flex: 1;
        padding: 16px 0;
        list-style: none;
        margin: 0;
    }

    .side-menu-nav li a {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px 28px;
        color: #fff;
        text-decoration: none;
        font-weight: 500;
        font-size: 1rem;
        transition: all 0.25s ease;
        border-left: 3px solid transparent;
    }

    .side-menu-nav li a i {
        width: 20px;
        text-align: center;
        color: var(--accent-color, #4CAF50);
    }

    .side-menu-nav li a:hover {
        background: rgba(255,255,255,0.1);
        border-left-color: var(--accent-color, #4CAF50);
        padding-left: 34px;
    }

    .side-menu-footer {
        padding: 20px 24px;
        border-top: 1px solid rgba(255,255,255,0.15);
    }

    .side-menu-footer .btn-login {
        width: 100%;
        text-align: center;
        text-decoration: none;
    }
</style>
