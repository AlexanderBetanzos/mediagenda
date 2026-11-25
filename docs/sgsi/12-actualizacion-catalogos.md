# 12. Proceso de actualización de catálogos oficiales

**Responsable:** [nombre] · **Frecuencia de revisión:** trimestral, y cada vez que la DGIS/INEGI anuncie una versión.

| Catálogo | Fuente | Dónde se carga |
|---|---|---|
| CIE-10 | DGIS / SSA (datos abiertos) | sql/data/cie10_mx.csv (módulo CIE-10) |
| Entidades | INEGI (claves 01–32) | Integrado en el código |
| Municipios y localidades | INEGI, Catálogo Único de Claves (AGEEML) | /platform/catalogos |
| CLUES | DGIS | /platform/catalogos |
| Medicamentos | Compendio Nacional de Insumos para la Salud (CSG) | /platform/catalogos |
| Procedimientos (CIE-9-MC) | DGIS | /platform/catalogos |

## Pasos
1. Revisar en la fuente si hay versión nueva (anotar fecha de publicación).
2. Descargar el archivo oficial y guardarlo en el repositorio documental con su fecha.
3. Cargarlo en /platform/catalogos indicando la versión tal como la nombra la fuente.
4. Verificar el número de registros contra la fuente y probar una búsqueda.
5. Registrar en /platform/sgsi como "Cambio relevante al sistema" (catálogo, versión, registros).
6. Si la GIIS cambia de versión, actualizar el diccionario y el mapeo en /platform/giis.
