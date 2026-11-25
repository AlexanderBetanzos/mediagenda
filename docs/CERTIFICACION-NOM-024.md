# Certificación NOM-024-SSA3-2012 — estado contra el plan (24/11/2026)

Plan base: "MediOS — Plan de certificación NOM-024-SSA3-2012" (PDF corregido, 24/11/2026).
Guías: **GIIS-B015-04-11** Consulta Externa (106 variables) + **GIIS-A004-01-07** SGSI (obligatoria).
Versión del SIRES a certificar: **MediOS 1.x** (`APP_VERSION` en config/config.php). El certificado solo cubre la versión mayor: no publicar una 2.0 sin decidirlo.
Referencia: SaludTotal se certificó en julio de 2026 con 76 de 106 variables (DGIS-CER-P-013-2026-07).
✅ hecho en el sistema · 🟡 hecho, falta un paso tuyo · ⬜ pendiente

## Identificación del paciente
| Punto del plan | Estado | Dónde |
|---|---|---|
| CURP como identificador, validada y nunca generada | ✅ | Formato + dígito verificador, aviso de duplicados; el sistema nunca la genera |
| Campos mínimos Tabla 1 | ✅ | Nombre, primer y segundo apellido, nacimiento, sexo, entidad de nacimiento (INEGI), nacionalidad; aviso si faltan |
| Pacientes sin CURP | ✅ | Motivo obligatorio (extranjero, recién nacido, no la tiene, urgencia) |

## Catálogos
| Punto | Estado | Dónde |
|---|---|---|
| CIE-10 con búsqueda en la nota | ✅ | 12,551 claves SSA |
| CLUES en la configuración | ✅ | Configuración + membrete |
| Entidades, municipios y localidades INEGI | 🟡 | Entidades integradas; **sube municipios y localidades** en /platform/catalogos |
| Catálogo de medicamentos | 🟡 | Estructura lista; **sube el Compendio** en /platform/catalogos (el catálogo propio de recetas sigue funcionando) |
| Proceso de actualización de catálogos | ✅ | docs/sgsi/12 + versión/fecha/responsable en /platform/catalogos |

## Intercambio (GIIS-B015)
| Punto | Estado | Dónde |
|---|---|---|
| Descargar la GIIS-B015-04-11 y su diccionario (106 variables) | ⬜ tuyo | DGIS |
| Mapear cada variable a campos de MediOS | 🟡 | /platform/giis: sube el diccionario (CSV) y asigna |
| Archivo de intercambio TXT | ✅ | Expediente → Reporte DGIS |
| Archivo de intercambio CIF | ⬜ | Se implementa con la especificación de la guía (no se inventa) |
| Validación en el Módulo de Carga Masiva (SINBA) | ⬜ tuyo | Pedir usuario a dgis@salud.gob.mx / soporte.sinba@salud.gob.mx (CURP, nombre, correo, entidad, GIIS); registrar cada carga exitosa en /platform/sgsi → "Carga de prueba en SINBA". La semana previa a la verificación se envía esa evidencia |
| Validaciones (obligatoriedad, longitud) | ✅ | Revisión previa con observaciones. Rangos y combinaciones: se agregan al conocer la guía |

## Expediente y registros
| Punto | Estado |
|---|---|
| Notas inalterables; corrección = nota nueva ligada | ✅ notas aclaratorias + sello SHA-256 encadenado |
| e.firma del médico en notas y recetas | ✅ (falta sello de tiempo NOM-151, opcional) |
| Consentimiento del paciente para exportar/compartir | ✅ con fecha y quién lo registró |

## Seguridad técnica
| Punto | Estado |
|---|---|
| Usuario por persona, roles | ✅ (permisos finos por módulo: ⬜ opcional) |
| Bitácora de consulta/creación/modificación | ✅ sellada e inalterable, con verificación |
| Cifrado en tránsito | ✅ HTTPS |
| Cifrado en reposo | 🟡 llaves, e.firma, 2FA y respaldos cifrados; BD y archivos dependen de Hostinger (confirmar en doc. 09) |
| Respaldos con prueba de restauración | ✅ diario cifrado + verificación registrada; 🟡 copia externa semanal y restauración real trimestral (tú) |
| Cierre por inactividad y política de contraseñas | ✅ |
| 2FA personal y consola | ✅ |

## SGSI (lo que marca el calendario: 6 meses)
Documentos listos para llenar, firmar y fechar en `docs/sgsi/` (se leen en /platform/sgsi):
política, alcance y roles, inventario, riesgos, accesos, respaldos, incidentes, NDA, revisión de hosting, minuta, cambios, catálogos.

**Tu siguiente paso (este mes):**
1. Llenar los corchetes y firmar la política (doc. 01) y los NDA (doc. 08) de cada persona con acceso.
2. Registrar en /platform/sgsi la **primera minuta**: ese día empieza a contar la madurez.
3. Activar 2FA en /platform/cuenta y pedírselo a tu equipo.
4. Mandar a Hostinger las preguntas del doc. 09 y archivar la respuesta.
5. Poner `APP_KEY` en `config/secrets.php` y guardar copia offline.
6. Configurar el cron horario (`--cada-hora`) para respaldos y sellado de bitácora.
7. Pedir a la DGIS el paquete informativo (borrador en el PDF) cuando el SGSI lleve ~4 meses.

## Documentos legales (pre-revisión y cotejo en original)
Decidir primero el **titular** (tú como persona física o BETASYD como persona moral): INDAUTOR, solicitud y certificado deben ir a su nombre.

| Documento | Estado |
|---|---|
| Identificación oficial vigente (del representante legal con poder, si es persona moral) | ⬜ |
| Acta constitutiva (solo persona moral) | ⬜ |
| Constancia de Situación Fiscal actualizada | ⬜ |
| Opinión de cumplimiento SAT 32-D en positivo | ⬜ |
| Comprobante de domicilio (≤ 2 meses) | ⬜ |
| **Registro de derechos de autor de MediOS ante INDAUTOR** (el más lento: tramitar ya) | ⬜ |
| Documento de derechos de modificación y comercialización (si aplica) | ⬜ |
| Carta poder con 2 testigos (si no asiste el representante legal) | ⬜ |

## Etapas y calendario
1. SGSI operando: **enero 2027** (primera minuta en /platform/sgsi).
2. Paquete informativo (oficio en Oficialía de Partes, Homero 213, CDMX): **mayo 2027**, con ~4 meses de SGSI. Guardar el acuse: de ahí corren 1 mes para la pre-revisión y 3 meses para la solicitud.
3. Pre-revisión documental (≤ 1 mes del acuse) y cotejo presencial (≥ 2 semanas antes de los 3 meses).
4. Solicitud formal: **julio 2027** (SGSI con 6 meses), con Anexo 1 "A"/"B" y Anexo 2 Declaración de Aplicabilidad. Respuesta ≤ 60 días hábiles; fechas inamovibles.
5. Pre-verificación (opcional, recomendada): 1–2 días en la DGIS, 2–8 personas, equipo e internet propios.
6. Verificación: 2–5 días presenciales; **nada se cambia durante la verificación** → congelar MediOS 1.x antes.
7. Segunda verificación si hay no conformidades (aviso entre el día hábil 30 y 60).
8. Certificado publicado por la DGIS (≤ 30 días hábiles). Vigencia 2 años.

**Presupuesto:** 3–4 viajes a CDMX de 2 personas que dominen el sistema y el SGSI (tú y tu primo).
