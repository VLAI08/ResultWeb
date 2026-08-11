# ResultWeb

Sistema web de **resultados de laboratorio en línea** — port en Symfony de un sistema legacy. Permite a pacientes consultar y descargar sus resultados, a empresas (clientes del laboratorio) gestionar los resultados de sus pacientes, y al administrador administrar pacientes, clientes, firmas, parámetros y estadísticas.

- **Framework**: Symfony 7.4 LTS (PHP 8.2+)
- **Frontend**: Twig + Bootstrap 5.3 (CDN) + JavaScript vanilla
- **Base de datos**: MySQL (tablas del sistema: `users`, `firms`, `domains`) y PostgreSQL (WinsisLab: resultados de laboratorio, solo lectura)
- **Repositorio**: https://github.com/VLAI08/ResultWeb (privado)

## Documentación

| Documento | Contenido |
|---|---|
| [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md) | Arquitectura, flujos, módulos y estructura del código |
| [docs/API.md](docs/API.md) | Contratos de todos los endpoints (páginas, APIs y PDF) |
| [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md) | Despliegue en desarrollo y producción |
| [docs/PRUEBAS.md](docs/PRUEBAS.md) | Credenciales de prueba y checklist de verificación |

## Características

- **Autenticación** con auto-registro de pacientes/empresas desde WinsisLab, protección anti fuerza bruta (5 intentos → 5 min de bloqueo) y recuperación de contraseña por código de verificación por correo.
- **Pacientes**: consulta y descarga de resultados en PDF (código de barras, firmas de médicos, textos descriptivos, marca de agua para resultados preliminares) y validación de pago.
- **Empresas (clientes)**: búsqueda de pacientes, solicitudes y descarga de PDFs; dashboard con estadísticas.
- **Administración**: CRUD de pacientes, clientes (con logos), firmas y parámetros; dashboard con estadísticas; exportación CSV; filtros con debounce; modo oscuro.
- **PDFs**: generados con dompdf (plantillas Twig) o descargados desde FTP cuando el archivo ya está publicado.
- **Seguridad**: APIs protegidas por sesión, validación MIME/tamaño de subidas, protección contra path traversal y SQL injection.

## Requisitos

- PHP 8.2+ con extensiones: `pdo_mysql`, `pdo_pgsql`, `fileinfo`, `mbstring`, `gd` (PDF), `ftp` (opcional), `curl`
- Composer 2
- MySQL y PostgreSQL (o solo MySQL si los resultados se sirven por otra vía)

## Instalación

```bash
composer install
```

## Configuración

Cree el archivo `.env.local` (no versionado) con sus credenciales:

```dotenv
APP_ENV=dev
APP_SECRET=un-secreto-aleatorio

# Conexión 1 (alpha): tabla users/firms/domains del sistema
ALPHA_DATABASE_URL="mysql://usuario:password@host:3306/basededatos?charset=UTF8"

# Conexión 2 (beta): resultados del laboratorio (WinsisLab, solo lectura)
BETA_DATABASE_URL="postgresql://usuario:password@host:5432/DBWINSISLAB?charset=UTF8"

# FTP (opcional) para descargar PDFs ya publicados
FTP_HOST=10.0.0.242
FTP_USER=usuario
FTP_PASS=password
FTP_PORT=21
```

Autenticación legacy (opcional, esquema existente de `users`):

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

Ver [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md) para entornos, caché de producción y servidores web.

## Comandos útiles

```bash
php bin/console cache:clear                # limpiar caché dev
php bin/console cache:clear --env=prod     # limpiar caché prod
php bin/console lint:twig templates        # validar plantillas
php bin/console lint:container             # validar container
php bin/console about                      # versión de Symfony
php bin/console debug:router               # listar rutas
```
