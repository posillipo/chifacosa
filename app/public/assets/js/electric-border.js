/*!
 * Bordo "Electric" per il tema pubblico CHI FA COSA — adattato dal componente ElectricBorder
 * di ReactBits (https://github.com/DavidHDev/react-bits), a sua volta ispirato al pen
 * "Electric Border" di Balint Ferenczy (https://codepen.io/BalintFerenczy/pen/yyYErXa).
 * Copyright (c) 2026 David Haz — licenza MIT + Commons Clause (uso in un prodotto/sito
 * consentito, non redistribuibile come componente a sé stante).
 * Porting da React/Canvas a JavaScript vanilla, senza dipendenze, per uso diretto via <script>.
 */
(function () {
    var targets = document.querySelectorAll('.electric-border');
    if (!targets.length) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    function random(x) {
        return (Math.sin(x * 12.9898) * 43758.5453) % 1;
    }

    function noise2D(x, y) {
        var i = Math.floor(x), j = Math.floor(y);
        var fx = x - i, fy = y - j;
        var a = random(i + j * 57);
        var b = random(i + 1 + j * 57);
        var c = random(i + (j + 1) * 57);
        var d = random(i + 1 + (j + 1) * 57);
        var ux = fx * fx * (3 - 2 * fx);
        var uy = fy * fy * (3 - 2 * fy);
        return a * (1 - ux) * (1 - uy) + b * ux * (1 - uy) + c * (1 - ux) * uy + d * ux * uy;
    }

    function octavedNoise(x, octaves, lacunarity, gain, baseAmplitude, baseFrequency, time, seed, baseFlatness) {
        var y = 0, amplitude = baseAmplitude, frequency = baseFrequency;
        for (var i = 0; i < octaves; i++) {
            var octaveAmplitude = amplitude;
            if (i === 0) octaveAmplitude *= baseFlatness;
            y += octaveAmplitude * noise2D(frequency * x + seed * 100, time * frequency * 0.3);
            frequency *= lacunarity;
            amplitude *= gain;
        }
        return y;
    }

    function getCornerPoint(cx, cy, radius, startAngle, arcLength, progress) {
        var angle = startAngle + progress * arcLength;
        return { x: cx + radius * Math.cos(angle), y: cy + radius * Math.sin(angle) };
    }

    function getRoundedRectPoint(t, left, top, width, height, radius) {
        var straightWidth = width - 2 * radius;
        var straightHeight = height - 2 * radius;
        var cornerArc = (Math.PI * radius) / 2;
        var totalPerimeter = 2 * straightWidth + 2 * straightHeight + 4 * cornerArc;
        var distance = t * totalPerimeter;
        var accumulated = 0;

        if (distance <= accumulated + straightWidth) {
            var p1 = (distance - accumulated) / straightWidth;
            return { x: left + radius + p1 * straightWidth, y: top };
        }
        accumulated += straightWidth;

        if (distance <= accumulated + cornerArc) {
            var p2 = (distance - accumulated) / cornerArc;
            return getCornerPoint(left + width - radius, top + radius, radius, -Math.PI / 2, Math.PI / 2, p2);
        }
        accumulated += cornerArc;

        if (distance <= accumulated + straightHeight) {
            var p3 = (distance - accumulated) / straightHeight;
            return { x: left + width, y: top + radius + p3 * straightHeight };
        }
        accumulated += straightHeight;

        if (distance <= accumulated + cornerArc) {
            var p4 = (distance - accumulated) / cornerArc;
            return getCornerPoint(left + width - radius, top + height - radius, radius, 0, Math.PI / 2, p4);
        }
        accumulated += cornerArc;

        if (distance <= accumulated + straightWidth) {
            var p5 = (distance - accumulated) / straightWidth;
            return { x: left + width - radius - p5 * straightWidth, y: top + height };
        }
        accumulated += straightWidth;

        if (distance <= accumulated + cornerArc) {
            var p6 = (distance - accumulated) / cornerArc;
            return getCornerPoint(left + radius, top + height - radius, radius, Math.PI / 2, Math.PI / 2, p6);
        }
        accumulated += cornerArc;

        if (distance <= accumulated + straightHeight) {
            var p7 = (distance - accumulated) / straightHeight;
            return { x: left, y: top + height - radius - p7 * straightHeight };
        }
        accumulated += straightHeight;

        var p8 = (distance - accumulated) / cornerArc;
        return getCornerPoint(left + radius, top + radius, radius, Math.PI, Math.PI / 2, p8);
    }

    function initElectricBorder(container) {
        // Struttura interna creata via JS: nel markup PHP il contenitore porta solo la classe
        // "electric-border" + la variabile CSS del colore, per restare invariato se lo script
        // non si carica (nessun elemento vuoto residuo nel markup).
        var canvasContainer = document.createElement('div');
        canvasContainer.className = 'eb-canvas-container';
        var canvas = document.createElement('canvas');
        canvas.className = 'eb-canvas';
        canvasContainer.appendChild(canvas);

        var layers = document.createElement('div');
        layers.className = 'eb-layers';
        var glow1 = document.createElement('div');
        glow1.className = 'eb-glow-1';
        var glow2 = document.createElement('div');
        glow2.className = 'eb-glow-2';
        var bgGlow = document.createElement('div');
        bgGlow.className = 'eb-background-glow';
        layers.appendChild(glow1);
        layers.appendChild(glow2);
        layers.appendChild(bgGlow);

        container.insertBefore(layers, container.firstChild);
        container.insertBefore(canvasContainer, container.firstChild);

        var ctx = canvas.getContext('2d');
        if (!ctx) return;

        var color = getComputedStyle(container).getPropertyValue('--electric-border-color').trim() || '#6C5CE7';
        var speed = 0.9;
        var chaos = 0.1;
        var borderRadius = 20;

        var octaves = 8;
        var lacunarity = 1.6;
        var gain = 0.7;
        var amplitude = chaos;
        var frequency = 10;
        var baseFlatness = 0;
        var displacement = 14;
        var borderOffset = 16;

        var width, height, lastDpr;
        var time = 0;
        var lastFrameTime = 0;

        function updateSize() {
            var rect = container.getBoundingClientRect();
            width = rect.width + borderOffset * 2;
            height = rect.height + borderOffset * 2;
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = width * dpr;
            canvas.height = height * dpr;
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';
            ctx.scale(dpr, dpr);
            lastDpr = dpr;
        }

        updateSize();

        function draw(currentTime) {
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            if (dpr !== lastDpr) updateSize();

            var deltaTime = (currentTime - lastFrameTime) / 1000;
            time += deltaTime * speed;
            lastFrameTime = currentTime;

            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.scale(lastDpr, lastDpr);

            ctx.strokeStyle = color;
            ctx.lineWidth = 1;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            var left = borderOffset, top = borderOffset;
            var borderWidth = width - 2 * borderOffset;
            var borderHeight = height - 2 * borderOffset;
            var maxRadius = Math.min(borderWidth, borderHeight) / 2;
            var radius = Math.min(borderRadius, maxRadius);
            var approxPerimeter = 2 * (borderWidth + borderHeight) + 2 * Math.PI * radius;
            var sampleCount = Math.max(8, Math.floor(approxPerimeter / 3));

            ctx.beginPath();
            for (var i = 0; i <= sampleCount; i++) {
                var progress = i / sampleCount;
                var point = getRoundedRectPoint(progress, left, top, borderWidth, borderHeight, radius);
                var xNoise = octavedNoise(progress * 8, octaves, lacunarity, gain, amplitude, frequency, time, 0, baseFlatness);
                var yNoise = octavedNoise(progress * 8, octaves, lacunarity, gain, amplitude, frequency, time, 1, baseFlatness);
                var dx = point.x + xNoise * displacement;
                var dy = point.y + yNoise * displacement;
                if (i === 0) ctx.moveTo(dx, dy); else ctx.lineTo(dx, dy);
            }
            ctx.closePath();
            ctx.stroke();

            requestAnimationFrame(draw);
        }

        requestAnimationFrame(draw);

        if (window.ResizeObserver) {
            new ResizeObserver(updateSize).observe(container);
        } else {
            window.addEventListener('resize', updateSize);
        }
    }

    for (var i = 0; i < targets.length; i++) {
        initElectricBorder(targets[i]);
    }
})();
