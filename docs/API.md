# API y endpoints

Todas las rutas de Symfony (listado completo: `php bin/console debug:router`).

## Páginas

| Método | Ruta | Nombre | Descripción |
|---|---|---|---|
| ANY | `/` | `root` | Redirige según perfil (admin → `/admin/pacientes`) |
| ANY | `/admin` | `admin` | Redirige a `/admin/pacientes` |
| GET | `/login` | `security_login` | Página de login |
| GET | `/reset-password` | `reset_password_page` | Página de recuperación de contraseña |
| GET | `/admin/pacientes` | `admin_patients` | Módulo pacientes |
| GET | `/admin/resultados` | `admin_results` | Módulo resultados |
| GET | `/admin/clientes` | `admin_clients` | Módulo clientes |
| GET | `/admin/firmas` | `admin_signatures` | Módulo firmas |
| GET | `/admin/configuracion` | `admin_configuration` | Módulo configuración |

Los módulos `/admin/*` requieren sesión (de lo contrario renderizan el login) y permisos por tipo: `admin` siempre; otros perfiles según `actions` del usuario.

## Autenticación

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/login_check` | Login. Campos: `_username`, `_password`, `_identification_type` (def. `CC`). Respuesta: `{state: "111", message, user}` o `{state: "000", message}` |
| GET/POST | `/logout` | Invalida sesión. JSON si es POST/XHR, redirección si GET |
| POST | `/change_password` | Cambio de contraseña: `_current_password`, `_new_password`, `_confirm_password` |
| POST | `/request_reset_password` | Paso 1 recuperación: `email` → envía código de 6 dígitos (10 min). En `dev` devuelve `debug_code` |
| POST | `/reset_password` | Paso 2 recuperación: `email`, `code`, `password` |

## APIs JSON (requieren sesión)

Todas devuelven `401 {"message":"No autorizado"}` sin sesión válida.

### /api/users — pacientes y cuentas

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/users` | Lista paginada: `page`, `limit`, `parameter`, `type`, `active`. Respuesta: `{content, totalRecord}` |
| GET | `/api/users/{id}` | Detalle (incluye email resuelto desde WinsisLab) |
| POST | `/api/users` | Crear paciente/cuenta |
| PUT/PATCH | `/api/users/{id}` | Actualizar |
| DELETE | `/api/users/{id}` | Desactivar |

### /api/clients — clientes (empresas)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/clients` | Lista paginada (`page`, `limit`, `parameter`, `active`) → `{content, totalRecord}` |
| GET | `/api/clients/{id}` | Detalle (con logo) |
| POST | `/api/clients` | Crear |
| PUT/PATCH | `/api/clients/{id}` | Actualizar |
| DELETE | `/api/clients/{id}` | Desactivar |

### /api/firms — firmas de PDFs

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/firms` | Lista → `{items, total_count}` |
| GET | `/api/firms/{id}` | Detalle |
| POST | `/api/firms` | Crear (`code`, `url`, `codeCompany`, `active`) |
| PUT/PATCH | `/api/firms/{id}` | Actualizar |
| DELETE | `/api/firms/{id}` | Desactivar |

### /api/domains — parámetros (domains)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/domains` | Lista paginada (`page`, `limit`, `parameter`, `name`, `active`) → `{content, totalRecord}` |
| GET | `/api/domains/by-name/{name}` | Activos de un dominio (p. ej. `identificationtype`) |
| GET | `/api/domains/{id}` | Detalle |
| POST | `/api/domains` | Crear |
| PUT/PATCH | `/api/domains/{id}` | Actualizar |
| DELETE | `/api/domains/{id}` | Desactivar |

### /api/files — archivos

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/files?path=...` | Sirve un archivo de `public/static/` (protección path traversal) |
| POST | `/api/files` | Sube archivo (`file`, `folder` opcional). Validación MIME real + 5 MB. Devuelve ruta relativa |
| POST | `/api/upload` | Alias de subida (logo de cliente) |

## Resultados

| Método | Ruta | Nombre | Descripción |
|---|---|---|---|
| GET | `/resultCrud?identification=&type_doc=` | `results_crud` | Solicitudes del paciente (usa identificación de sesión si no llegan params) → `{total_count, items}` |
| GET | `/results/labs` | `results_labs` | Búsqueda combinada para admin/empresa: `identification_number`, `identification_type`, `name`, `last_name`, `start_date`, `end_date`. Exige al menos un filtro. → `{total_count, items}` |
| GET | `/result/detail/{requestCode}?prevalidated=` | `results_detail` | Detalle de solicitud (secciones/exámenes) |
| GET | `/valid_result?_solicitud=` | `results_validate` | Valida pago de una solicitud → `{success, state, msg}` |
| GET | `/result/pdf/{requestCode}` | `results_pdf` | Genera/descarga el PDF (dompdf o FTP) |
| GET | `/api/dashboard` | `api_dashboard` | Estadísticas por perfil → `{requests, patients, exams}` |
| POST | `/upload_result/resultados` | `admin_upload_result` | Sube PDF de resultados |
| POST | `/upload_result_ftp` | `admin_upload_result_ftp` | Publica PDF al FTP |

## Ejemplos

```bash
# Login
curl -c cookies.txt -X POST http://127.0.0.1:8090/login_check \
  -d "_username=23008709&_password=23008709&_identification_type=CC"

# Listar pacientes (admin)
curl -b cookies.txt "http://127.0.0.1:8090/api/users?type=person&active=1&limit=10"

# Búsqueda de resultados (empresa)
curl -b cookies.txt "http://127.0.0.1:8090/results/labs?identification_number=1048460045"

# Dashboard
curl -b cookies.txt http://127.0.0.1:8090/api/dashboard

# Descargar PDF
curl -b cookies.txt -o resultado.pdf "http://127.0.0.1:8090/result/pdf/<requestCode>"
```
