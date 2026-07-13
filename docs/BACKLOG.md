# Backlog & Roadmap — MediAgenda

> Documento maestro de producto. Organiza **todo** lo que vamos a construir, en
> qué **fase**, a qué **plan** pertenece y de qué **depende técnicamente**.
> Se edita en cada sesión. Estado: `✅ hecho` · `🟡 parcial` · `⬜ pendiente`.

Visión: pasar de "citas + expediente" a ser el **centro operativo** del
consultorio/clínica (estilo DrChrono / SimplePractice → Athenahealth → Epic),
vendido como SaaS multi-tenant white-label, por **capas/planes**.

---

## 1. Estado actual (lo que ya corre)

| Área | Estado | Notas |
|---|---|---|
| Auth + roles (admin/médico/recepción) + superadmin | ✅ | `require_role`, `has_role` |
| SaaS multi-tenant (aislamiento por `consultorio_id`) | ✅ | `tenant_id()`, anti cross-tenant |
| Registro + prueba 15 días + gating de suscripción | ✅ | `tenant_bloqueado()` |
| Pagos recurrentes (Mercado Pago) | ✅ | `planes_mp()`, webhook |
| Dashboard | 🟡 | Básico, sin KPIs configurables |
| Configuración white-label (marca, color, tema, regional) | ✅ | tabla `configuracion` vía `cfg()` |
| Modo oscuro / claro / auto | ✅ | `tema_actual()` |
| Pacientes (alta/edición/listado) | ✅ | |
| Agenda y citas | 🟡 | CRUD + estados; **falta** drag&drop, recurrentes, vista calendario |
| Expediente clínico + archivos adjuntos | ✅ | consultas, signos vitales, archivos seguros |
| Catálogo de servicios (precios y duración) | ✅ | tabla `servicios`, `servicios/` |
| Presupuestos / planes de tratamiento + abonos | ✅ | `presupuestos/`, saldo por paciente, imprimible |
| Recetas | 🟡 | Cabecera + ítems; **falta** catálogo, firma, QR |
| Facturación | 🟡 | Folio interno; **falta** CFDI/SAT real |
| Reportes | 🟡 | Solo `index`, sin BI |
| Usuarios (gestión de personal) | ✅ | |

**Brechas estructurales que bloquean el resto del roadmap** → ver §3.

---

## 2. Modelo de planes (base para los cobros)

3 niveles. Precios *propuestos* (MXN/mes, ajustables). Hoy existen 2 planes
hardcodeados en `planes_mp()` (Estándar $299 / Premium $599); esto los reemplaza.

| Plan | Precio aprox. | Para quién | Incluye (resumen) |
|---|---|---|---|
| **Básico** | $299 | Médico solo / consultorio chico | Núcleo: citas, expediente, recetas, facturación simple, recordatorios por correo |
| **Profesional** | $599 | Consultorio en crecimiento | Todo Básico + WhatsApp, portal del paciente, telemedicina básica, reportes, plantillas por especialidad |
| **Clínica** | $1,199 | Clínica / multi-médico / multi-sucursal | Todo Profesional + farmacia/POS, laboratorio, multi-sucursal, IA clínica, RH, CFDI/SAT, integraciones avanzadas |

> Regla de oro: **cada función nueva nace etiquetada con su plan** (columna
> "Plan" en las tablas de fases). Sin la infraestructura de entitlements (§3.1)
> el gating no es real, así que ese es el primer entregable.

Códigos usados abajo: **B** = Básico · **P** = Profesional · **C** = Clínica
· **Add-on** = se puede vender suelto.

---

## 3. Cimientos técnicos (PRIMERO — habilitan todo lo demás)

### 3.1 Entitlements / módulos por plan ✅ — *cimiento listo*
Implementado en `sql/planes.sql` + helpers en `functions.php`. El plan ahora
gatea **módulo por módulo** (antes solo bloqueaba el acceso completo).

Tablas:
```
planes              (clave, nombre, precio, descripcion, items, destacado, mp_plan_id, orden, activo)
modulos             (clave, nombre, fase, orden)
plan_modulos        (plan_clave, modulo_clave)            -- qué incluye cada plan
consultorio_modulos (consultorio_id, modulo_clave, activo) -- overrides/add-ons puntuales
```

Helpers (en uso):
```php
modulo_activo('telemedicina'): bool   // ¿el tenant actual tiene el módulo?
require_modulo('farmacia');           // redirige a /pagos/index si no está en su plan
modulos_activos();                    // lista (o ['*'] = acceso total)
```
`planes_mp()` es ahora la única fuente de verdad de precios (lee de `planes`);
el landing, registro, suscripción y pagos consumen de ahí. El nav
(`includes/header.php`) oculta los módulos no contratados.

**Fail-open**: súper-admin, prueba vigente, plan sin mapeo o tablas ausentes →
acceso total, para no bloquear a nadie por accidente.

✅ Pantalla de súper-admin para asignar plan y add-ons por consultorio:
`admin/consultorio.php` (cambia `consultorios.plan` y gestiona overrides en
`consultorio_modulos` con semántica de solo-deltas). Enlace "Plan y módulos"
en `admin/index.php`.

✅ `require_modulo()` aplicado en cada controlador, no solo en el menú: los
índices de módulo y también sus sub-acciones (`create`/`edit`/`delete`/
`estado`/`feed`/`mover`/`ver`), para que un POST directo no salte el plan.
El portal del paciente se gatea en `require_paciente()` y en el login
(`modulo_activo_en()`, porque ahí aún no hay sesión ni tenant).

Verificación en producción: `sql/verificar_planes.sql` (solo lectura). Detecta
planes sin módulos mapeados y consultorios con plan desconocido, que hacen
*fail-open* y abren todo, y lista los overrides manuales por consultorio.

Cuidado al probar: con `estado='trial'` el código devuelve `'*'` y todo se ve
abierto. Los gates solo se notan con un consultorio fuera de prueba.

### 3.2 Seguridad y cumplimiento (México) 🟡
- ✅ **Auditoría / logs de actividad**: tabla `auditoria` + helper `auditar()`.
  Registra login/logout/login_fallido, 2FA y altas/ediciones/borrados de
  paciente, consulta y archivos. Pantalla `admin/auditoria.php` (admin ve su
  consultorio; súper-admin, todos) con filtros y paginación.
- ✅ **2FA (TOTP)**: `includes/totp.php` (RFC 6238, sin librerías), pantalla
  `auth/seguridad.php` (activar con QR / desactivar con contraseña) y reto
  `auth/2fa.php` en el login. Compatible con Google Authenticator/Authy.
- ✅ Cobertura ampliada: ahora también auditan recetas, facturas (crear/
  borrar/cambio de estado), personal (alta/edición/activar) y configuración.
- ⬜ Falta: recuperación de 2FA (reset por admin); **cifrado** de datos
  sensibles en reposo + respaldos automáticos; control de acceso por IP.
- **NOM-024-SSA3-2012** (expediente clínico electrónico): estructura mínima,
  firma, integridad. Marca el diseño del expediente "inteligente" (Fase 1+).
- **LFPDPPP**: aviso de privacidad, consentimiento, derechos ARCO.
- **CFDI 4.0 / SAT** y **COFEPRIS** (recetas de controlados) para sus módulos.

### 3.3 Plataforma transversal ⬜
- Servicio de **notificaciones** unificado (correo ✅ / WhatsApp / SMS) con cola.
- **Plantillas** reutilizables (consulta, mensajes, documentos).
- **Generador de PDF** con membrete del tenant (recetas, certificados, facturas).
- **API interna / webhooks** para portal paciente, app móvil e integraciones.
- 🟡 **Internacionalización (i18n)**: cimiento listo — helper `t()`/`et()`
  (el español es la clave, `lang/en.php` traduce), `idioma_actual()` (cookie
  `lang` o `cfg('idioma_default')`), selector ES/EN en el menú y en el login,
  HTML `lang` dinámico. ✅ **Sistema completo traducido (ES/EN)**: nav, login,
  dashboard, pacientes, citas (lista/calendario/sala/horarios), expediente,
  recetas, facturación, inventario, CRM, configuración, usuarios, reportes,
  súper-admin, portal del paciente, plantillas y auth (registro/suscripción/
  seguridad/2FA). `lang/en.php` ~400 términos. ⬜ Falta: pulir frases largas
  de marketing del registro y los nombres de plan que viven en la tabla `planes`.

---

## 4. Fase 1 — MVP comercial (vender rápido)

Objetivo: que un consultorio pague hoy. Completa el núcleo + comunicación.

| Módulo | Qué incluye | Plan | Depende de | Estado |
|---|---|---|---|---|
| Agenda pro | ✅ Vista día/semana/mes (FullCalendar) + **drag&drop** reagendar · ✅ **citas recurrentes** (semanal/quincenal/mensual) · ✅ **horarios por médico** + **bloqueos** (`citas/horarios.php`, bloqueos como franjas de fondo en el calendario). ⏳ Falta: validar choques contra horario/bloqueo al agendar, duración por defecto por médico | B | — | 🟢 |
| Flujo de sala | ✅ Estados (esperando/en consulta/atendida/…), **check-in**, tablero del día (`citas/sala.php`), tiempo de espera y promedio. ⬜ Falta: QR de llegada, turnos digitales, pantallas en sala | P | Agenda pro | 🟢 |
| Recordatorios | ✅ **Automáticos por correo** (cron `cron/recordatorios.php`, citas de mañana, idempotente, toggle en config) · ✅ **WhatsApp click-to-chat** (`wa.me` por cita). ⬜ Falta: WhatsApp/SMS automáticos por API (Twilio/Meta), confirmación bidireccional, lista de espera | B (correo) / P (WhatsApp/SMS) | 3.3 notificaciones | 🟢 |
| Expediente inteligente | ✅ Identificación (CURP/RFC/INE/tipo sangre), contacto de emergencia, antecedentes (personales/familiares/cirugías/vacunas/crónicas/hábitos/alergias), **IMC automático** (de la última consulta), fotos/PDF/estudios ✅. ⬜ Falta: medicamentos actuales estructurados, gráficas de signos vitales | B | 3.2 NOM-024 | 🟡 |
| Consulta avanzada | ✅ **Plantillas de consulta** (formatos reutilizables que pre-llenan el expediente, `plantillas/`), diagnósticos/tratamientos ✅. ⬜ Falta: plantillas por especialidad, notas rápidas, órdenes médicas, interconsultas, firma digital | B | Expediente | 🟡 |
| Recetas electrónicas | Catálogo de medicamentos, recetas favoritas, dosis automáticas, firma electrónica, QR, reimpresión, historial | B (básico) / P (firma+QR) | 3.3 PDF | 🟡 |
| Catálogo de servicios | ✅ Procedimientos con precio, duración por defecto y bandera "se cotiza por pieza dental" (`servicios/`). ⬜ Falta: usar `duracion_min` para pre-llenar la cita, importar/exportar catálogo | B | — | 🟢 |
| Presupuestos / plan de tratamiento | ✅ Folio por año y por consultorio, conceptos con **diente (FDI) y caras**, descuento, estados (borrador→propuesto→aceptado→terminado / rechazado / cancelado), avance por procedimiento realizado, **abonos** con saldo y estado de cuenta, documento **imprimible** con membrete y firmas (`presupuestos/`). ⬜ Falta: ligar el item a la cita que lo ejecuta, generar factura desde el presupuesto, firma del paciente en tablet | B | Catálogo de servicios | 🟢 |
| Facturación + caja | Métodos de pago, caja, cortes, pagos parciales, cuentas por cobrar; **CFDI/SAT** | B (simple) / C (CFDI) | 3.2 SAT | 🟡 |
| Cobro en línea al paciente | ✅ Credenciales de Mercado Pago **por consultorio** (`configuracion/`, el dinero cae en su cuenta) · ✅ **link de pago** por presupuesto con token público (`pago/`), Checkout Pro, webhook idempotente que verifica importe y registra el abono solo. ⬜ Falta: cobro desde el portal del paciente, pago de facturas, reembolsos | P | Presupuestos | 🟢 |
| Portal del paciente | ✅ Acceso propio (sesión separada), ver citas próximas/historial, **reagendar/cancelar** sus citas (`portal/cita.php`), ver/imprimir recetas, descargar estudios. Provisión desde `pacientes/ver`. ⬜ Falta: pagos, chat/video, auto-registro por token | P | 3.3 API | 🟢 |
| Dashboard | KPIs configurables, agenda del día, pendientes, ingresos | B | — | 🟡 |

---

## 5. Fase 2 — Crecer

| Módulo | Qué incluye | Plan | Depende de | Estado |
|---|---|---|---|---|
| Telemedicina | Videollamada, sala de espera virtual, chat en vivo, compartir estudios/pantalla, grabación | P (básica) / C (grabación) | Portal, integración Zoom/WebRTC | ⬜ |
| Farmacia | ✅ Catálogo de productos, stock por **lotes con caducidad**, entradas/salidas (**FEFO**), movimientos (bitácora), alertas de stock bajo y caducidad (`inventario/`). ⬜ Falta: POS/venta ligada a factura, código de barras (escáner), compras/proveedores formales, transferencias entre sucursales | C | Multi-sucursal | 🟡 |
| Inventario general | Material médico, insumos, alertas, compras, proveedores | P | — | ⬜ |
| Reportes / BI | ✅ KPIs (ingresos del mes + variación, citas, pacientes nuevos, tasa de no-show), ingresos/citas por mes, citas por estado, pacientes por tipo, top médicos, **horas pico**, **pacientes nuevos por mes** (Chart.js). Gateado con `modulo_activo('reportes')` en `dashboard.php`: sin el módulo no se renderizan las gráficas ni se carga Chart.js, y en su lugar sale una tarjeta para mejorar de plan. ⬜ Falta: exportar CSV, gastos, filtros por rango | P | — | 🟢 |
| App móvil paciente | Agenda, historial, estudios, chat, videollamada, notificaciones, ubicación | P | Portal + API | ⬜ |
| App móvil médico | Agenda, consultas, expedientes, dictado por voz, firmar recetas, estadísticas | C | API + firma | ⬜ |
| CRM médico | ✅ Seguimientos por paciente (tipo/fecha/estado, vencidos), cumpleaños del mes (felicitar por WhatsApp), **campañas de WhatsApp** por segmento (todos/tipo/cumpleaños) con enlaces wa.me (`crm/`). ⬜ Falta: embudos, correos automáticos, encuestas | P | Notificaciones | 🟡 |
| Especialidades | ✅ **Odontograma por caras** (`odontograma/`, FDI, capas hallazgo/requerido/realizado, **historial** de versiones, **genera presupuesto** con lo requerido y marca la cara al ejecutarlo) · ✅ **Curvas de crecimiento** pediátricas (`crecimiento/index.php`, peso/talla/IMC vs edad + mediana de referencia por sexo). ⬜ Falta: periodontograma, dentición temporal (dientes 51-85), percentiles exactos, ginecología (prenatal/FUM/USG), psicología (sesiones/escalas), nutrición (planes/calorías), dermatología (comparativa fotos), cardiología (ECG), oftalmología | P (paquete) / Add-on | Consulta avanzada + plantillas | 🟢 |

---

## 6. Fase 3 — Diferenciarte (IA)

| Módulo | Qué incluye | Plan | Depende de | Estado |
|---|---|---|---|---|
| Transcripción y resumen | Dictado voz→texto, resumen automático, generación de notas de consulta | C / Add-on | Consulta avanzada | ⬜ |
| Asistente clínico IA | Sugerencia de diagnósticos, detección de riesgos, recomendaciones, chat con el expediente ("pacientes con diabetes e hipertensión") | C / Add-on | Expediente estructurado + IA | ⬜ |
| OCR | Leer INE/CURP, recetas externas, estudios; escaneo con cámara | P / Add-on | — | ⬜ |
| Analítica predictiva | Predicción de ausencias (no-show), pacientes en riesgo, estimación de retrasos | C | BI + histórico | ⬜ |
| Automatizaciones | Seguimiento postconsulta, recordatorio de medicamentos, encuestas automáticas, certificados/incapacidades automáticos | P/C | CRM + plantillas | ⬜ |

> Nota IA: usar los modelos Claude más recientes (Opus/Sonnet) vía API, con el
> expediente como contexto. Cuidar **privacidad** (datos clínicos) y trazabilidad.

---

## 7. Fase 4 — Nivel hospital / ERP médico

| Módulo | Qué incluye | Plan | Depende de | Estado |
|---|---|---|---|---|
| Laboratorio | ✅ Catálogo de estudios (precio, muestra, preparación, unidad y rango de referencia, con carga inicial de 18 estudios comunes), órdenes con folio `LAB-AAAA-####`, prioridad urgente, laboratorio externo, flujo de estados (solicitada → en proceso → lista → entregada), captura de resultados con marca de **fuera de rango**, PDF/imagen del resultado que se guarda en el expediente (`archivos.lab_orden_id`) y por tanto se publica solo en el portal del paciente, orden e informe imprimibles con membrete, aviso por WhatsApp cuando está lista (`laboratorio/`). Gateado `require_modulo('laboratorio')` (solo plan Clínica). ⬜ Falta: comparación histórica de un mismo estudio, integración con equipos, cobro ligado a factura | C | Expediente | 🟢 |
| Radiología / DICOM | Subir DICOM, **visor DICOM**, comparación histórica, reportes | C / Add-on | Almacenamiento grande | ⬜ |
| Multi-sucursal | Sucursales dentro de un mismo tenant, transferencias, consolidado | C | Entitlements + modelo de datos | ⬜ |
| Recursos Humanos | Médicos, enfermería, recepción, horarios, **nómina**, comisiones, asistencias | C | — | ⬜ |
| ERP médico | Cuentas por pagar, compras, proveedores, membresías/suscripciones/paquetes, comisiones, reembolsos | C | Facturación + inventario | ⬜ |
| Integraciones | WhatsApp, Zoom, Google Calendar, Outlook, Stripe, PayPal, Mercado Pago ✅, Twilio, SAT, Apple Health, Google Fit, wearables | varía | API + 3.3 | 🟡 |

---

## 8. Funciones "wow" (oportunistas, encajan donde toque)

Kiosco de auto check-in · reconocimiento facial/huella de pacientes · mapa del
consultorio · pantallas en sala de espera · sistema de turnos tipo banco · firma
en tablet · historial familiar conectado · generación automática de certificados
e incapacidades · OCR de INE/CURP. → la mayoría cuelgan de **Flujo de sala**
(Fase 1) o **IA/OCR** (Fase 3).

---

## 9. Mapa módulo → plan (matriz resumida)

| Módulo | Básico | Profesional | Clínica |
|---|:--:|:--:|:--:|
| Citas + agenda pro | ✓ | ✓ | ✓ |
| Expediente + recetas | ✓ | ✓ | ✓ |
| Servicios + presupuestos y abonos | ✓ | ✓ | ✓ |
| Facturación simple | ✓ | ✓ | ✓ |
| Recordatorios correo | ✓ | ✓ | ✓ |
| WhatsApp/SMS | — | ✓ | ✓ |
| Portal paciente | — | ✓ | ✓ |
| Telemedicina | — | ✓ | ✓ |
| Reportes/BI | — | ✓ | ✓ |
| Especialidades | — | ✓ | ✓ |
| App móvil | — | paciente | + médico |
| Farmacia/POS | — | — | ✓ |
| Laboratorio | — | — | ✓ |
| IA clínica | — | — | ✓ |
| Multi-sucursal / RH / ERP | — | — | ✓ |
| CFDI/SAT | — | — | ✓ |
| OCR / DICOM / IA avanzada | — | add-on | ✓ |

---

## 10. Orden de ejecución sugerido

1. ✅ **§3.1 Entitlements** (planes/módulos/gating) + `planes_mp()` migrado a 3 planes.
2. ✅ **§3.2 Auditoría + 2FA** (bitácora + doble factor TOTP).
3. ✅ **Servicios + presupuestos + abonos**: la capa de dinero y tratamiento, sin
   la cual un dentista no puede migrar su operación. Módulo `presupuestos`.
4. ✅ **Odontograma real**: por caras, tres capas (hallazgo / a tratar / realizado),
   historial de versiones y circuito cerrado con el presupuesto.
5. **Siguiente**: consentimiento informado con firma → WhatsApp por API
   (recordatorios automáticos y confirmación bidireccional).
6. Después: recetas con firma/QR → portal paciente con pagos → dashboard KPIs.
7. Ajustar **precios de planes** una vez Fase 1 tenga valor diferenciado por plan.
8. **Fase 2** en adelante según demanda comercial.

> Cada módulo que arranquemos: rama `feat/<modulo>`, migración SQL idempotente en
> `sql/`, gating con `modulo_activo()`, y se marca su estado en este doc.

---

_Última actualización: 2026-07-09._
