# 11. Control de cambios y desarrollo seguro

1. Todo cambio pasa por git; producción se despliega desde la rama `main` (Hostinger).
2. Nunca se suben secretos al repositorio (config/secrets.php y config/app.key están en .gitignore).
3. Reglas de código: consultas preparadas (PDO), escape de salida (`e()`), CSRF en todo POST,
   verificación de consultorio (`consultorio_id`) en cada consulta, sin edición de notas clínicas.
4. Cambios en autenticación, permisos, expediente, recetas, bitácora o respaldos:
   revisión de seguridad antes de publicar y registro en /platform/sgsi ("Cambio relevante").
5. Se prueban con datos ficticios; nunca con datos reales de pacientes.
6. Si un cambio falla: revertir el commit y redeplegar; si afectó datos, procedimiento de incidentes.
