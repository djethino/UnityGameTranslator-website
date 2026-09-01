/**
 * Thousands of points, drawn by the GPU — twice.
 *
 * ── Why points and not shapes ──────────────────────────────────────────────────────────────────
 * A blob here is not a form, it is a POPULATION. Nothing in this file knows what shape anything is
 * meant to be: it receives a list of points, each with a position, a depth and an allegiance, and
 * draws them. The shapes — a ring, a squadron, a letter — are an emergent property of where the
 * points are, which is why a cloud can stretch, hollow out, split and re-form without a single line
 * of code describing any of those events.
 *
 * ── The two passes, and why they blend differently ─────────────────────────────────────────────
 * 🔴 This is the whole look, and it rests on separating two things usually conflated: the BODIES,
 * and the LIGHT THEY CAST.
 *
 *   **The wash** — every point drawn large and faint into a small buffer with ADDITIVE blending,
 *   then stretched back over the frame. Additive is mixing, and here mixing is exactly right: this
 *   is light, and where a blue cloud and a mauve one pass close their glow blends on the background
 *   the way two coloured lamps blend on a wall. Order-independent, so no sorting; and at a fifth of
 *   the resolution the upscale IS the blur, so there is no blur pass to pay for.
 *
 *   **The bodies** — the same points, sharp, with SORTED ALPHA blending. Here mixing would be a
 *   defect: a nearer point simply covers a farther one, so two populations interpenetrating give a
 *   stipple of both colours, each still itself. Two powders stirred together, not a watercolour.
 *
 * So the clouds light one another without ever fusing. The sort is not optional for the second
 * pass: alpha blending is order-dependent, and unsorted the near/far relationship flickers frame to
 * frame. It is a counting sort over depth buckets — two linear passes, not a comparison sort.
 *
 * ── WebGL, and what happens without it ─────────────────────────────────────────────────────────
 * Where WebGL is unavailable — Firefox blocklists some drivers, hardened profiles disable it — we
 * add a class to <body> and CSS paints a still gradient. No second engine: a fallback renderer
 * nobody exercises is a fallback that does not work.
 */

const POINT_VERT = `
precision highp float;

attribute vec4 aData;   // xyz = position in the field, w = brightness the pattern asked for
attribute vec3 aColor;  // the point's allegiance, decided once and never revisited
attribute float aSize;  // small per-point variation, so a cloud is never a regular lattice

uniform float uAspect;
uniform float uPointScale;
uniform float uAlpha;
uniform float uWarp;

varying vec3 vColor;
varying float vAlpha;
varying float vSoft;   // 0 = far, a tight spark · 1 = near, a broad glow

void main() {
    // The whole of the fake perspective: one divide. Near is big, far is small.
    // ⚠ No guard on the divisor here on purpose: the engine floors every point's depth at 0.12 as
    // it fills the buffer, which is the one place that can do it once for both passes.
    float p = 1.0 / aData.z;

    vec2 clip = vec2(aData.x * p / uAspect, -aData.y * p);

    // A real lens, not a sheared sprite. Now that every point is placed individually there is
    // nothing to fake: displacing each one radially IS barrel distortion, and it costs a dot
    // product. The ambush drives this to bend the field as the clouds come past the camera.
    if (uWarp != 0.0) clip *= 1.0 + uWarp * dot(clip, clip);

    gl_Position = vec4(clip, 0.0, 1.0);

    // ⚠ Not pure perspective. A point that shrinks exactly as 1/z collapses below the size where a
    // soft falloff can show anything, and the far half of the field empties. The constant term is
    // an atmospheric cheat — distant lights read larger than geometry says — and it is what keeps
    // the far clouds present. The 3 px floor catches whatever is left.
    gl_PointSize = max(3.0, aSize * uPointScale * (p * 0.85 + 0.22));

    vColor = aColor;

    // 🔴 Depth is carried by DIFFUSION, not by transparency. Two earlier versions put it in the
    // alpha — clamp(p * 0.75, 0.25, 1.4), then clamp(0.62 + 0.33p, 0.70, 1.20) — and both made the
    // far clouds fade out instead of recede, because the size was already falling off at the same
    // rate and the two multiplied. Brightness now barely moves; what changes with distance is how
    // wide the glow spreads.
    vAlpha = uAlpha * aData.w * clamp(0.86 + 0.10 * p, 0.86, 1.10);

    // Far points are compact sparks, near ones broad haloes — the same statement as "they grow and
    // become more diffuse as they approach", expressed where it belongs.
    vSoft = clamp((p - 0.40) / 1.20, 0.0, 1.0);
}
`;

const POINT_FRAG = `
precision mediump float;

uniform float uGlow;

varying vec3 vColor;
varying float vAlpha;
varying float vSoft;

void main() {
    // A soft disc, evaluated per pixel. No bitmap, so nothing to upscale and no square to leak.
    vec2 d = gl_PointCoord - vec2(0.5);
    float r2 = dot(d, d) * 4.0;
    if (r2 > 1.0) discard;

    // Both terms are exactly zero at the rim with zero slope there. Anything that merely gets small
    // at the edge draws a visible disc outline once thousands of them overlap.
    float w = 1.0 - r2;

    // A point is a light, not a dot: a tight core for presence, and a wide halo around it that is
    // most of what the eye registers at a distance.
    float core = w * w * w * w;
    float halo = w * w;

    gl_FragColor = vec4(vColor, vAlpha * min(1.0, core + uGlow * mix(0.65, 1.45, vSoft) * halo));
}
`;

const WASH_VERT = `
precision highp float;
attribute vec2 aQuad;
varying vec2 vUv;
void main() {
    vUv = aQuad * 0.5 + 0.5;
    gl_Position = vec4(aQuad, 0.0, 1.0);
}
`;

const WASH_FRAG = `
precision mediump float;
uniform sampler2D uTex;
uniform float uIntensity;
varying vec2 vUv;

void main() {
    vec4 t = texture2D(uTex, vUv);

    // The wash buffer accumulated each colour scaled by its own contribution, and the contributions
    // themselves in alpha. Dividing recovers the blended hue — the average of every cloud that lit
    // this pixel, weighted by how much each one lit it.
    //
    // 🔴 That division is the entire point of this pass. It is where a blue cloud and a mauve one
    // become ONE colour on the background, while their bodies, drawn afterwards, stay separate.
    // Light mixes; matter does not.
    vec3 hue = t.rgb / max(t.a, 0.0025);

    gl_FragColor = vec4(hue, min(1.0, t.a * uIntensity));
}
`;

// Depth buckets for the counting sort. Far finer than the eye can separate at these sizes, and the
// whole sort stays two linear passes over the points.
const BUCKETS = 512;

/** The wash is a blur by construction: drawn small, stretched back. A fifth is plenty. */
const WASH_DIV = 5;

function compile(gl, type, source) {
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        const log = gl.getShaderInfoLog(shader);
        gl.deleteShader(shader);
        throw new Error(`ambient shader: ${log}`);
    }
    return shader;
}

function link(gl, vert, frag) {
    const program = gl.createProgram();
    gl.attachShader(program, compile(gl, gl.VERTEX_SHADER, vert));
    gl.attachShader(program, compile(gl, gl.FRAGMENT_SHADER, frag));
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(program));
    return program;
}

export function createRenderer(canvas, capacity) {
    const gl = canvas.getContext('webgl', {
        alpha: true,
        antialias: false,        // every point is already soft-edged; multisampling would be paid for nothing
        depth: false,            // the sort does this job, and correctly for translucent points
        premultipliedAlpha: false,
        powerPreference: 'low-power',
        failIfMajorPerformanceCaveat: false,
    });
    if (!gl) return null;

    let pointProg, washProg;
    try {
        pointProg = link(gl, POINT_VERT, POINT_FRAG);
        washProg = link(gl, WASH_VERT, WASH_FRAG);
    } catch {
        return null;
    }

    const pLoc = {
        data: gl.getAttribLocation(pointProg, 'aData'),
        color: gl.getAttribLocation(pointProg, 'aColor'),
        size: gl.getAttribLocation(pointProg, 'aSize'),
        aspect: gl.getUniformLocation(pointProg, 'uAspect'),
        pointScale: gl.getUniformLocation(pointProg, 'uPointScale'),
        alpha: gl.getUniformLocation(pointProg, 'uAlpha'),
        glow: gl.getUniformLocation(pointProg, 'uGlow'),
        warp: gl.getUniformLocation(pointProg, 'uWarp'),
    };
    const wLoc = {
        quad: gl.getAttribLocation(washProg, 'aQuad'),
        tex: gl.getUniformLocation(washProg, 'uTex'),
        intensity: gl.getUniformLocation(washProg, 'uIntensity'),
    };

    // ⚠ Implementations cap gl_PointSize, and the cap can be as low as 64. Points here stay well
    // under that by design — a cloud is large because it has many points, not because each one is —
    // but the ceiling is read rather than assumed, and the scale clamped to it.
    const maxPoint = gl.getParameter(gl.ALIASED_POINT_SIZE_RANGE)[1] || 64;

    const dataBuf = gl.createBuffer();
    const colorBuf = gl.createBuffer();
    const sizeBuf = gl.createBuffer();
    const indexBuf = gl.createBuffer();

    const quadBuf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, quadBuf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);

    const washTex = gl.createTexture();
    const washFbo = gl.createFramebuffer();
    gl.bindTexture(gl.TEXTURE_2D, washTex);
    // LINEAR is what turns the small buffer into a smooth wash instead of into visible squares.
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

    const order = new Uint16Array(capacity);
    const counts = new Int32Array(BUCKETS + 1);

    gl.bindBuffer(gl.ARRAY_BUFFER, dataBuf);
    gl.bufferData(gl.ARRAY_BUFFER, capacity * 4 * 4, gl.DYNAMIC_DRAW);

    gl.enable(gl.BLEND);
    gl.disable(gl.DEPTH_TEST);

    let W = 0, H = 0, washW = 0, washH = 0;

    function bindPointAttribs() {
        gl.bindBuffer(gl.ARRAY_BUFFER, dataBuf);
        gl.enableVertexAttribArray(pLoc.data);
        gl.vertexAttribPointer(pLoc.data, 4, gl.FLOAT, false, 0, 0);
        gl.bindBuffer(gl.ARRAY_BUFFER, colorBuf);
        gl.enableVertexAttribArray(pLoc.color);
        gl.vertexAttribPointer(pLoc.color, 3, gl.FLOAT, false, 0, 0);
        gl.bindBuffer(gl.ARRAY_BUFFER, sizeBuf);
        gl.enableVertexAttribArray(pLoc.size);
        gl.vertexAttribPointer(pLoc.size, 1, gl.FLOAT, false, 0, 0);
    }

    return {
        /** Colours and sizes do not change per frame, so they are uploaded when the layout does. */
        setStatic(colors, sizes) {
            gl.bindBuffer(gl.ARRAY_BUFFER, colorBuf);
            gl.bufferData(gl.ARRAY_BUFFER, colors, gl.STATIC_DRAW);
            gl.bindBuffer(gl.ARRAY_BUFFER, sizeBuf);
            gl.bufferData(gl.ARRAY_BUFFER, sizes, gl.STATIC_DRAW);
        },

        /** Wipe the frame and leave it wiped — for when the visitor has turned the background off. */
        clear() {
            gl.bindFramebuffer(gl.FRAMEBUFFER, null);
            gl.viewport(0, 0, W, H);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
        },

        resize(w, h) {
            W = w; H = h;
            washW = Math.max(16, Math.round(w / WASH_DIV));
            washH = Math.max(16, Math.round(h / WASH_DIV));
            gl.bindTexture(gl.TEXTURE_2D, washTex);
            gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, washW, washH, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
            gl.bindFramebuffer(gl.FRAMEBUFFER, washFbo);
            gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, washTex, 0);
            gl.bindFramebuffer(gl.FRAMEBUFFER, null);
        },

        draw({ points, count, aspect, pointScale, alpha, glow, warp,
               washSpread, washAlpha, washIntensity, keep, zNear, zFar }) {
            if (!count) return 0;

            gl.bindBuffer(gl.ARRAY_BUFFER, dataBuf);
            gl.bufferSubData(gl.ARRAY_BUFFER, 0, points.subarray(0, count * 4));

            // ---- pass 1 : the light ----
            // Additive, so no sort is needed and none is done: a sum is a sum whatever order it
            // arrives in. That is also why the colours mix here, and only here.
            gl.bindFramebuffer(gl.FRAMEBUFFER, washFbo);
            gl.viewport(0, 0, washW, washH);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);

            // blendFuncSeparate, and the separation is not a nicety.
            //
            // 🔴 blendFunc(SRC_ALPHA, ONE) applies src.a to EVERY channel, alpha included, so the
            // buffer accumulates the sum of a SQUARED instead of the sum of a. At a = 0.03 that is
            // 0.0009 — thirty-three times too little — and the wash existed but was invisible,
            // while rgb/a came out wrong on top of it. RGB must be weighted by each contribution,
            // ALPHA must simply add them up.
            gl.blendFuncSeparate(gl.SRC_ALPHA, gl.ONE, gl.ONE, gl.ONE);

            gl.useProgram(pointProg);
            bindPointAttribs();
            gl.uniform1f(pLoc.aspect, aspect);
            gl.uniform1f(pLoc.warp, warp || 0);
            gl.uniform1f(pLoc.alpha, washAlpha);
            gl.uniform1f(pLoc.glow, 1.0);   // all halo, no core: this is a glow, not a grain
            gl.uniform1f(pLoc.pointScale, Math.min(maxPoint, (pointScale / WASH_DIV) * washSpread));
            gl.drawArrays(gl.POINTS, 0, count);

            // ---- composite the light onto the frame ----
            gl.bindFramebuffer(gl.FRAMEBUFFER, null);
            gl.viewport(0, 0, W, H);
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

            gl.useProgram(washProg);
            gl.bindBuffer(gl.ARRAY_BUFFER, quadBuf);
            gl.enableVertexAttribArray(wLoc.quad);
            gl.vertexAttribPointer(wLoc.quad, 2, gl.FLOAT, false, 0, 0);
            gl.activeTexture(gl.TEXTURE0);
            gl.bindTexture(gl.TEXTURE_2D, washTex);
            gl.uniform1i(wLoc.tex, 0);
            gl.uniform1f(wLoc.intensity, washIntensity);
            gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
            gl.disableVertexAttribArray(wLoc.quad);

            // ---- pass 2 : the bodies ----
            // ⚠ Thinning is decided per point by a stable hash rather than by taking a prefix of the
            // list — a prefix would empty one cloud entirely and leave the others untouched.
            // 🔴 And the SAME test has to run in both passes of the sort: counting every point but
            // placing only some leaves holes in `order`, and the draw reads whatever is in them.
            const span = zFar - zNear || 1;
            const thin = keep < 1;
            const kept = (i) => !thin || ((Math.imul(i, 2654435761) >>> 8) % 1024) / 1024 < keep;
            const bucketOf = (z) => {
                // Reversed on purpose: bucket 0 is the FAR end, so the natural order is
                // back-to-front, which is what alpha blending needs.
                const b = Math.floor(BUCKETS - 1 - ((z - zNear) / span) * (BUCKETS - 1));
                return b < 0 ? 0 : b >= BUCKETS ? BUCKETS - 1 : b;
            };

            counts.fill(0);
            for (let i = 0; i < count; i++) {
                if (!kept(i)) continue;
                counts[bucketOf(points[i * 4 + 2]) + 1]++;
            }
            for (let b = 0; b < BUCKETS; b++) counts[b + 1] += counts[b];

            let drawn = 0;
            for (let i = 0; i < count; i++) {
                if (!kept(i)) continue;
                order[counts[bucketOf(points[i * 4 + 2])]++] = i;
                drawn++;
            }

            gl.useProgram(pointProg);
            bindPointAttribs();
            gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, indexBuf);
            gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, order.subarray(0, drawn), gl.STREAM_DRAW);

            gl.uniform1f(pLoc.aspect, aspect);
            gl.uniform1f(pLoc.pointScale, Math.min(pointScale, maxPoint));
            gl.uniform1f(pLoc.alpha, alpha);
            gl.uniform1f(pLoc.glow, glow);
            gl.uniform1f(pLoc.warp, warp || 0);

            gl.drawElements(gl.POINTS, drawn, gl.UNSIGNED_SHORT, 0);
            return drawn;
        },

        get lost() { return gl.isContextLost(); },
    };
}
