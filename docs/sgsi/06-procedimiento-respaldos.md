# 06. Procedimiento de respaldos y restauración

## Respaldo
- **Automático:** el cron horario (`cron/recordatorios.php --cada-hora`) crea un respaldo diario
  completo (estructura + datos), comprimido y cifrado con AES-256-GCM.
- **Manual:** /platform/respaldos → "Respaldar ahora" antes de cambios grandes.
- **Retención:** últimos 14 (configurable). **Copia externa:** el Responsable descarga uno cada
  semana y lo guarda fuera del hosting en [ubicación cifrada].
- La APP_KEY se guarda offline en [dos ubicaciones]; sin ella los respaldos no se pueden abrir.

## Prueba de restauración
- **Automática diaria:** después de crearlo, el sistema descifra, descomprime, comprueba el
  SHA-256 y cuenta las filas de cada tabla contra el manifiesto. Resultado registrado.
- **Restauración real trimestral:** descargar un respaldo, restaurarlo en una base de datos de
  prueba (`gunzip < respaldo.sql.gz | mysql -u usuario -p base_prueba`), entrar con un usuario
  de prueba y revisar 3 expedientes al azar. Registrar en /platform/sgsi ("Revisión de riesgos"
  o "Cambio") con fecha, duración y resultado.

## Restauración en producción (desastre)
1. Declarar incidente (doc. 07).
2. Elegir el respaldo verificado más reciente.
3. Restaurar en una base nueva, cambiar credenciales en secrets.php, comprobar, conmutar.
4. Registrar el tiempo total (objetivo: < 4 horas) y la pérdida máxima (objetivo: < 24 horas).
