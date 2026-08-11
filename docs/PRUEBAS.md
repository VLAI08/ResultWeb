# Pruebas manuales

## Credenciales de prueba (entorno local)

| Perfil | Usuario | Contraseña | Tipo doc. | Notas |
|---|---|---|---|---|
| Administrador | `admin` | `LcslResult2023$` | CC | Acceso total |
| Paciente | `23008709` | `23008709` | CC | Dashboard de paciente |
| Empresa (cliente) | `900701859-2` | `900701859-2` | NI | OPTYMUS, dashboard de empresa |

### Casos especiales de pacientes

| Identificación | Tipo | Qué valida |
|---|---|---|
| `1048460045` | CC | Dos usuarios duplicados; el mejor registro es id `277527` |
| `1044911324` | CC | Email vacío en DB → se resuelve desde WinsisLab (histórico) |
| `1143350248` | AS/CC | Misma persona con tipos de documento AS y CC |
| `45547928` | CC/CE | Misma persona con tipos CC y CE |

## Checklist de verificación

### Autenticación
- [ ] Login admin → redirige a `/admin/pacientes`
- [ ] Login paciente (`23008709`) → "Mis resultados" + dashboard con sus solicitudes
- [ ] Login empresa (`900701859-2`) → dashboard con solicitudes/pacientes/exámenes del cliente
- [ ] 5 intentos fallidos → bloqueo "Intente nuevamente en 5 minutos"
- [ ] `POST /request_reset_password` con email registrado → `debug_code` en dev
- [ ] `POST /reset_password` con código y contraseña nueva → login con la nueva

### Módulos admin
- [ ] `/admin/pacientes`: listado paginado, búsqueda con debounce, filtro activo/inactivo, modal edición (muestra email correcto), export CSV
- [ ] `/admin/clientes`: CRUD, logo, modal edición
- [ ] `/admin/firmas`: CRUD de firmas de PDF
- [ ] `/admin/configuracion`: CRUD de dominios (parámetros)
- [ ] `/admin/resultados`: búsqueda combinada, subida de PDF, publicación FTP

### Resultados (paciente/empresa)
- [ ] `/resultCrud` con identificación de sesión → lista de solicitudes
- [ ] `/results/labs` con filtro → resultados; sin filtro → error "Debe seleccionar por lo menos un filtro"
- [ ] `/result/detail/{requestCode}` → secciones y exámenes con valores
- [ ] `/result/pdf/{requestCode}` → PDF válido (abrir y verificar contenido, código de barras, firmas)
- [ ] `/valid_result` → estado de pago correcto

### Seguridad
- [ ] APIs sin sesión → `401 {"message":"No autorizado"}`
- [ ] `/api/files?path=../../etc/passwd` → rechazado (path traversal)
- [ ] Subida de archivo con extensión falsa (p. ej. `.php` renombrado) → rechazado por MIME
- [ ] Página inexistente → 404 personalizada en prod

## Verificación rápida con curl/PowerShell

```powershell
# Login y prueba de módulos (campos correctos: _username/_password)
$body = @{_username='admin';_password='LcslResult2023$';_identification_type='CC'}
$null = Invoke-WebRequest "http://127.0.0.1:8090/login" -UseBasicParsing -SessionVariable s
Invoke-WebRequest "http://127.0.0.1:8090/login_check" -Method Post -Body $body -UseBasicParsing -WebSession $s
# login_check responde {"state":"111","message":"Bienvenido",...}

# Módulos → todos 200
foreach($m in @('/admin/pacientes','/admin/clientes','/admin/firmas','/admin/configuracion','/admin/resultados','/resultCrud','/api/dashboard')){
  (Invoke-WebRequest "http://127.0.0.1:8090$m" -UseBasicParsing -WebSession $s).StatusCode
}
```

## Comandos de validación del código

```bash
php bin/console lint:twig templates        # 13 plantillas válidas
php bin/console lint:container             # container dev
php bin/console lint:container --env=prod  # container prod
php bin/console cache:clear                # limpiar caché dev
php bin/console cache:clear --env=prod     # limpiar caché prod
```

## Pruebas con navegador real (opcional)

Usar Puppeteer (nodo) con Edge:

```js
const puppeteer = require('puppeteer-core');
const browser = await puppeteer.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
// login, abrir módulos, verificar dropdowns, modal edición, modo oscuro
```
