# Librerie/codice di terze parti usati nel progetto

## Effetto sfondo "Wave Grid" (tema pagina pubblica "Wave")

File: `app/public/assets/js/wave-bg.js`

Adattato e semplificato a partire dal progetto open source **"3D Wave Grid"** di franky-adl:
https://github.com/franky-adl/3d-wave-grid

```
MIT License

Copyright (c) 2026 franky-adl

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

**Cosa è stato modificato rispetto all'originale**: la versione usata in CHI FA COSA è una
riscrittura semplificata a file singolo (nessun bundler/build tool, pensata per essere caricata
via CDN), con griglia più piccola (22x22 invece di 40x40), un solo punto d'onda basato sulla
posizione corrente del mouse invece di una scia storica multi-punto, senza post-processing
(vignette/RGB shift) né pannello di debug.

## Three.js

Libreria caricata da CDN (jsdelivr) per l'effetto sopra — licenza MIT, progetto:
https://github.com/mrdoob/three.js
