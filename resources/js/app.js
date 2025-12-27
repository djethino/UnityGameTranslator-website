import './bootstrap';

// Alpine.js
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

// Organic background parallax - scroll velocity affects animation time
(function() {
    const body = document.body;
    if (!body.classList.contains('animated-bg')) return;

    // State
    let time = 0;
    let velocity = 0;
    let targetVelocity = 0;
    let lastScrollY = window.scrollY;
    let lastScrollTime = performance.now();
    let isScrolling = false;
    let scrollTimeout = null;

    // Config
    const baseSpeed = 0.00008; // Normal animation speed (very slow)
    const scrollMultiplier = 0.015; // How much scroll affects time
    const velocityDecay = 0.92; // How fast velocity returns to normal (0.9-0.99)
    const velocitySmoothness = 0.08; // How smooth the velocity change is

    // Create style element for pseudo-element transforms
    const style = document.createElement('style');
    style.id = 'bg-parallax-style';
    document.head.appendChild(style);

    function updateTransforms() {
        // Calculate positions based on time (using sine waves for organic motion)
        const t1 = time * 0.5; // Layer 1 time
        const t2 = time * 0.35; // Layer 2 slower

        // Layer 1 transforms
        const x1 = Math.sin(t1) * 3 + Math.sin(t1 * 1.3) * 2;
        const y1 = Math.cos(t1 * 0.8) * 2 + Math.sin(t1 * 0.5) * 1.5;
        const scale1 = 1 + Math.sin(t1 * 0.6) * 0.02;
        const opacity1 = 0.9 + Math.sin(t1 * 0.4) * 0.1;

        // Layer 2 transforms (different frequencies for organic feel)
        const x2 = Math.cos(t2 * 0.7) * 4 + Math.sin(t2 * 1.1) * 2;
        const y2 = Math.sin(t2 * 0.9) * 3 + Math.cos(t2 * 0.6) * 1.5;
        const scale2 = 1 + Math.cos(t2 * 0.5) * 0.03;
        const rotate2 = Math.sin(t2 * 0.3) * 1;
        const opacity2 = 0.75 + Math.sin(t2 * 0.35) * 0.1;

        style.textContent = `
            .animated-bg::before {
                transform: translate(${x1}%, ${y1}%) scale(${scale1});
                opacity: ${opacity1};
            }
            .animated-bg::after {
                transform: translate(${x2}%, ${y2}%) scale(${scale2}) rotate(${rotate2}deg);
                opacity: ${opacity2};
            }
        `;
    }

    function onScroll() {
        const now = performance.now();
        const currentScrollY = window.scrollY;
        const deltaY = currentScrollY - lastScrollY;
        const deltaTime = now - lastScrollTime;

        if (deltaTime > 0) {
            // Calculate scroll velocity (pixels per ms, then scale)
            const scrollSpeed = deltaY / deltaTime;
            // Exponential response - faster scroll = much more effect
            targetVelocity = Math.sign(scrollSpeed) * Math.pow(Math.abs(scrollSpeed), 1.3) * scrollMultiplier;
        }

        lastScrollY = currentScrollY;
        lastScrollTime = now;
        isScrolling = true;

        // Reset scrolling state after pause
        clearTimeout(scrollTimeout);
        scrollTimeout = setTimeout(() => {
            isScrolling = false;
            targetVelocity = 0;
        }, 100);
    }

    function animate() {
        // Smooth velocity interpolation
        velocity += (targetVelocity - velocity) * velocitySmoothness;

        // Decay velocity when not scrolling
        if (!isScrolling) {
            velocity *= velocityDecay;
        }

        // Update time: base speed + scroll velocity
        time += baseSpeed + velocity;

        updateTransforms();
        requestAnimationFrame(animate);
    }

    // Start
    window.addEventListener('scroll', onScroll, { passive: true });
    animate();
})();
