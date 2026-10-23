(function () {
    'use strict';

    /* ==========================================================
       1. ACCESSIBILITY & PREFERS-REDUCED-MOTION CHECK
    ========================================================== */
    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ==========================================================
       2. SMOOTH SCROLLING (LENIS)
    ========================================================== */
    let lenis = null;

    if (!prefersReducedMotion && typeof Lenis !== 'undefined') {
        lenis = new Lenis({
            lerp: 0.1,
            smoothWheel: true,
            syncTouch: false,
            wheelMultiplier: 1.0,
            touchMultiplier: 1.0,
            autoRaf: false
        });

        window.lenis = lenis;
        window.lenisInstance = lenis;
    }

    /* ==========================================================
       3. GSAP & SCROLLTRIGGER SYNCHRONIZATION
    ========================================================== */
    const hasGSAP = typeof gsap !== 'undefined';
    const hasScrollTrigger = typeof ScrollTrigger !== 'undefined';

    if (hasGSAP && hasScrollTrigger) {
        gsap.registerPlugin(ScrollTrigger);

        if (lenis) {
            // Synchronize Lenis scroll updates with ScrollTrigger
            lenis.on('scroll', ScrollTrigger.update);

            // Synchronize GSAP ticker with Lenis frame loop
            gsap.ticker.add(function (time) {
                lenis.raf(time * 1000);
            });
            gsap.ticker.lagSmoothing(0);
        }
    } else if (lenis) {
        function raf(time) {
            lenis.raf(time);
            requestAnimationFrame(raf);
        }
        requestAnimationFrame(raf);
    }

    /* ==========================================================
       4. SMOOTH ANCHOR NAVIGATION
    ========================================================== */
    document.addEventListener('click', function (e) {
        const anchor = e.target.closest('a[href^="#"]');
        if (!anchor) return;

        const href = anchor.getAttribute('href');
        if (href && href !== '#' && href.length > 1) {
            const target = document.querySelector(href);
            if (target) {
                e.preventDefault();
                if (lenis) {
                    lenis.scrollTo(target, { offset: 0, immediate: false });
                } else {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            }
        }
    });

    /* ==========================================================
       5. SYNCHRONIZE LENIS WITH MOBILE MENU STATE
    ========================================================== */
    function syncMenuScrollState() {
        if (!lenis) return;
        if (document.body.classList.contains('menu-open')) {
            lenis.stop();
        } else {
            lenis.start();
        }
    }

    if (window.MutationObserver) {
        const observer = new MutationObserver(syncMenuScrollState);
        observer.observe(document.body, {
            attributes: true,
            attributeFilter: ['class']
        });
    }

    /* ==========================================================
       6. CANONICAL NAVIGATION CONTROLLER (DOM READY)
    ========================================================== */
    document.addEventListener('DOMContentLoaded', function () {
        const burgerBtn = document.getElementById('burgerButton');
        const menuClose = document.getElementById('menuClose');
        const fullscreenMenu = document.getElementById('fullscreenMenu');

        function openMenu() {
            if (!fullscreenMenu) return;
            fullscreenMenu.classList.add('open');
            document.body.classList.add('menu-open');
            if (lenis) lenis.stop();
        }

        function closeMenu() {
            if (!fullscreenMenu) return;
            fullscreenMenu.classList.remove('open');
            document.body.classList.remove('menu-open');
            if (lenis) lenis.start();
        }

        if (burgerBtn) {
            burgerBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openMenu();
            });
        }

        if (menuClose) {
            menuClose.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeMenu();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && fullscreenMenu && fullscreenMenu.classList.contains('open')) {
                closeMenu();
            }
        });

        // Close menu when clicking actual navigation destination links
        const burgerLinks = document.querySelectorAll('.burger-navigation a');
        burgerLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                const href = link.getAttribute('href');
                if (href && href !== '#' && !href.startsWith('javascript:')) {
                    closeMenu();
                }
            });
        });

        // Mobile Accordion (Top-level items: Services)
        const burgerItems = document.querySelectorAll('.burger-nav-item');
        burgerItems.forEach(function (item) {
            const toggle = item.querySelector('button.burger-nav-link');
            if (!toggle) return;

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = item.classList.contains('open');

                burgerItems.forEach(function (other) {
                    if (other !== item) {
                        other.classList.remove('open');
                        const otherToggle = other.querySelector('button.burger-nav-link');
                        if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
                    }
                });

                if (!isOpen) {
                    item.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                } else {
                    item.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // Mobile Nested Sub-accordions (Categories inside Services)
        const subItems = document.querySelectorAll('.burger-subitem');
        subItems.forEach(function (sub) {
            const subToggle = sub.querySelector('.burger-sub-toggle');
            if (!subToggle) return;

            subToggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = sub.classList.contains('open');
                sub.classList.toggle('open');
                subToggle.setAttribute('aria-expanded', (!isOpen).toString());
            });
        });

        // Active page detection & highlight for mobile menu
        try {
            const currentPath = window.location.pathname.split('/').pop() || 'index.html';
            const allBurgerLinks = document.querySelectorAll('.burger-navigation a');
            allBurgerLinks.forEach(function (link) {
                const href = link.getAttribute('href');
                if (href && href === currentPath) {
                    link.classList.add('active');
                    const parentSubitem = link.closest('.burger-subitem');
                    if (parentSubitem) {
                        parentSubitem.classList.add('open');
                        const t = parentSubitem.querySelector('.burger-sub-toggle');
                        if (t) t.setAttribute('aria-expanded', 'true');
                    }
                    const parentNavItem = link.closest('.burger-nav-item');
                    if (parentNavItem) {
                        parentNavItem.classList.add('open');
                        const t = parentNavItem.querySelector('button.burger-nav-link');
                        if (t) t.setAttribute('aria-expanded', 'true');
                    }
                }
            });
        } catch (err) {}

        // Desktop Dropdown Toggles (Compact Dark Dropdown)
        const desktopDropdowns = document.querySelectorAll('.desktop-nav .dropdown');
        desktopDropdowns.forEach(function (dropdown) {
            const toggle = dropdown.querySelector('.dropdown-toggle');
            if (!toggle) return;

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                desktopDropdowns.forEach(function (other) {
                    if (other !== dropdown) other.classList.remove('open');
                });
                dropdown.classList.toggle('open');
            });
        });

        // Submenu hover / click accessibility & right boundary overflow check
        const submenus = document.querySelectorAll('.dropdown-submenu');
        submenus.forEach(function (sub) {
            const parentLink = sub.querySelector('.submenu-parent');
            const subDropdown = sub.querySelector('.submenu-dropdown');
            if (!parentLink || !subDropdown) return;

            function checkBoundary() {
                const rect = subDropdown.getBoundingClientRect();
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
                if (rect.right > viewportWidth - 10) {
                    subDropdown.classList.add('open-left');
                } else {
                    subDropdown.classList.remove('open-left');
                }
            }

            sub.addEventListener('mouseenter', checkBoundary);
            parentLink.addEventListener('focus', checkBoundary);

            // Touch accessibility: first tap opens submenu if touch device
            parentLink.addEventListener('click', function (e) {
                if (window.matchMedia('(hover: none)').matches && !sub.classList.contains('open')) {
                    e.preventDefault();
                    submenus.forEach(function (other) {
                        if (other !== sub) other.classList.remove('open');
                    });
                    sub.classList.add('open');
                    checkBoundary();
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.desktop-nav .dropdown')) {
                desktopDropdowns.forEach(function (d) {
                    d.classList.remove('open');
                });
                submenus.forEach(function (s) {
                    s.classList.remove('open');
                });
            }
        });

        // Keyboard navigation for dropdowns
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                desktopDropdowns.forEach(function (d) {
                    d.classList.remove('open');
                });
                submenus.forEach(function (s) {
                    s.classList.remove('open');
                });
            }
        });

        // In-page FAQ accordions
        document.querySelectorAll('.faq-trigger').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const card = btn.closest('.faq-card');
                if (card) {
                    card.classList.toggle('open');
                }
            });
        });

        /* ==========================================================
           7. DECORATIVE GLASS ELEMENT ANIMATIONS (HOMEPAGE)
        ========================================================== */
        if (!prefersReducedMotion && hasGSAP) {
            const cubeContainer = document.querySelector('.about-object-left');
            const shapeContainer = document.querySelector('.about-object-right');
            const cubeWrap = document.querySelector('.about-object-left-wrap');
            const shapeWrap = document.querySelector('.about-object-right-wrap');
            const cubeImg = document.querySelector('.about-object-left-img');
            const shapeImg = document.querySelector('.about-object-right-img');
            const aboutSection = document.getElementById('aboutSection') || document.querySelector('.about-section');

            if (aboutSection) {
                // Animation A: Continuous organic floating idle motion (breathing)
                if (cubeImg) {
                    gsap.to(cubeImg, {
                        y: -18,
                        rotation: 3.5,
                        duration: 4.8,
                        ease: 'sine.inOut',
                        repeat: -1,
                        yoyo: true
                    });
                }

                if (shapeImg) {
                    gsap.to(shapeImg, {
                        y: 22,
                        rotation: -5,
                        duration: 5.6,
                        ease: 'sine.inOut',
                        repeat: -1,
                        yoyo: true
                    });
                }

                // Animation B: Scroll parallax motion with ScrollTrigger
                if (hasScrollTrigger) {
                    if (cubeContainer) {
                        gsap.to(cubeContainer, {
                            scrollTrigger: {
                                trigger: aboutSection,
                                start: 'top bottom',
                                end: 'bottom top',
                                scrub: 1.2
                            },
                            y: -65,
                            x: 18,
                            rotation: 5,
                            ease: 'none'
                        });
                    }

                    if (shapeContainer) {
                        gsap.to(shapeContainer, {
                            scrollTrigger: {
                                trigger: aboutSection,
                                start: 'top bottom',
                                end: 'bottom top',
                                scrub: 1.2
                            },
                            y: 75,
                            x: -22,
                            rotation: -6,
                            ease: 'none'
                        });
                    }
                }

                // Animation C: Interactive Mouse Tracking on Fine-Pointer Devices
                const isFinePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
                if (isFinePointer && cubeWrap && shapeWrap) {
                    const cubeQuickX = gsap.quickTo(cubeWrap, 'x', { duration: 0.9, ease: 'power2.out' });
                    const cubeQuickY = gsap.quickTo(cubeWrap, 'y', { duration: 0.9, ease: 'power2.out' });
                    const shapeQuickX = gsap.quickTo(shapeWrap, 'x', { duration: 1.1, ease: 'power2.out' });
                    const shapeQuickY = gsap.quickTo(shapeWrap, 'y', { duration: 1.1, ease: 'power2.out' });

                    aboutSection.addEventListener('mousemove', function (e) {
                        const rect = aboutSection.getBoundingClientRect();
                        const normX = ((e.clientX - rect.left) / rect.width) - 0.5;
                        const normY = ((e.clientY - rect.top) / rect.height) - 0.5;

                        cubeQuickX(normX * 28);
                        cubeQuickY(normY * 24);

                        shapeQuickX(normX * -32);
                        shapeQuickY(normY * -28);
                    });

                    aboutSection.addEventListener('mouseleave', function () {
                        cubeQuickX(0);
                        cubeQuickY(0);
                        shapeQuickX(0);
                        shapeQuickY(0);
                    });
                }
            }
        }

        /* ==========================================================
           8. SERVICES SECTION: Natural vertical flow (no pinning/stacking)
        ========================================================== */
        // Sticky pinning & card stacking remain completely removed as requested.
        // Subtle one-time fade-up entrance for each independent card:
        if (!prefersReducedMotion && hasGSAP && hasScrollTrigger) {
            const servicesSec = document.querySelector('.services-section');
            if (servicesSec) {
                gsap.from('.services-header', {
                    scrollTrigger: {
                        trigger: servicesSec,
                        start: 'top 82%',
                        toggleActions: 'play none none reverse'
                    },
                    y: 35,
                    opacity: 0,
                    duration: 0.8,
                    ease: 'power3.out'
                });

                const serviceCards = document.querySelectorAll('.service-card');
                if (serviceCards.length) {
                    serviceCards.forEach(function (card) {
                        gsap.from(card, {
                            scrollTrigger: {
                                trigger: card,
                                start: 'top 88%',
                                toggleActions: 'play none none reverse'
                            },
                            y: 38,
                            opacity: 0,
                            duration: 0.8,
                            ease: 'power3.out'
                        });
                    });
                }
            }
        }

        /* ==========================================================
           9. ENHANCED WEBSITE-WIDE PREMIUM SCROLL ANIMATIONS
        ========================================================== */
        if (!prefersReducedMotion && hasGSAP) {
            // Function to animate a hero slide
            function animateSlideContent(slide) {
                if (!slide) return;
                const eyebrow = slide.querySelector('.eyebrow');
                const headingRows = slide.querySelectorAll('.heading-row, .heading-big, .heading-small');
                const slideP = slide.querySelector('.slide-right p');
                const discoverBtn = slide.querySelector('.discover-btn');

                gsap.killTweensOf([eyebrow, headingRows, slideP, discoverBtn]);

                const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

                if (eyebrow) {
                    tl.fromTo(eyebrow, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.8 });
                }
                if (headingRows.length) {
                    tl.fromTo(headingRows, { opacity: 0, y: 40 }, { opacity: 1, y: 0, duration: 0.95, stagger: 0.12 }, '-=0.5');
                }
                if (slideP) {
                    tl.fromTo(slideP, { opacity: 0, y: 28 }, { opacity: 1, y: 0, duration: 0.85 }, '-=0.5');
                }
                if (discoverBtn) {
                    tl.fromTo(discoverBtn, { opacity: 0, y: 22, scale: 0.94 }, { opacity: 1, y: 0, scale: 1, duration: 0.75, ease: 'back.out(1.5)' }, '-=0.4');
                }
            }

            // Animate initial active slide
            const initialSlide = document.querySelector('.slide.active') || document.querySelector('.slide');
            if (initialSlide) {
                animateSlideContent(initialSlide);

                // Gentle animated hero metrics
                const heroStats = document.querySelectorAll('.hero-bottom span');
                if (heroStats.length) {
                    gsap.fromTo(heroStats,
                        { opacity: 0, y: 18 },
                        { opacity: 1, y: 0, duration: 0.75, stagger: 0.1, delay: 0.4, ease: 'power2.out' }
                    );
                }
            }

            // Observer for hero slide changes
            const allSlides = document.querySelectorAll('.slide');
            if (allSlides.length && window.MutationObserver) {
                const slideObserver = new MutationObserver(function (mutations) {
                    mutations.forEach(function (mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                            const target = mutation.target;
                            if (target.classList.contains('active')) {
                                animateSlideContent(target);
                            }
                        }
                    });
                });
                allSlides.forEach(function (slide) {
                    slideObserver.observe(slide, { attributes: true, attributeFilter: ['class'] });
                });
            }

            if (hasScrollTrigger) {
                // About Section Reveal
                const aboutSec = document.querySelector('.about-section');
                if (aboutSec) {
                    const aboutTl = gsap.timeline({
                        scrollTrigger: {
                            trigger: aboutSec,
                            start: 'top 78%',
                            toggleActions: 'play none none reverse'
                        }
                    });

                    aboutTl.from('.about-label', {
                        y: 25,
                        opacity: 0,
                        duration: 0.75,
                        ease: 'power3.out'
                    })
                    .from('.about-heading', {
                        y: 42,
                        opacity: 0,
                        duration: 0.9,
                        ease: 'power3.out'
                    }, '-=0.45')
                    .from('.about-description', {
                        y: 30,
                        opacity: 0,
                        duration: 0.8,
                        ease: 'power3.out'
                    }, '-=0.4')
                    .from('.about-button', {
                        y: 24,
                        opacity: 0,
                        scale: 0.94,
                        duration: 0.75,
                        ease: 'back.out(1.5)'
                    }, '-=0.5');
                }

                // Values / Experience Cards Reveal
                const valuesSec = document.querySelector('.values-section');
                if (valuesSec) {
                    gsap.fromTo('.experience-card, .value-card, .experience-counter-card', {
                        y: 45,
                        opacity: 0,
                        scale: 0.97
                    }, {
                        scrollTrigger: {
                            trigger: valuesSec,
                            start: 'top 78%',
                            toggleActions: 'play none none reverse'
                        },
                        y: 0,
                        opacity: 1,
                        scale: 1,
                        duration: 0.85,
                        stagger: 0.14,
                        ease: 'power3.out'
                    });
                }

                // Creative Solutions Section
                const creativeSec = document.querySelector('.creative-section');
                if (creativeSec) {
                    const creativeTl = gsap.timeline({
                        scrollTrigger: {
                            trigger: creativeSec,
                            start: 'top 78%',
                            toggleActions: 'play none none reverse'
                        }
                    });

                    creativeTl.from('.creative-heading h2', {
                        y: 45,
                        opacity: 0,
                        duration: 0.9,
                        ease: 'power3.out'
                    })
                    .from('.creative-pill', {
                        scale: 0.75,
                        opacity: 0,
                        duration: 0.85,
                        ease: 'back.out(1.6)'
                    }, '-=0.5')
                    .from('.creative-left', {
                        y: 35,
                        opacity: 0,
                        duration: 0.85,
                        ease: 'power3.out'
                    }, '-=0.35')
                    .from('.creative-right .creative-info', {
                        y: 35,
                        opacity: 0,
                        duration: 0.85,
                        stagger: 0.16,
                        ease: 'power3.out'
                    }, '-=0.5');

                    // Subtle background parallax on desktop
                    if (window.innerWidth >= 992) {
                        gsap.to(creativeSec, {
                            scrollTrigger: {
                                trigger: creativeSec,
                                start: 'top bottom',
                                end: 'bottom top',
                                scrub: 1.5
                            },
                            backgroundPositionY: '60%',
                            ease: 'none'
                        });
                    }
                }

                // 03/ WHY CHOOSE US
                const whySec = document.querySelector('.why-section');
                if (whySec) {
                    const whyTl = gsap.timeline({
                        scrollTrigger: {
                            trigger: whySec,
                            start: 'top 76%',
                            toggleActions: 'play none none reverse'
                        }
                    });

                    whyTl.from('.why-label', {
                        x: -30,
                        opacity: 0,
                        duration: 0.7,
                        ease: 'power3.out'
                    })
                    .from('.why-header h2', {
                        y: 42,
                        opacity: 0,
                        duration: 0.85,
                        ease: 'power3.out'
                    }, '-=0.4')
                    .from('.why-card', {
                        y: 52,
                        opacity: 0,
                        scale: 0.95,
                        duration: 0.85,
                        stagger: 0.14,
                        ease: 'power3.out'
                    }, '-=0.4')
                    .from('.why-tag', {
                        y: 14,
                        opacity: 0,
                        scale: 0.85,
                        duration: 0.6,
                        stagger: 0.14,
                        ease: 'back.out(1.6)'
                    }, '-=0.7')
                    .from('.why-icon', {
                        scale: 0,
                        rotation: -25,
                        opacity: 0,
                        duration: 0.75,
                        stagger: 0.14,
                        ease: 'back.out(2)'
                    }, '-=0.8');
                }

                // 04/ REAL TESTIMONIALS
                const testSec = document.querySelector('.testimonials-section');
                if (testSec) {
                    const testTl = gsap.timeline({
                        scrollTrigger: {
                            trigger: testSec,
                            start: 'top 76%',
                            toggleActions: 'play none none reverse'
                        }
                    });

                    testTl.from('.testimonials-heading h2', {
                        y: 45,
                        opacity: 0,
                        duration: 0.9,
                        ease: 'power3.out'
                    })
                    .from('.testimonials-rating', {
                        y: 28,
                        opacity: 0,
                        duration: 0.75,
                        ease: 'power3.out'
                    }, '-=0.5')
                    .from('.testimonials-rating svg, .testimonials-rating .star, .rating-star', {
                        scale: 0,
                        opacity: 0,
                        duration: 0.45,
                        stagger: 0.08,
                        ease: 'back.out(2)'
                    }, '-=0.4')
                    .fromTo('.testimonial-card', {
                        y: 55,
                        opacity: 0,
                        scale: 0.95
                    }, {
                        y: 0,
                        opacity: 1,
                        scale: 1,
                        duration: 0.88,
                        stagger: 0.16,
                        ease: 'power3.out'
                    }, '-=0.3')
                    .fromTo('.quote-mark', {
                        scale: 0.7,
                        rotation: -12
                    }, {
                        scale: 1,
                        rotation: 0,
                        duration: 0.55,
                        stagger: 0.12,
                        ease: 'back.out(1.8)',
                        clearProps: 'transform'
                    }, '-=0.55')
                    .from('.testimonial-person img', {
                        scale: 0.8,
                        opacity: 0,
                        duration: 0.55,
                        stagger: 0.12,
                        ease: 'back.out(1.5)'
                    }, '-=0.55')
                    .from('.testimonial-person h3, .testimonial-person span', {
                        y: 14,
                        opacity: 0,
                        duration: 0.5,
                        stagger: 0.06,
                        ease: 'power2.out'
                    }, '-=0.4');
                }

                // CONNECT / NEWSLETTER / FOOTER CTA
                const connSec = document.querySelector('.connect-section');
                if (connSec) {
                    gsap.from('.connect-heading h2', {
                        scrollTrigger: {
                            trigger: connSec,
                            start: 'top 78%',
                            toggleActions: 'play none none reverse'
                        },
                        y: 40,
                        opacity: 0,
                        duration: 0.85,
                        stagger: 0.12,
                        ease: 'power3.out'
                    });

                    gsap.from('.connect-content', {
                        scrollTrigger: {
                            trigger: '.connect-content',
                            start: 'top 85%',
                            toggleActions: 'play none none reverse'
                        },
                        y: 32,
                        opacity: 0,
                        duration: 0.85,
                        ease: 'power3.out'
                    });
                }

                // INNER PAGES HERO & SECTION REVEALS
                const subpageHero = document.querySelector('.about-page-hero, .growth-hero, .inner-hero-banner, .svc-overview-hero, .svc-detail-hero');
                if (subpageHero) {
                    const heroTitle = subpageHero.querySelector('h1, .growth-title, .svc-hero-title, .inner-hero-title');
                    if (heroTitle) {
                        gsap.from(heroTitle, {
                            opacity: 0,
                            y: 35,
                            duration: 0.85,
                            ease: 'power3.out'
                        });
                    }
                }

                const subSections = document.querySelectorAll('.who-section, .dc-section, .contact-section, .gs-page-section, .gs-details-section, .svc-overview-section, .svc-detail-section');
                subSections.forEach(function (sec) {
                    gsap.from(sec.children, {
                        scrollTrigger: {
                            trigger: sec,
                            start: 'top 82%',
                            toggleActions: 'play none none reverse'
                        },
                        y: 35,
                        opacity: 0,
                        duration: 0.8,
                        stagger: 0.12,
                        ease: 'power3.out'
                    });
                });
            }
        }
    });

})();
