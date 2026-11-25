# 04. Análisis de riesgos y plan de tratamiento

Escala: Probabilidad (P) e Impacto (I) de 1 a 3; Riesgo = P × I. Revisión semestral.

| # | Riesgo | Activo | P | I | R | Controles existentes | Tratamiento pendiente | Responsable / fecha |
|---|---|---|---|---|---|---|---|---|
| R1 | Robo de contraseña de personal de un consultorio | A1, A2 | 2 | 3 | 6 | 2FA (exigible), bloqueo por intentos, política de contraseñas, bitácora de accesos | Promover 2FA obligatorio en planes Clínica | [ ] |
| R2 | Acceso indebido a la consola de plataforma | A1–A4 | 1 | 3 | 3 | Rol super/socio, bitácora | 2FA en consola de plataforma | [ ] |
| R3 | Pérdida de la base de datos | A2 | 1 | 3 | 3 | Respaldo diario cifrado y verificado | Copia semanal fuera del hosting | [ ] |
| R4 | Pérdida de la APP_KEY | A5 | 1 | 3 | 3 | — | Copia offline en dos lugares seguros | [ ] |
| R5 | Alteración de notas clínicas o bitácora en la BD | A2 | 1 | 3 | 3 | Sellos SHA-256 encadenados con verificación | Revisión mensual del estado de la cadena | [ ] |
| R6 | Fuga de datos por proveedor | A2, A9, A10 | 1 | 3 | 3 | Proveedores reconocidos | Revisión anual de proveedores (doc. 09), contratos | [ ] |
| R7 | Vulnerabilidad en el código (inyección, XSS) | A1 | 2 | 3 | 6 | Consultas preparadas, escape de salida, CSRF | Revisión de seguridad por cambio relevante (doc. 11) | [ ] |
| R8 | Caída del servicio | A1 | 2 | 2 | 4 | Hosting administrado | Monitoreo externo de disponibilidad | [ ] |
| R9 | Equipo de trabajo robado | A13 | 2 | 2 | 4 | — | Disco cifrado y bloqueo obligatorio | [ ] |
| R10 | Persona con acceso deja el proyecto | A6–A8 | 2 | 2 | 4 | — | Baja de accesos en 24 h (doc. 05) | [ ] |
