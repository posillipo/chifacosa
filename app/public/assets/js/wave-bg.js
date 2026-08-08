/*!
 * Sfondo "Wave Grid" per i temi CHI FA COSA (Wave / Wave Chiaro / Wave Neon) — ispirato/adattato da:
 * "3D Wave Grid" di franky-adl (https://github.com/franky-adl/3d-wave-grid)
 * Rilasciato con licenza MIT — Copyright (c) 2026 franky-adl
 * Versione semplificata e parametrizzabile per l'uso diretto via CDN (senza build tool).
 * I parametri (colori, forma, dimensione della trama) si passano tramite attributi
 * data-* sulla canvas, così lo stesso file serve più varianti di tema.
 */
(function () {
    if (typeof THREE === 'undefined') return; // Three.js non caricato: nessun effetto, nessun errore
    var canvas = document.getElementById('wave-bg-canvas');
    if (!canvas) return;

    try {
        var testCtx = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (!testCtx) return;
    } catch (e) { return; }

    var d = canvas.dataset;
    var accent = d.accent || '#6C5CE7';
    var baseColor = d.base || '#1a1a1a';
    var shape = d.shape || 'box'; // 'box' oppure 'cylinder', cambia la "trama" della griglia
    var gridSize = parseInt(d.gridSize || '22', 10);
    var cubeSize = parseFloat(d.cubeSize || '0.75');
    var gap = parseFloat(d.gap || '0.18');
    var cubeHeight = parseFloat(d.cubeHeight || '2.4');
    var lightIntensity = parseFloat(d.lightIntensity || '2.2');
    var ambientIntensity = parseFloat(d.ambientIntensity || '0.6');

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(40, window.innerWidth / window.innerHeight, 0.1, 100);
    var radius = 15;
    camera.position.set(0, radius, 0.1);
    camera.lookAt(0, 0, 0);

    var renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setClearColor(0x000000, 0);

    scene.add(new THREE.AmbientLight(0xffffff, ambientIntensity));
    var keyLight = new THREE.DirectionalLight(0xffffff, lightIntensity);
    keyLight.position.set(-10, 12, 6);
    scene.add(keyLight);

    var spacing = cubeSize + gap;
    var count = gridSize * gridSize;

    var geometry = shape === 'cylinder'
        ? new THREE.CylinderGeometry(cubeSize / 2, cubeSize / 2, cubeHeight, 8)
        : new THREE.BoxGeometry(cubeSize, cubeHeight, cubeSize);
    var offsetAttr = new THREE.InstancedBufferAttribute(new Float32Array(count * 2), 2);
    geometry.setAttribute('aOffset', offsetAttr);

    var material = new THREE.MeshPhongMaterial({ color: 0x222222 });
    var uniforms = {
        uTime: { value: 0 },
        uWavePos: { value: new THREE.Vector2(9999, 9999) },
        uWaveStart: { value: -10 },
        uColorBase: { value: new THREE.Color(baseColor) },
        uColorHigh: { value: new THREE.Color(accent) },
    };

    material.onBeforeCompile = function (shader) {
        Object.assign(shader.uniforms, uniforms);
        shader.vertexShader = shader.vertexShader
            .replace('#include <common>', '#include <common>\n' +
                'varying float vHeight;\n' +
                'attribute vec2 aOffset;\n' +
                'uniform float uTime;\n' +
                'uniform vec2 uWavePos;\n' +
                'uniform float uWaveStart;\n')
            .replace('#include <begin_vertex>', '#include <begin_vertex>\n' +
                'vHeight = 0.0;\n' +
                'if (position.y > 0.0) {\n' +
                '  float dist = distance(aOffset, uWavePos);\n' +
                '  float age = uTime - uWaveStart;\n' +
                '  float wavefront = age * 7.0;\n' +
                '  float rel = dist - wavefront;\n' +
                '  float envelope = exp(-(rel * rel) / 4.0) * exp(-age * 0.6);\n' +
                '  float ambient = sin(uTime * 0.7 + aOffset.x * 0.35 + aOffset.y * 0.35) * 0.08;\n' +
                '  float h = clamp(envelope * 0.9, 0.0, 1.0) * cos(rel * 1.1) * 0.5 + ambient;\n' +
                '  transformed.y += h;\n' +
                '  vHeight = h;\n' +
                '}\n');
        shader.fragmentShader = shader.fragmentShader
            .replace('#include <common>', '#include <common>\n' +
                'varying float vHeight;\n' +
                'uniform vec3 uColorBase;\n' +
                'uniform vec3 uColorHigh;\n')
            .replace('#include <color_fragment>', '#include <color_fragment>\n' +
                'float t = clamp(vHeight / 0.5, 0.0, 1.0);\n' +
                'diffuseColor.rgb = mix(uColorBase, uColorHigh, t);\n');
    };

    var mesh = new THREE.InstancedMesh(geometry, material, count);
    var dummy = new THREE.Object3D();
    var offset = ((gridSize - 1) * spacing) / 2;
    var idx = 0;
    for (var i = 0; i < gridSize; i++) {
        for (var j = 0; j < gridSize; j++) {
            var x = i * spacing - offset;
            var z = j * spacing - offset;
            dummy.position.set(x, 0, z);
            dummy.updateMatrix();
            mesh.setMatrixAt(idx, dummy.matrix);
            offsetAttr.setXY(idx, x, z);
            idx++;
        }
    }
    mesh.instanceMatrix.needsUpdate = true;
    scene.add(mesh);

    var lastWaveX = 9999, lastWaveZ = 9999;
    var bounds = gridSize * spacing * 0.5;
    window.addEventListener('mousemove', function (e) {
        var nx = (e.clientX / window.innerWidth) * 2 - 1;
        var nz = (e.clientY / window.innerHeight) * 2 - 1;
        var wx = nx * bounds;
        var wz = nz * bounds;
        if (Math.hypot(wx - lastWaveX, wz - lastWaveZ) > 1.2) {
            uniforms.uWavePos.value.set(wx, wz);
            uniforms.uWaveStart.value = clock.getElapsedTime();
            lastWaveX = wx; lastWaveZ = wz;
        }
    });

    window.addEventListener('resize', function () {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
    });

    var clock = new THREE.Clock();
    function animate() {
        requestAnimationFrame(animate);
        uniforms.uTime.value = clock.getElapsedTime();
        renderer.render(scene, camera);
    }
    animate();
})();
