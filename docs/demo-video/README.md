# Videos demo para prospectos

Animación en HTML con línea de tiempo fija (`render(t)`), grabada cuadro por
cuadro con Chrome sin interfaz y ffmpeg: sale fluida y siempre igual.

- Vista previa en vivo: abrir `bienvita.html?play` en Chrome (`?t=30` congela el segundo 30).
- Grabar (necesita Chrome y ffmpeg instalados):

```bash
cd docs/demo-video
npm i puppeteer-core@23.11.1
node grabar.mjs video demo_bienvita.mp4      # 74 s, 1920×1080, ~2 min
node grabar.mjs shots 12 24 36               # cuadros sueltos para revisar
```

Para otro prospecto: copia `bienvita.html`, cambia nombres y textos, ajusta los
tiempos (`data-in`, `data-out`, `CUR`) y graba con `DEMO=otro.html node grabar.mjs video`.
Todo va con la etiqueta "Datos de ejemplo".
