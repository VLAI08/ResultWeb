<?php

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;

/**
 * Servicio de autenticación configurable para reutilizar la BD legacy sin acoplar a un esquema fijo.
 *
 * Orden de validación:
 * 1. LEGACY_AUTH_SQL (si está configurado): consulta personalizada con :username/:password.
 * 2. Tabla users (alpha) vía UsersService::login (incluye admins y auto-registro desde WinsisLab).
 * 3. LEGACY_ADMIN_AUTH_SQL (si está configurado).
 */
class LegacyAuthService
{
    public function __construct(private ManagerRegistry $registry, private UsersService $usersService)
    {
    }

    /**
     * Devuelve arreglo 'user' compatible con el contrato legacy o null si no valida.
     */
    public function authenticate(string $username, string $password, string $identificationType = 'CC'): ?array
    {
        $row = null;

        // 1. SQL personalizado (opcional)
        $sql = $_ENV['LEGACY_AUTH_SQL'] ?? $_SERVER['LEGACY_AUTH_SQL'] ?? null;
        if ($sql) {
            $connName = $_ENV['LEGACY_AUTH_CONNECTION'] ?? $_SERVER['LEGACY_AUTH_CONNECTION'] ?? 'alpha';
            $conn = $this->registry->getConnection($connName);
            $row = $conn->fetchAssociative($sql, [
                'username' => $username,
                'password' => $password,
            ]);
            // Si hay usuarios duplicados con la misma identificación (nombres variados),
            // se prefiere el activo con el nombre más completo.
            if ($row && !empty($row['id']) && (($row['type'] ?? '') !== 'admin')) {
                $best = $this->usersService->bestUserForLogin($username, $identificationType);
                if ($best) {
                    $row = $best;
                }
            }
        }

        // 2. Tabla users + auto-registro (WinsisLab)
        if (!$row) {
            try {
                $res = $this->usersService->login($username, $password, $identificationType);
                if (($res['state'] ?? '') === '111' && isset($res['user'])) {
                    $u = $res['user'];
                    $row = [
                        'id' => method_exists($u, 'getId') ? $u->getId() : null,
                        'type' => method_exists($u, 'getType') ? (string) $u->getType() : 'person',
                        'code' => method_exists($u, 'getCode') ? $u->getCode() : '',
                        'identification' => method_exists($u, 'getIdentification') ? $u->getIdentification() : $username,
                        'identificationtype' => method_exists($u, 'getIdentificationtype') ? $u->getIdentificationtype() : 'CC',
                        'download_options' => method_exists($u, 'getDownloadOptions') ? $u->getDownloadOptions() : 'S',
                        'logo_options' => method_exists($u, 'getLogoOptions') ? $u->getLogoOptions() : 'default',
                        'names' => method_exists($u, 'getNames') ? $u->getNames() : '',
                        'lastnames' => method_exists($u, 'getLastnames') ? $u->getLastnames() : '',
                        'address' => method_exists($u, 'getAddress') ? $u->getAddress() : '',
                        'phones' => method_exists($u, 'getPhones') ? $u->getPhones() : '',
                        'sex' => method_exists($u, 'getSex') ? $u->getSex() : '',
                        'type_admin' => method_exists($u, 'getTypeAdmin') ? $u->getTypeAdmin() : '',
                        'isadmin' => method_exists($u, 'getIsadmin') ? $u->getIsadmin() : false,
                        'password_changed' => method_exists($u, 'getPasswordChanged') ? (bool) $u->getPasswordChanged() : false,
                    ];
                } else {
                    return null;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        // 3. SQL alterno para administradores (opcional)
        if (!$row) {
            $adminSql = $_ENV['LEGACY_ADMIN_AUTH_SQL'] ?? $_SERVER['LEGACY_ADMIN_AUTH_SQL'] ?? null;
            if ($adminSql) {
                $adminConnName = $_ENV['LEGACY_ADMIN_AUTH_CONNECTION'] ?? $_SERVER['LEGACY_ADMIN_AUTH_CONNECTION'] ?? 'alpha';
                $adminConn = $this->registry->getConnection($adminConnName);
                $row = $adminConn->fetchAssociative($adminSql, [
                    'username' => $username,
                    'password' => $password,
                ]);
            }
        }

        if (!$row) {
            return null;
        }

        // Normalizamos claves esperadas por el frontend
        $isAdmin = (bool) ($row['isadmin'] ?? false);
        $type = (string) ($row['type'] ?? ($isAdmin ? 'admin' : 'person'));
        if ($type === '' && $isAdmin) {
            $type = 'admin';
        }

        // Correo del último ingreso en WinsisLab si el usuario no lo tiene registrado
        // (solo se consulta la BD beta; se actualiza únicamente ese campo en la tabla users).
        if (!empty($row['id']) && ($row['email'] ?? '') === '') {
            $this->usersService->fillEmailIfMissing((int) $row['id'], $username, $identificationType, $type);
            $filled = $this->usersService->findUserById((int) $row['id']);
            if ($filled) {
                $row['email'] = (string) $filled->getEmail();
            }
        }

        $user = [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $type,
            'code' => (string) ($row['code'] ?? ''),
            'identification' => (string) ($row['identification'] ?? $username),
            'identificationtype' => (string) ($row['identificationtype'] ?? $identificationType),
            'email' => (string) ($row['email'] ?? ''),
            'download_options' => (string) ($row['download_options'] ?? 'S'),
            'logo_options' => (string) ($row['logo_options'] ?? 'default'),
            'names' => (string) ($row['names'] ?? ''),
            'lastnames' => (string) ($row['lastnames'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
            'phones' => (string) ($row['phones'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
            'isadmin' => $isAdmin,
            'password_changed' => (bool) ($row['password_changed'] ?? false),
        ];
        // Acciones (type_admin): lista separada por comas
        if (isset($row['type_admin']) && $row['type_admin'] !== null && $row['type_admin'] !== '') {
            $user['actions'] = array_values(array_filter(array_map('trim', explode(',', (string) $row['type_admin'])), 'strlen'));
        } elseif ($isAdmin) {
            $user['actions'] = ['mis_resultados', 'buscar_resultados', 'actualizar_datos_paciente', 'actualizar_datos_cliente'];
        } else {
            $user['actions'] = $type === 'company'
                ? ['buscar_resultados', 'actualizar_datos_cliente']
                : ['mis_resultados', 'actualizar_datos_paciente'];
        }
        return $user;
    }
}
