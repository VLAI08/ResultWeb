# Despliegue

## Entornos

La app detecta el entorno por `APP_ENV` (archivo `.env`). En producción debe ser `prod`.

> **Importante (servidor integrado)**: si `variables_order` del php.ini no incluye la letra `E` (entorno), las variables de entorno no se cargan y la app inicia en `dev`. Para servidores integrados use:

```bash
set APP_ENV=prod
php -d variables_order=EGPCS -S 127.0.0.1:8091 C:\Users\SOPORTE\AppData\Local\Temp\opencode\sf-router.php
```

**Cómo detectar el entorno**: en `dev`, una excepción muestra el profiler de Symfony; en `prod` muestra la página de error personalizada. Alternativa: tocar `var/log/prod.log` — si se crea/escribe, el entorno es `prod`.

## Desarrollo

```bash
php -S 127.0.0.1:8090 -t public
```

(El puerto 8080 puede estar ocupado por otros servicios locales.)

## Producción

1. Configurar `.env` (o `.env.local`) con `APP_ENV=prod` y las credenciales reales (ver README → Configuración).
2. Limpiar y precargar caché:
   ```bash
   php bin/console cache:clear --env=prod
   ```
3. Asegurarse de que `var/` y `public/static/upload/` tengan permisos de escritura.
4. Apuntar el documento raíz del servidor web a `public/`.

### Apache (vhost)

```apache
<VirtualHost *:80>
    ServerName resultados.midominio.com
    DocumentRoot "C:\xampp\htdocs\resultados\resultados\symfony44\public"
    <Directory "C:\xampp\htdocs\resultados\resultados\symfony44\public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 80;
    server_name resultados.midominio.com;
    root /ruta/symfony44/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }
    location ~ \.php$ { return 404; }
}
```

## Notas de operación

- **Solo lectura en WinsisLab (PostgreSQL)**: el sistema nunca escribe sobre la base de resultados. Toda escritura va a la tabla `users` (alpha/MySQL) en campos puntuales (estado, contraseña, correo de contacto).
- **No alterar índices/DDL** de las bases reales; búsquedas de pacientes pueden tardar ~2 s por la estructura actual de WinsisLab.
- **Logs**: `var/log/dev.log` (dev) y `var/log/prod.log` (prod). Limpiarlos en cada sesión de pruebas.
- **Subidas**: `public/static/upload/` (logos de clientes, PDFs de resultados). Validación MIME + tamaño.
- **FTP**: para descargar PDFs publicados (`FTP_HOST/FTP_USER/FTP_PASS/FTP_PORT` en `.env`); si no está configurado, el sistema genera el PDF localmente con dompdf.
- **Git**: el repo (ResultWeb, privado) NO incluye `.env*`, `vendor/`, `var/`, `composer.phar` ni las subidas/imágenes de marca.
