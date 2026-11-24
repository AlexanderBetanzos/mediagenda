# Datos de referencia

## cie10_mx.csv

Catálogo CIE-10 (diagnósticos) para el buscador de diagnósticos.

- **Fuente:** "Catálogo CIE-10", Secretaría de Salud, publicado por el Hospital
  Juárez de México en datos.gob.mx — https://www.datos.gob.mx/dataset/catalogo_cie_10
- **Licencia:** Creative Commons Atribución 4.0 (CC BY 4.0). El buscador muestra
  el crédito en cada búsqueda.
- **Cambios respecto al original (permitidos por la licencia):**
  - Solo las columnas de clave y nombre.
  - Solo los códigos vigentes para codificar (`VALID = SI`); se quitan los
    encabezados de categoría que tienen subcategorías.
  - Clave con punto (`J029` → `J02.9`); las de 3 caracteres con relleno `X`
    quedan sin él (`I10X` → `I10`).
  - Nombres en minúsculas normales (venían en MAYÚSCULAS), respetando siglas
    (VIH, COVID-19…), epónimos (Parkinson, Alzheimer…) y géneros de
    microorganismos (Vibrio, Salmonella…).

La primera búsqueda en el sistema lo importa a la tabla `cie10` (includes/cie10.php).
