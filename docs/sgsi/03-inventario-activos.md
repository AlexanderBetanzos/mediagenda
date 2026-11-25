# 03. Inventario de activos

Actualizado: [dd/mm/aaaa] · Responsable: [nombre]

| # | Activo | Tipo | Ubicación / proveedor | Datos clínicos | Dueño | Acceso | Protección |
|---|---|---|---|---|---|---|---|
| A1 | Aplicación MediAgenda (PHP) | Software | Hostinger, mediagenda.com.mx | Procesa | BETASYD | Despliegue por git | HTTPS, sesiones seguras, roles |
| A2 | Base de datos MySQL de producción | Datos | Hostinger | **Sí** | BETASYD | Panel de Hostinger | Credenciales en secrets.php, red del proveedor |
| A3 | Archivos del expediente (uploads/) | Datos | Hostinger | **Sí** | BETASYD | Solo vía aplicación | .htaccess deny, sesión y permiso |
| A4 | Respaldos cifrados | Datos | Hostinger (respaldos/) + copia externa [dónde] | **Sí** (cifrados) | BETASYD | Consola /platform/respaldos | AES-256-GCM, retención 14 |
| A5 | APP_KEY (llave maestra) | Secreto | config/secrets.php + copia offline [dónde] | Protege A4, CSD, e.firma, 2FA | Director | Solo Director | Fuera de git |
| A6 | Repositorio de código | Software | GitHub [org/repo] | No | BETASYD | Cuentas con 2FA | Sin secretos en el repo |
| A7 | Cuenta de Hostinger | Servicio | Hostinger | Indirecto | Director | [personas] | 2FA |
| A8 | Cuenta de Mercado Pago (plataforma) | Servicio | Mercado Pago | No (pagos) | Director | [personas] | 2FA |
| A9 | WhatsApp Cloud API (plataforma) | Servicio | Meta | Nombre, cita | Director | [personas] | 2FA, token permanente en BD |
| A10 | API de IA clínica | Servicio | Anthropic | Texto dictado de la consulta | Director | Director | Llave en BD de plataforma |
| A11 | Videoconsulta | Servicio | Jitsi (meet.jit.si o propio) | Audio/video en tránsito | Consultorio | — | WebRTC DTLS-SRTP |
| A12 | Correo transaccional | Servicio | SMTP de Hostinger | Nombre, cita | BETASYD | [personas] | TLS |
| A13 | Equipos de trabajo | Hardware | [laptops] | No deberían | [personas] | Dueño | Disco cifrado, bloqueo, antivirus |
| A14 | Gestor de contraseñas | Servicio | [cuál] | No | Cada persona | Personal | 2FA |
