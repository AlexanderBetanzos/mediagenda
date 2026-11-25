# 02. Alcance, roles y responsabilidades

## Alcance del SIRES a certificar
- **Tipo:** Consulta externa, sector privado, SaaS (GIIS-B015 + GIIS del SGSI).
- **Módulos en alcance:** Pacientes, Expediente (consultas, notas aclaratorias, firma, impresión), Recetas.
- **Módulos que generan registro clínico (incluir si se usan):** Agenda, Laboratorio, Ultrasonido, Óptica.
- **Fuera de alcance:** Punto de venta, inventario, CRM, presupuestos, landing comercial.

## Roles
| Rol | Persona | Responsabilidades |
|---|---|---|
| Director / dueño del SGSI | Alexander Betanzos López | Aprueba la política, asigna recursos, firma minutas |
| Responsable de Seguridad | [nombre] | Opera el SGSI, revisa bitácoras, coordina incidentes, minuta mensual |
| Desarrollo | [nombres] | Cambios seguros al código (doc. 11), sin acceso a datos reales salvo soporte autorizado |
| Soporte | [nombres] | Atiende consultorios; accede a un consultorio solo con su autorización y queda en bitácora |

## Roles dentro de MediAgenda (consultorios)
- **Administrador:** configuración, personal, bitácora, suscripción.
- **Médico:** expediente, consultas, recetas, firma electrónica, impresión.
- **Recepción:** agenda, pacientes, cobros; no escribe ni firma notas clínicas.
- **Consola de plataforma:** roles `super` (dueño) y `socio` (solo los consultorios asignados).
