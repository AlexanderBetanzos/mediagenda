# MediAgenda (MediOS) frente a NOM-004 y NOM-024

> **Qué es y qué no es este documento.** Es una revisión técnica del código,
> actualizada el **24 de noviembre de 2026** (la primera fue el 13 de octubre de
> 2026). **No es un dictamen legal ni una certificación.** Solo la **DGIS**
> (Dirección General de Información en Salud) puede declarar que un sistema
> cumple la NOM-024, después de una evaluación de conformidad. Mientras no exista
> esa certificación, en el material de venta se dice "construido tomando como
> guía la NOM-004 y la NOM-024" y **nunca** "cumple" ni "certificado".

## 0. Para explicarlo a un cliente en 30 segundos

- **NOM-004-SSA3-2012** dice **qué** debe tener el expediente: identificación del
  paciente, historia clínica, notas con fecha, hora, nombre y firma, y que no se
  alteren. También dice que se conserve **5 años** y que sea confidencial.
- **NOM-024-SSA3-2012** dice **cómo** debe ser el **sistema** que lo guarda:
  catálogos oficiales (CIE-10, CLUES, CURP), control de acceso, bitácora,
  integridad, firma electrónica, respaldo e intercambio (HL7/CDA).
- **¿Es obligatoria la certificación?** Para sistemas públicos o que intercambian
  información con el sector público, en la práctica sí. Para software privado de
  consultorio no es un requisito de venta, pero es un argumento de confianza.
- **Qué decimos:** "MediAgenda se construyó tomando como guía la NOM-004 y la
  NOM-024. Aún no tiene la certificación de la DGIS." Esto también está en
  `/seguridad` y en `/ayuda/expediente-clinico`.

Leyenda: ✅ lo cubre · 🟡 en parte · ❌ no · 🆕 agregado el 24/11/2026

---

## 1. NOM-004 — Expediente clínico

| Requisito | Estado | Dónde está |
|---|---|---|
| Datos del establecimiento en los documentos | ✅🆕 | Membrete (`includes/comprobante.php`) con razón social, RFC, **CLUES**, dirección y teléfono |
| Identificación del paciente | ✅ | `pacientes/_form.php`: nombre, sexo, nacimiento, domicilio, CURP validada |
| Historia clínica (antecedentes) | 🟡 | Antecedentes personales, heredofamiliares, hábitos, cirugías, crónicas, alergias. Falta el interrogatorio por aparatos y sistemas estructurado (se cubre con plantillas) |
| Nota de evolución con signos, exploración, diagnóstico, **pronóstico** y tratamiento | ✅🆕 | `pacientes/ver.php` + columna `consultas.pronostico` |
| Fecha, hora, nombre y **firma** de quien elabora la nota | ✅🆕 | Fecha, hora y autor automáticos; **firma con e.firma** (`includes/ece.php`, `usuarios/efirma.php`) y línea de firma autógrafa al imprimir |
| Las notas no se alteran; correcciones en nota nueva | ✅🆕 | No hay edición ni borrado de consultas. **Notas aclaratorias** (`consulta_aclaraciones`) ligadas a la original |
| Conservación mínima de 5 años | 🟡 | No se puede eliminar un paciente con expediente. Falta la política escrita de qué pasa con los datos al cancelar la suscripción |
| Consentimiento informado | 🟡 | `consentimientos/` con firma. Faltan espacios para **dos testigos** |
| Referencia / interconsulta | 🟡 | Plantillas de documentos |
| Hospitalización (ingreso, evolución, egreso) | 🟡 | `hospitalizacion/`. Falta egreso estructurado completo |
| Resumen clínico | ✅🆕 | `expediente/imprimir.php?modo=resumen` |
| Confidencialidad | ✅ | Aislamiento por consultorio, roles; solo personal médico escribe, firma e imprime notas |

## 2. NOM-024 — Sistema de registro electrónico para la salud

| Requisito | Estado | Dónde está |
|---|---|---|
| Identificación inequívoca del paciente (CURP) | ✅ | Validación de formato y dígito verificador, aviso de duplicados (`pacientes/curp.php`) |
| Catálogo CIE-10 | ✅ | Catálogo oficial SSA (12,551 claves), buscador en el diagnóstico, código guardado en `consultas.cie10` |
| CLUES del establecimiento | ✅🆕 | Configuración → datos del consultorio; sale en el membrete |
| Catálogo de procedimientos (CIE-9-MC) | ❌ | Pendiente |
| Autenticación | ✅🆕 | Contraseña con hash bcrypt, **verificación en dos pasos (TOTP)** con códigos de respaldo, exigible por consultorio (`includes/totp.php`, `usuarios/seguridad.php`), **bloqueo por intentos fallidos** |
| Control de acceso por rol | ✅ | admin / médico / recepción; aislamiento por consultorio |
| Sesión segura | ✅ | Cookie HttpOnly, SameSite, Secure; regenerada al entrar; **cierre por inactividad** configurable |
| Bitácora de auditoría | ✅🆕 | `auditoria/index.php`: accesos, apertura de expedientes, altas, cambios, firmas, impresiones, borrados. **Registro automático de todo lo que se guarda** (`bitacora_auto()`). No se edita ni borra desde el sistema |
| Integridad de los registros | ✅🆕 | **Sello SHA-256 encadenado** por nota (`consultas.sello`, `sello_prev`); el expediente marca "Alterada" si no coincide |
| Firma electrónica avanzada | ✅🆕 | e.firma del SAT, RSA-SHA256 sobre el contenido y el sello; la `.key` cifrada (AES-256-GCM) y la contraseña nunca se guarda. Falta la **constancia de conservación NOM-151** (sello de tiempo de un tercero) |
| Cifrado en tránsito | ✅ | HTTPS |
| Cifrado en reposo | 🟡 | Certificados, e.firma y secretos 2FA cifrados (AES-256-GCM). La base de datos y los archivos clínicos dependen del hosting (Hostinger); no cifrados a nivel de campo |
| Respaldos y recuperación | 🟡 | Descarga ZIP por consultorio (`configuracion/exportar.php`). **Falta confirmar por escrito** la política de respaldos de Hostinger (frecuencia, retención, prueba de restauración) |
| Intercambio (HL7 CDA / guías DGIS) | ❌ | Pendiente; solo necesario para interoperar con instituciones |
| Certificación DGIS | ❌ | No iniciada |

## 3. Datos personales (LFPDPPP)

| Punto | Estado | Notas |
|---|---|---|
| Aviso de privacidad | ✅🆕 | `privacidad.php` actualizado (WhatsApp incluido, enlace a Seguridad) |
| Página pública de seguridad | ✅🆕 | `seguridad.php`: solo medidas reales, sin certificaciones inventadas |
| Consentimiento de datos sensibles del paciente | 🟡 | Lo recaba el consultorio; falta una plantilla lista en `consentimientos/` |
| Contrato de encargo consultorio ↔ plataforma | ❌ | Términos del servicio pendientes |
| Derechos ARCO | 🟡 | Exportación y corrección disponibles; falta el procedimiento escrito |

## 4. Receta (Reglamento de Insumos para la Salud)

| Elemento | Estado |
|---|---|
| Médico, cédula, especialidad, domicilio | ✅ |
| Fecha, firma, datos y alergias del paciente | ✅ |
| QR de verificación; medicamentos visibles solo con la fecha de nacimiento del paciente | ✅🆕 |

---

## 5. Lo que falta, por prioridad

**Para decir "listos para certificar"**
1. Política de respaldos de Hostinger confirmada por escrito y prueba de restauración documentada.
2. Términos del servicio con contrato de encargo y política de conservación al cancelar.
3. Exportación del expediente en **HL7 CDA** (resumen clínico) y catálogo CIE-9-MC.
4. Sello de tiempo NOM-151 para las firmas (proveedor certificado).
5. Solicitar la evaluación de conformidad ante la DGIS.

**Mejoras de contenido (NOM-004)**
6. Interrogatorio por aparatos y sistemas estructurado en la historia clínica.
7. Consentimiento con dos testigos y plantilla de consentimiento de datos sensibles.
8. Egreso hospitalario estructurado y hoja de enfermería imprimible con firma por turno.
