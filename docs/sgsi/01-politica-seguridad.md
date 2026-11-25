# 01. Política de seguridad de la información

**Organización:** BETASYD Consultoría · **Sistema:** MediAgenda (MediOS), SIRES de consulta externa en modalidad SaaS
**Versión:** 1.0 · **Fecha:** [dd/mm/aaaa] · **Aprobó:** Alexander Betanzos López, Director · **Próxima revisión:** [+12 meses]

## Propósito
Proteger la confidencialidad, integridad y disponibilidad de la información de salud que los
consultorios registran en MediAgenda, conforme a la NOM-024-SSA3-2012, la NOM-004-SSA3-2012 y
la legislación de protección de datos personales.

## Alcance
Plataforma MediAgenda (aplicación, base de datos, archivos del expediente y respaldos), la
infraestructura de hospedaje, el repositorio de código y todas las personas con acceso a ellos.

## Principios
1. **Mínimo privilegio:** cada persona tiene solo el acceso que su función necesita.
2. **Identidad única:** nadie comparte cuentas; cada acceso es nominal y queda en bitácora.
3. **Registros inalterables:** las notas clínicas y la bitácora no se modifican; se sellan
   (SHA-256 encadenado) y las correcciones son notas aclaratorias.
4. **Cifrado:** en tránsito (TLS) siempre; en reposo para llaves, certificados, secretos y respaldos.
5. **Respaldo verificado:** respaldo diario cifrado con prueba de restauración registrada.
6. **Confidencialidad contractual:** toda persona con acceso firma un acuerdo de confidencialidad.
7. **Mejora continua:** revisión mensual del SGSI con minuta y acciones con responsable y fecha.

## Obligaciones
- Usar verificación en dos pasos en la consola de plataforma, el hosting, GitHub y el correo.
- Contraseñas: mínimo 10 caracteres, letras y números, únicas por servicio, en gestor de contraseñas.
- No copiar datos clínicos fuera de la plataforma salvo en respaldos cifrados autorizados.
- Reportar cualquier incidente o sospecha al Responsable de Seguridad en menos de 24 horas.
- Los datos de prueba nunca son datos reales de pacientes.

## Incumplimiento
Retiro inmediato de accesos y, en su caso, las acciones legales que procedan.

Firma: ______________________ Alexander Betanzos López · Fecha: ____________
