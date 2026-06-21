# Consultorios Médicos y Dentales

Sistema web para la gestión de consultorios: **citas, expediente clínico y recordatorios**.
Construido con **PHP 8 + MariaDB + Bootstrap 5** sobre XAMPP.

## Características

- **Landing pública** con presentación de funciones y planes.
- **Acceso por roles**: Administrador, Médico/Dentista y Recepción.
- **Pacientes**: alta, edición, búsqueda y ficha clínica (alergias, antecedentes).
- **Citas**: agenda con filtros por fecha/médico/estado y cambio rápido de estado
  (programada, confirmada, atendida, cancelada, no asistió).
- **Expediente clínico electrónico**: historial de consultas con diagnóstico,
  tratamiento, receta y signos vitales.
- **Recordatorios**: panel de próximas citas (7 días) y agenda del día en el dashboard.
- Seguridad: contraseñas con `bcrypt`, protección **CSRF** y consultas preparadas (PDO).

## Instalación

1. Copia la carpeta `consultorios/` en `htdocs/` de XAMPP (ya está ahí).
2. Inicia **Apache** y **MySQL** desde el panel de XAMPP.
3. Crea la base de datos e importa los tres archivos **en orden**
   (`schema` → `modulos` → `configuracion`):

   ```bash
   MYSQL=/Applications/XAMPP/xamppfiles/bin/mysql
   $MYSQL -u root -e "CREATE DATABASE IF NOT EXISTS consultorios_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   $MYSQL -u root consultorios_db < sql/schema.sql
   $MYSQL -u root consultorios_db < sql/modulos.sql
   $MYSQL -u root consultorios_db < sql/configuracion.sql
   $MYSQL -u root consultorios_db < sql/multitenant.sql
   $MYSQL -u root consultorios_db < sql/archivos.sql
   $MYSQL -u root consultorios_db < sql/planes.sql
   $MYSQL -u root consultorios_db < sql/seguridad.sql
   $MYSQL -u root consultorios_db < sql/portal.sql
   $MYSQL -u root consultorios_db < sql/expediente.sql
   $MYSQL -u root consultorios_db < sql/agenda.sql
   ```

   O desde **phpMyAdmin** → selecciona la BD → *Importar* (un archivo a la vez,
   en ese orden). Los `.sql` ya no incluyen `CREATE DATABASE`, así que se importan
   en la BD que tengas seleccionada (compatible con hosting compartido).

4. Abre el sitio en el navegador:

   - Sitio público: <http://localhost/consultorios/>
   - Acceso al sistema: <http://localhost/consultorios/auth/login.php>

## Cuentas de prueba

Contraseña para todas: **`password`**

| Rol         | Correo                       |
|-------------|------------------------------|
| Admin       | `admin@consultorio.com`      |
| Médico      | `laura@consultorio.com`      |
| Dentista    | `carlos@consultorio.com`     |
| Recepción   | `recepcion@consultorio.com`  |

## Permisos por rol

| Acción                         | Admin | Médico | Recepción |
|--------------------------------|:-----:|:------:|:---------:|
| Ver pacientes y citas          | ✅    | ✅     | ✅        |
| Crear/editar pacientes y citas | ✅    | ✅     | ✅        |
| Eliminar pacientes/citas       | ✅    | ❌     | ✅        |
| Registrar consultas (expediente)| ✅   | ✅     | ❌        |
| Gestionar personal (usuarios)  | ✅    | ❌     | ❌        |

> El médico ve por defecto su propia agenda en el panel y en el filtro de citas.

## Configuración

Edita `config/config.php` si tu MySQL usa otras credenciales:

```php
define('DB_USER', 'root');
define('DB_PASS', '');        // contraseña de MySQL
define('BASE_URL', '/consultorios');
```

## Estructura

```
consultorios/
├── index.php            Landing pública
├── dashboard.php        Panel interno (estadísticas + recordatorios)
├── config/config.php    Configuración y conexión PDO
├── includes/            functions.php, header.php, footer.php
├── auth/                login.php, logout.php
├── pacientes/           CRUD + expediente (ver.php)
├── citas/               Agenda CRUD + cambio de estado
├── usuarios/            Gestión de personal (solo admin)
├── assets/css/          Estilos
└── sql/schema.sql       Esquema + datos de ejemplo
```

## Despliegue (GitHub → Hostinger con Git)

El despliegue usa el **Git integrado de Hostinger** (hPanel → *Avanzado → Git*):
se conecta el repositorio y Hostinger publica el contenido en `public_html` al
hacer *Deploy* (o automáticamente con un webhook).

### Configuración inicial (una sola vez)

1. **Base de datos** (hPanel → phpMyAdmin): crea la BD e importa, en orden:
   `sql/schema.sql`, `sql/modulos.sql`, `sql/configuracion.sql`.

2. **Archivo de secretos** (credenciales): crea `mediagenda_secrets.php` en la
   carpeta que **contiene** a `public_html` (un nivel arriba), con el contenido
   de `config/secrets.sample.php` y tus credenciales reales de MySQL.
   - Vive **fuera del webroot**: ningún despliegue lo borra y no es accesible por
     web. `config/config.php` **sí** se versiona (sin secretos) y lo carga solo,
     buscándolo en varios niveles por encima de `public_html`.

3. **Git en Hostinger** (hPanel → *Git*): añade el repositorio
   `https://github.com/AlexanderBetanzos/mediagenda.git`, rama `main`,
   directorio `public_html`. Para auto-deploy en cada push, copia la **URL del
   webhook** que da Hostinger y pégala en GitHub → *Settings → Webhooks*.

> Tras cada push, pulsa **Deploy** en hPanel (o deja que el webhook lo haga).

## Notas de seguridad

- Las credenciales viven en `mediagenda_secrets.php` **fuera de public_html**:
  nunca llegan al repo ni se exponen por web (`config/secrets.php` local está
  en `.gitignore`).
- Cambia las contraseñas de las cuentas demo antes de usar en producción.
- `display_errors` se desactiva solo en producción (se activa únicamente en
  `localhost`/CLI o con `APP_DEBUG=1`).
