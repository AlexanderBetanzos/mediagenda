# Revisión técnica: MediOS frente a NOM-004 y NOM-024

> **Qué es y qué no es este documento.** Es una revisión técnica del código de
> MediOS hecha el 13 de octubre de 2026 contra los puntos principales de las
> normas. **No es un dictamen legal ni una certificación.** Antes de anunciar
> “cumplimiento” en la landing o en contratos, que lo revise un abogado o
> consultor en regulación sanitaria y se confirme la versión vigente de cada
> norma en el DOF. Mientras tanto, no se debe decir en el material de venta que
> MediOS “cumple la NOM-004/NOM-024”.

Normas consideradas:

- **NOM-004-SSA3-2012**, Del expediente clínico.
- **NOM-024-SSA3-2012**, Sistemas de información de registro electrónico para la
  salud (SIRES). Hay que confirmar si ya se publicó una versión que la sustituya.
- **Protección de datos personales.** Los datos de salud son *datos sensibles*. En
  2025 se publicó una nueva Ley Federal de Protección de Datos Personales en
  Posesión de los Particulares y la autoridad cambió. Hay que verificar
  obligaciones y autoridad vigentes. El **consultorio es el responsable**; MediOS
  actúa como **encargado**.
- Para recetas: el **Reglamento de Insumos para la Salud** (requisitos de la receta).

Leyenda: ✅ lo cubre · 🟡 lo cubre en parte · ❌ no lo cubre · 🔧 corregido en esta revisión

---

## 1. NOM-004 — Expediente clínico

| Requisito (resumen) | Estado | Dónde está en MediOS | Qué falta |
|---|---|---|---|
| Datos del establecimiento en los documentos (nombre, domicilio) | 🟡 | Membrete en receta y comprobante (`includes/comprobante.php`) con nombre, razón social, RFC, dirección y teléfono de Configuración | Solo aparece si el consultorio los captura. Conviene pedirlos durante el arranque (primeros pasos) |
| Identificación del paciente (nombre, sexo, edad, domicilio) | ✅ | Alta de paciente (`pacientes/_form.php`): nombre, sexo, fecha de nacimiento, dirección, CURP, contacto de emergencia | — |
| Historia clínica: antecedentes heredofamiliares, personales patológicos y no patológicos | 🟡 | Campos `antecedentes`, `antecedentes_familiares`, `habitos`, `cirugias`, `enf_cronicas`, `alergias`, `tipo_sangre` | No hay interrogatorio por aparatos y sistemas ni padecimiento actual estructurados (se pueden capturar con plantillas de consulta) |
| Nota de evolución: signos vitales, exploración, diagnóstico, tratamiento | 🟡 | Consulta en la ficha (`pacientes/ver.php`, tabla `consultas`): motivo, exploración, diagnóstico, tratamiento, peso, talla, TA, temperatura, glucosa, FC, FR, SpO₂ | **Falta el pronóstico**, que la norma pide en historia clínica, notas de evolución y egreso |
| Fecha, hora, nombre completo y firma de quien elabora cada nota | 🟡 | Cada consulta guarda fecha y hora y el médico (`medico_id`) | **No hay firma**: ni línea de firma autógrafa al imprimir la nota ni firma electrónica |
| Las notas no se alteran; las correcciones van en una nota nueva | ✅ | No existe edición ni borrado de consultas en la interfaz (no hay `UPDATE`/`DELETE` sobre `consultas`) | Documentarlo como política. Si algún día se permite corregir, que sea con una nota nueva que apunte a la anterior |
| Conservación mínima de 5 años | 🔧 | **Antes:** recepción podía eliminar un paciente y, en cascada, se borraban sus consultas. **Ahora** (`pacientes/delete.php`) no se puede eliminar a un paciente con consultas, recetas u hospitalizaciones; queda en la bitácora como `borrar_bloqueado` | Revisar también qué pasa con los datos cuando un consultorio cancela su suscripción (hoy el aviso de privacidad pide descargarlos antes) |
| Consentimiento informado | 🟡 | Módulo `consentimientos/` con plantillas, membrete y firma | Revisar contra el contenido mínimo de la norma: acto autorizado, riesgos y beneficios, autorización para atender contingencias, firmas del paciente o su representante, del médico y **de dos testigos** (no hay espacios para testigos) |
| Referencia / interconsulta | 🟡 | Plantillas de documentos (p. ej. “Referencia a especialista”) | No hay un formato propio con los datos mínimos (motivo de envío, impresión diagnóstica, terapéutica empleada) |
| Hospitalización: notas de ingreso, evolución y egreso | 🟡 | Módulo nuevo `hospitalizacion/`: ingreso (motivo, diagnóstico, cama, médico), notas de evolución con signos y alta con resumen | Falta un **egreso estructurado**: fechas de ingreso y egreso, motivo del egreso, diagnósticos finales, resumen de la evolución, pronóstico, problemas pendientes, plan y recomendaciones |
| Hoja de enfermería: ministración de medicamentos (fecha, hora, cantidad, vía) y quién la hizo | 🟡 | Kardex: cada dosis queda como aplicada u omitida, con quién y cuándo; impresión del kardex | Falta nombre completo y **firma** del personal en la impresión, y signos por turno en formato de hoja de enfermería |
| Resumen clínico para el paciente o una autoridad que lo pida | ❌ | El portal muestra citas, recetas, vacunas y estudios | No hay un “resumen clínico” imprimible |
| Confidencialidad y acceso restringido | 🟡 | Roles (admin, médico, recepción); cada consultorio solo ve lo suyo | Recepción ve la ficha completa. Evaluar si debe ver las notas clínicas |

## 2. NOM-024 — Sistemas de registro electrónico para la salud (SIRES)

| Requisito (resumen) | Estado | Dónde está en MediOS | Qué falta |
|---|---|---|---|
| Identificación inequívoca del paciente (CURP) | 🟡 | Campo CURP de 18 caracteres | No se valida el formato ni se avisa de duplicados |
| Catálogos fundamentales: CIE-10 (diagnósticos), CIE-9-MC (procedimientos), CLUES (establecimiento) | ❌ | Diagnóstico en texto libre | Buscador CIE-10 en el diagnóstico (guardando código y texto); campo CLUES en Configuración |
| Autenticación y control de acceso por rol | ✅ | Cuenta por persona, contraseña con hash (`password_hash`), roles, sesión regenerada al entrar | Recuperación de contraseña agregada en esta ronda (`auth/recuperar.php`, enlace de un solo uso que vence en 1 h) |
| Cookies de sesión seguras | 🔧 | Ahora la cookie de sesión es `HttpOnly`, `SameSite=Lax` y `Secure` en HTTPS (`includes/functions.php`) | Cerrar la sesión tras un tiempo sin actividad (hoy no hay límite) |
| Bitácora de auditoría (accesos y cambios) | 🟡🔧 | Tabla `auditoria` con usuario, acción, IP y navegador: inicios de sesión, altas, borrados, cobros, abonos, hospitalización. **Ahora** también registra quién abre un expediente (`ver` paciente, una vez cada 30 min por persona) | No hay pantalla para consultar la bitácora; no se registran todas las ediciones de la ficha con el valor anterior |
| Integridad de los registros | 🟡 | Las notas no se editan | No hay sello de tiempo ni hash por nota (lo que daría la firma electrónica) |
| Firma electrónica avanzada (e.firma) en notas y recetas | ❌ | — | Requiere integrar e.firma (FIEL) o un proveedor de firma; es la pieza más costosa |
| Cifrado en tránsito | ✅ | El sitio en producción usa HTTPS | Mantener la redirección forzada a HTTPS |
| Cifrado en reposo | ❌ / sin verificar | — | Depende del hosting (Hostinger). Evaluar cifrar campos clínicos sensibles |
| Respaldos y recuperación | 🟡 | Descarga de datos en ZIP para el consultorio (`configuracion/exportar.php`) | Confirmar la política de respaldos de la base en Hostinger (frecuencia, retención, prueba de restauración) y documentarla |
| Intercambio de información (HL7 CDA / guías de la DGIS) | ❌ | — | Solo si se busca interoperar con instituciones. No es prioritario para consultorios privados |
| Certificación del sistema ante la autoridad (DGIS) | ❌ / sin verificar | — | Confirmar si hoy aplica la certificación de SIRES y cuál es el trámite vigente |

## 3. Datos personales

| Punto | Estado | Notas |
|---|---|---|
| Aviso de privacidad | 🟡 | Existe `privacidad.php`. Revisarlo contra la ley de 2025 y la autoridad vigente |
| Consentimiento expreso para datos sensibles (salud) | ❌ | El consultorio debe recabarlo de sus pacientes. Se puede ofrecer una plantilla de consentimiento de tratamiento de datos en `consentimientos/` |
| Contrato de encargo consultorio ↔ MediOS | ❌ | Los términos del servicio deben decir que MediOS trata los datos por cuenta del consultorio, con qué medidas de seguridad y qué pasa con los datos al terminar |
| Derechos ARCO | 🟡 | El consultorio puede exportar y corregir datos. Falta un procedimiento documentado |

## 4. Receta (Reglamento de Insumos para la Salud)

| Elemento | Estado |
|---|---|
| Nombre del médico, cédula profesional y especialidad | ✅🔧 La receta impresa ahora muestra la cédula (si está capturada en el perfil del médico) |
| Domicilio del consultorio | 🟡 Va en el membrete si se capturó en Configuración |
| Institución que expidió el título | ❌ No hay campo (confirmar si se exige en la versión vigente) |
| Fecha y firma autógrafa | ✅ Fecha y línea de firma con nombre y cédula |
| Datos del paciente | ✅ Nombre, edad y **alergias** (nuevo) |

---

## 5. Plan sugerido, por prioridad

**Prioridad 1: poco esfuerzo, mucho valor**
1. Campo **pronóstico** en la consulta y en el alta hospitalaria.
2. **Egreso hospitalario estructurado** (diagnósticos finales, motivo del egreso, resumen, pronóstico, pendientes, plan).
3. **Nota clínica imprimible** con membrete, nombre, cédula y línea de firma; y **resumen clínico** del paciente.
4. **Cierre de sesión por inactividad** (p. ej. 30 min) en el panel.
5. Pantalla de **bitácora** para el administrador (quién vio o cambió qué).
6. Pedir los datos del establecimiento (dirección, cédula de cada médico) en “Primeros pasos”.

**Prioridad 2**
7. Buscador **CIE-10** en el diagnóstico (código + texto) y campo **CLUES**.
8. Validación de **CURP** y aviso de pacientes duplicados.
9. Consentimiento informado con **testigos** y contenido mínimo; plantilla de **consentimiento de datos sensibles**.
10. Hoja de enfermería imprimible desde el kardex, con firma por turno.
11. Restringir a recepción la vista de notas clínicas (opcional por consultorio).

**Prioridad 3: proyecto mayor**
12. **Firma electrónica** (e.firma) para notas y recetas.
13. Cifrado en reposo de campos clínicos.
14. Exportación HL7 CDA y evaluación de la certificación SIRES.

## 6. Lo que se corrigió junto con esta revisión

- No se puede eliminar a un paciente que tenga expediente clínico (`pacientes/delete.php`).
- Bitácora de apertura de expedientes (`pacientes/ver.php` → `auditar('ver', 'paciente', …)`).
- Cookie de sesión `HttpOnly` + `SameSite=Lax` + `Secure` en HTTPS.
- Recuperación de contraseña del personal con enlace de un solo uso.
- Receta con cédula, alergias del paciente y membrete del consultorio.
