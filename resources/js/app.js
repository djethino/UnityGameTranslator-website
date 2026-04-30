import './bootstrap';

// Alpine.js (CSP build — no eval/Function, compatible with strict CSP)
import Alpine from '@alpinejs/csp';

// Alpine components
import mergeTable from './components/merge-table.js';
Alpine.data('mergeTable', mergeTable);

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
    let lastFrameTime = performance.now();
    let isScrolling = false;
    let scrollTimeout = null;
    let isPaused = false;

    // Config (values are per-second rates, scaled by delta time)
    const baseSpeed = 0.02; // Animation speed per second (~30s cycle)
    const scrollMultiplier = 0.8; // How much scroll affects time (per second)
    const velocityDecay = 5; // Velocity decay rate (higher = faster return to base speed)
    const velocitySmoothness = 8; // Interpolation speed per second (higher = faster response)

    // CSS custom properties pipeline: write values to body.style only.
    // The stylesheet (app.css) consumes them via var(--bg1-x) etc. so no
    // CSS parsing happens per frame — only a property-value update that
    // browsers fast-path through compositing. Avoids the layout thrashing
    // observed on Firefox when the stylesheet was rewritten every frame.
    const bs = body.style;

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

        bs.setProperty('--bg1-x', x1 + '%');
        bs.setProperty('--bg1-y', y1 + '%');
        bs.setProperty('--bg1-scale', scale1);
        bs.setProperty('--bg1-opacity', opacity1);
        bs.setProperty('--bg2-x', x2 + '%');
        bs.setProperty('--bg2-y', y2 + '%');
        bs.setProperty('--bg2-scale', scale2);
        bs.setProperty('--bg2-rotate', rotate2 + 'deg');
        bs.setProperty('--bg2-opacity', opacity2);
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

    function animate(currentTime) {
        // Calculate delta time in seconds
        const deltaTime = Math.min((currentTime - lastFrameTime) / 1000, 0.1); // Cap at 100ms to prevent jumps
        lastFrameTime = currentTime;

        // Skip updates if paused (tab not visible)
        if (isPaused) {
            requestAnimationFrame(animate);
            return;
        }

        // Smooth velocity interpolation (frame-rate independent)
        const lerpFactor = 1 - Math.exp(-velocitySmoothness * deltaTime);
        velocity += (targetVelocity - velocity) * lerpFactor;

        // Decay velocity when not scrolling (frame-rate independent)
        if (!isScrolling) {
            velocity *= Math.exp(-velocityDecay * deltaTime);
        }

        // Update time: base speed + scroll velocity (scaled by delta)
        time += (baseSpeed + velocity) * deltaTime;

        updateTransforms();
        requestAnimationFrame(animate);
    }

    // Pause when tab not visible (save resources)
    document.addEventListener('visibilitychange', () => {
        isPaused = document.hidden;
        // Reset timing when returning to avoid jumps
        if (!isPaused) {
            lastFrameTime = performance.now();
            velocity = 0;
            targetVelocity = 0;
        }
    });

    // Start
    window.addEventListener('scroll', onScroll, { passive: true });
    requestAnimationFrame(animate);
})();
