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

## Despliegue automático (GitHub → hosting por FTP)

Cada `git push` a la rama `main` despliega el sitio al hosting mediante
**GitHub Actions** (`.github/workflows/deploy.yml`).

### Configuración inicial (una sola vez)

1. **En el servidor (cPanel):**
   - Crea la base de datos e importa, en orden: `sql/schema.sql`,
     `sql/modulos.sql`, `sql/configuracion.sql`.
   - Copia `config/config.sample.php` como `config/config.php` y rellena las
     credenciales reales de MySQL. Este archivo **no** se versiona ni se
     sobrescribe en los despliegues (está en `.gitignore`).

2. **En GitHub** (repo → *Settings* → *Secrets and variables* → *Actions*),
   crea estos *secrets*:

   | Secret           | Valor                                             |
   |------------------|---------------------------------------------------|
   | `FTP_SERVER`     | host FTP, ej. `ftp.midominio.com`                 |
   | `FTP_USERNAME`   | usuario FTP de cPanel                             |
   | `FTP_PASSWORD`   | contraseña FTP                                    |
   | `FTP_SERVER_DIR` | carpeta destino, ej. `/public_html/`             |

   Desde la terminal también puedes hacerlo con:
   ```bash
   gh secret set FTP_SERVER
   gh secret set FTP_USERNAME
   gh secret set FTP_PASSWORD
   gh secret set FTP_SERVER_DIR
   ```

> Si tu hosting no soporta FTPS, cambia `protocol: ftps` por `protocol: ftp`
> en `.github/workflows/deploy.yml`.

## Notas de seguridad

- `config/config.php` está en `.gitignore`: las credenciales nunca llegan al repo.
- Cambia las contraseñas de las cuentas demo antes de usar en producción.
- En producción, desactiva `display_errors` en `config/config.php` y usa
  un usuario de MySQL con contraseña (no `root`).
