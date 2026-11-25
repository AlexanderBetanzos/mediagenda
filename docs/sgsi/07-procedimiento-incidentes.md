# 07. Procedimiento de gestión de incidentes

**Incidente:** cualquier evento que afecte o pueda afectar la confidencialidad, integridad o
disponibilidad de la información (accesos sospechosos, bitácora "no coincide", fuga, caída,
pérdida de equipo, error que exponga datos).

1. **Reporte (≤ 24 h):** quien lo detecte avisa al Responsable de Seguridad.
2. **Registro inmediato:** /platform/sgsi → "Incidente de seguridad", estado *abierto*, severidad.
3. **Contención:** bloquear cuentas, rotar secretos, aislar lo afectado.
4. **Análisis:** bitácora sellada, logs del hosting, qué datos y qué consultorios.
5. **Notificación:** si hay datos personales afectados, avisar a los consultorios responsables
   sin demora para que informen a sus pacientes conforme a la ley de datos personales.
6. **Recuperación:** restaurar desde respaldo verificado si aplica.
7. **Cierre:** causa raíz, acciones preventivas con responsable y fecha; estado *cerrado*.

Si en un mes no hubo incidentes, la minuta mensual lo dice expresamente ("Incidentes: ninguno").
