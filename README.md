# ResultWeb

Sistema web de **resultados de laboratorio en línea** (port Symfony de un sistema legacy). Permite a pacientes consultar y descargar sus resultados, a empresas (clientes del laboratorio) gestionar los resultados de sus pacientes, y al administrador administrar pacientes, clientes, firmas, parámetros y estadísticas.

## Características

- **Autenticación** con auto-registro de pacientes/empresas desde la base de datos del laboratorio (WinsisLab), protección anti fuerza bruta y recuperación de contraseña por código de verificación.
- **Pacientes**: consulta y descarga de resultados en PDF (con código de barras, firmas de médicos, textos descriptivos y marca de agua para resultados preliminares), validación de pago.
- **Empresas (clientes)**: búsqueda de pacientes, visualización de solicitudes y descarga de PDFs; dashboard con sus estadísticas.
- **Administración**: CRUD de pacientes, clientes (con logos), firmas y parámetros; dashboard general con estadísticas; exportación a Excel (CSV); búsquedas con filtros y debounce; modo oscuro.
- **PDFs**: generados con dompdf (plantillas Twig) o descargados desde FTP cuando el archivo ya está publicado.
- **Seguridad**: endpoints API protegidos por sesión, validación de archivos (MIME y tamaño), prevención de path traversal y SQL injection.

## Requisitos

- PHP 8.2+ con extensiones: `pdo_mysql`, `pdo_pgsql`, `fileinfo`, `mbstring`, `gd` (para PDF), `ftp` (opcional, para descarga de resultados publicados).
- Composer 2.
- MySQL y PostgreSQL (o solo MySQL si los resultados se sirven desde otra vía).

## Instalación

```bash
composer install
```

## Configuración

Cree el archivo `.env.local` (no está versionado) con sus credenciales:

```dotenv
APP_ENV=dev
APP_SECRET=un-secreto-aleatorio

# Conexión 1 (alpha): tabla users/firms/domains del sistema
ALPHA_DATABASE_URL="mysql://usuario:password@host:3306/basededatos?charset=UTF8"

# Conexión 2 (beta): resultados del laboratorio (WinsisLab)
BETA_DATABASE_URL="postgresql://usuario:password@host:5432/DBWINSISLAB?charset=UTF8"

# FTP (opcional) para descargar PDFs ya publicados
FTP_HOST=10.0.0.242
FTP_USER=usuario
FTP_PASS=password
FTP_PORT=21
```

Autenticación legacy (opcional, para conectar con el esquema existente de `users`):

```dotenv
LEGACY_AUTH_CONNECTION=alpha
LEGACY_AUTH_SQL="SELECT id, type, code, identification, identificationtype, names, lastnames, address, phones, sex, download_options, logo_options FROM users WHERE identification = :username AND password = :password AND isadmin = 0 AND active = 1"
LEGACY_ADMIN_AUTH_CONNECTION=alpha
LEGACY_ADMIN_AUTH_SQL="SELECT id, 'admin' AS type, code, identification, identificationtype, names, lastnames, address, phones, sex, download_options, logo_options FROM users WHERE identification = :username AND password = :password AND isadmin = 1 AND active = 1"
```

## Ejecutar en desarrollo

```bash
php -S 127.0.0.1:8090 -t public
```

Acceda a `http://127.0.0.1:8090`.

## Estructura

- `src/Controller/` — controladores web (auth, home, admin, resultados) y API (`src/Controller/Api/`).
- `src/Service/` — lógica de negocio (auth, resultados, PDF, FTP, archivos, usuarios, dominios, firmas).
- `templates/` — plantillas Twig (layout moderno en `templates/modern/`, módulos admin en `templates/admin/`).
- `public/static/` — imágenes base y archivos subidos (upload/ no se versiona).

## Notas

- Las contraseñas se almacenan en texto plano (compatibilidad con el sistema legacy).
- La base de datos WinsisLab (beta) es de **solo lectura**: el sistema únicamente la consulta.
- El login exige un tipo de documento válido; el auto-registro crea el usuario en `users` con la contraseña por defecto = número de identificación (debe cambiarse en el primer ingreso).
