# Certificación NOM-024-SSA3-2012 — estado contra el plan (24/11/2026)

Plan base: "MediOS — Plan de certificación NOM-024-SSA3-2012" (PDF, 24/11/2026).
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
| Descargar guía y diccionario vigentes | ⬜ tuyo | DGIS |
| Mapear cada variable a campos de MediOS | 🟡 | /platform/giis: sube el diccionario (CSV) y asigna |
| Exportar en el formato de la guía | ✅ | Expediente → Reporte DGIS (archivo de intercambio) |
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
