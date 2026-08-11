# Arquitectura

## Visión general

Aplicación Symfony 7.4 LTS sin frontend de framework: las páginas se renderizan con Twig y las interacciones usan JavaScript vanilla que llama a endpoints JSON propios (`/api/*`). El sistema lee de dos bases de datos:

- **Alpha (MySQL)**: tablas propias del sistema — `users` (pacientes, clientes-empresa, administradores), `firms` (firmas para PDF), `domains` (parámetros/configuración).
- **Beta (PostgreSQL, WinsisLab, SOLO LECTURA)**: datos de resultados de laboratorio — solicitudes, exámenes, secciones, texto de resultados. Nunca se escribe sobre esta base.

## Estructura del código

```
src/
├── Controller/
│   ├── HomeController.php          Ruta raíz, redirección según perfil
│   ├── AdminController.php         Módulos del panel administrable (/admin/*)
│   ├── ResultsController.php       Resultados: listas, búsquedas, detalle, PDF, dashboard
│   ├── SecurityController.php      Login, logout, cambio/recuperación de contraseña
│   └── Api/                        CRUDs JSON usados por el frontend
│       ├── ApiBaseController.php   Base: requireSession() → 401 si no hay sesión
│       ├── UserApiController.php   /api/users
│       ├── ClientsApiController.php /api/clients
│       ├── FirmsApiController.php  /api/firms
│       ├── DomainApiController.php /api/domains
│       └── FileApiController.php   /api/files, /api/upload (subidas validadas)
├── Entity/                         Doctrine ORM (Users, Domains)
├── Repository/                     Repositorios Doctrine (Users, Domains)
└── Service/
    ├── LegacyAuthService.php       Autenticación legacy + auto-registro desde WinsisLab
    ├── UsersService.php            Lógica de usuarios (login, CRUD, email, recuperación)
    ├── ResultsService.php          Consultas a WinsisLab (solicitudes, exámenes, dashboard)
    ├── DomainsService.php          Parámetros (domains)
    ├── FirmsService.php            Firmas de PDFs
    ├── FileService.php             Subida/lectura/borrado de archivos (logos, uploads)
    ├── FtpService.php              Descarga de PDFs publicados por FTP
    └── PdfService.php              Render de PDF con dompdf
```

## Perfiles de usuario

| Tipo | Descripción | Acceso |
|---|---|---|
| `admin` | Administrador del sistema | Todos los módulos `/admin/*` + dashboard general |
| `company` | Empresa/cliente del laboratorio | Dashboard con sus solicitudes/pacientes/exámenes, búsqueda de pacientes, descarga de PDFs |
| `person` | Paciente | "Mis resultados" (sus solicitudes), descarga de PDFs con validación de pago |

El usuario se guarda completo en sesión (`$session->set('user', $user)`) junto con `type`. No se usa el firewall de seguridad de Symfony (`security: false` en `security.yaml`); el control de acceso es manual vía `sessionUser()`/`requireSession()`.

## Autenticación y login

1. `POST /login_check` con `_username`, `_password`, `_identification_type` (CC/TI/NI/CE/AS...).
2. `LegacyAuthService::authenticate()` busca en la tabla `users` (alpha) por identificación y contraseña en texto plano (esquema legacy). Según el tipo de documento puede requerir también el código de cliente para empresas.
3. Si no existe, **auto-registro**: busca el paciente/empresa en WinsisLab (beta) por identificación y crea el registro en `users` (con correo si lo encuentra).
4. Protección anti fuerza bruta: 5 intentos fallidos → bloqueo 5 minutos por sesión (`login_blocked_until`).
5. Al ingresar se limpian intentos fallidos y se guardan `type` + `user` en sesión.

Reglas para duplicados (cambios TI→CC, registros históricos):

- `bestUserForLogin()`: mejor registro para login por identificación sin filtrar tipo (evita duplicados al cambiar tipo de documento).
- `bestPatientRow()`: mejor registro de paciente entre todos los ingresos (descarta basura como ". ,", ".. ..").
- `emailForUser()` / `emailsFromWinsislab()`: el correo mostrado en listados/edición se resuelve desde WinsisLab (beta) por identificación sola; nunca se escribe sobre la base de resultados.

## Resultados (WinsisLab, beta, solo lectura)

`ResultsService` encapsula todas las consultas a PostgreSQL:

- `findByIdentification()`: solicitudes de un paciente por identificación (sin filtrar tipo de documento, cubre TI→CC).
- `findByParameter()`: búsqueda combinada (identificación, tipo, nombres, fechas) para admin/empresa. Exige al menos un filtro para evitar consultas masivas de 25s.
- `findByRequest()`: detalle de una solicitud (secciones, exámenes, valores, rangos, textos descriptivos).
- `findPacienteExamenes()`: texto plano de exámenes de un paciente.
- `dashboardStats()`: estadísticas por perfil — `company`: solicitudes, pacientes, exámenes del cliente; `person`: solicitudes y exámenes propios; `admin`: devuelve `{requests:0, exams:0}` (sin métricas globales por ahora).
- `isPaid()`: validación de pago/cobro de una solicitud (controla la descarga de PDF).

## Generación de PDF

Flujo en `ResultsController::generatePdf()` (`GET /result/pdf/{requestCode}`):

1. Valida sesión y (para paciente) el pago vía `isPaid()`.
2. Si la solicitud tiene URL de archivo publicada (FTP), `FtpService::downloadPdf()` la descarga.
3. Si no, `PdfService::render()` genera el PDF localmente con **dompdf**: plantilla `templates/pdf/result.html.twig` con código de barras (tecnickcom/tc-lib-barcode), firmas desde `firms`, textos descriptivos y marca de agua para resultados preliminares.

## Subidas de archivos (logos, resultados)

- `FileApiController::saveUpload()`: valida extensión, MIME real (`finfo`) y tamaño máximo (5 MB por defecto); guarda en `public/static/upload/` y devuelve la ruta relativa.
- `FileApiController::files()`: sirve archivos del directorio estático con protección de path traversal (`realpath` + prefijo `static/`).
- `AdminController::uploadResult()` (`POST /upload_result/resultados`): sube PDFs de resultados; `uploadResultFtp()` (`POST /upload_result_ftp`) los envía al FTP de publicación.

## Frontend

- `templates/modern/base.html.twig`: layout base — tema claro/oscuro (`data-bs-theme`), modal de confirmación (`showConfirm()`), helper `apiFetch()` con redirección a login en 401, menús desplegables `data-dd-toggle`/`data-dd-menu` renderizados dentro de `document.body` (escapan de overflow/stacking contexts).
- Módulos en `templates/admin/*.html.twig` (pacientes, clientes, firmas, configuración, resultados) con: tablas paginadas, filtros con debounce (300 ms), filtro de estado activo/inactivo, modal de edición con correo correcto desde WinsisLab y exportación CSV con BOM UTF-8.
- `templates/home/full.html.twig`: vista de paciente/empresa (mis resultados + dashboard).
- `templates/security/login.html.twig` y `reset-password.html.twig`: autenticación y recuperación.
- Páginas de error personalizadas en `templates/bundles/TwigBundle/Exception/` (error, 403, 404).

## Convenciones de respuestas API

- `users`, `clients`, `domains` → `{content: [...], totalRecord: n}` (paginados).
- `firms`, resultados/búsquedas → `{items: [...], total_count: n}`.
- `/api/dashboard` → `{requests, patients, exams}` según perfil.
- Errores de sesión → `401 {"message": "No autorizado"}`.
- Errores de negocio → `400/404/500` con `{message}` o `{error}` descriptivo.
