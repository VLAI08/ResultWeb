<?php

namespace App\Service;

use App\Entity\Users;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class UsersService
{
    public const COMPANY_KEY = 'COMPANY_KEY';

    public function __construct(private ManagerRegistry $registry)
    {
    }

    private function alphaEm(): EntityManagerInterface
    {
        return $this->registry->getManager('alpha');
    }

    private function betaConn(): Connection
    {
        return $this->registry->getConnection('beta');
    }

    public function getCompanyKey(): ?string
    {
        $alpha = $this->registry->getConnection('alpha');
        $val = $alpha->fetchOne("SELECT valor FROM domains WHERE nombre = 'COMPANY_KEY' AND estado = 1 ORDER BY idioma LIMIT 1");
        return $val ?: null;
    }

    /**
     * Login con auto-registro (replica lab-results-api):
     * 1) usuario existente por (identification, identificationtype) → valida activo y contraseña.
     * 2) si no existe → determina tipo (empresa si el tipo de doc es COMPANY_KEY) y lo crea
     *    a partir de WinsisLab (clientes/paciente), con contraseña = número de identificación.
     */
    public function login(string $username, string $password, string $identificationType = 'CC'): array
    {
        $result = ['state' => '-112', 'message' => 'Error al ingresar, Datos Incorrectos'];

        $repo = $this->alphaEm()->getRepository(Users::class);
        $user = $repo->findOneBy(['identification' => $username, 'identificationtype' => $identificationType]);
        if (!$user) {
            $user = $this->autoRegister($username, $identificationType);
            if (!$user) {
                return $result;
            }
        }

        if (!$user->getActive()) {
            return ['state' => '-113', 'message' => 'Usuario no está activo'];
        }

        if ($user->getPassword() !== $password) {
            return $result;
        }

        // Si el correo está vacío (registros creados antes de capturar email), se completa
        // SOLO ese campo con el último ingreso en WinsisLab (beta solo se consulta).
        if ($user->getEmail() === '' || $user->getEmail() === null) {
            $email = $this->lastEmailFromWinsislab($username, $identificationType, $user->getType());
            if ($email !== '') {
                $user->setEmail($email);
                $this->persist($user);
            }
        }

        return ['state' => '111', 'message' => 'Logueado correctamente', 'user' => $user];
    }

    /**
     * Completa SOLO el campo email del usuario si está vacío, consultando el último ingreso
     * en WinsisLab (la BD beta nunca se modifica, únicamente se lee).
     */
    public function fillEmailIfMissing(int $id, string $identification, string $identificationType, ?string $type): void
    {
        $user = $this->findUserById($id);
        if (!$user || ($user->getEmail() ?? '') !== '') {
            return;
        }
        $email = $this->lastEmailFromWinsislab($identification, $identificationType, $type);
        if ($email !== '') {
            $user->setEmail($email);
            $this->persist($user);
        }
    }

    /**
     * Consulta (sin escribir) el correo del último ingreso en WinsisLab.
     * paciente → public.paciente (person); clientes → public.clientes (company).
     */
    public function lastEmailFromWinsislab(string $identification, string $identificationType, ?string $type): string
    {
        try {
            if ($type === 'company') {
                $row = $this->betaConn()->fetchAssociative(
                    "SELECT email FROM public.clientes
                     WHERE nit = :nit AND tipodcto_cod = :td AND activo = true
                     LIMIT 1",
                    ['nit' => $identification, 'td' => $identificationType]
                );
            } else {
                $row = $this->betaConn()->fetchAssociative(
                    "SELECT email FROM public.paciente
                     WHERE historia = :h AND tipodcto_cod = :td
                     ORDER BY fecha DESC LIMIT 1",
                    ['h' => $identification, 'td' => $identificationType]
                );
            }
        } catch (\Throwable $e) {
            return '';
        }
        return trim((string) ($row['email'] ?? ''));
    }

    /**
     * Crea el usuario automáticamente desde WinsisLab cuando no existe en la tabla users.
     */
    private function autoRegister(string $identification, string $identificationType): ?Users
    {
        $companyKey = $this->getCompanyKey();
        $isCompany = $companyKey !== null && $companyKey === $identificationType;

        if ($isCompany) {
            $row = $this->betaConn()->fetchAssociative(
                "SELECT nit, razon, clte_codigo, tipodcto_cod, telefono, direccion, email
                 FROM public.clientes
                 WHERE nit = :nit AND tipodcto_cod = :td AND activo = true",
                ['nit' => $identification, 'td' => $identificationType]
            );
            if (!$row) {
                return null;
            }
            $u = new Users();
            $u->setActive(true)
                ->setType('company')
                ->setCode((string) ($row['clte_codigo'] ?? ''))
                ->setNames((string) ($row['razon'] ?? ''))
                ->setIdentification($row['nit'] ?? $identification)
                ->setIdentificationtype($row['tipodcto_cod'] ?? $identificationType)
                ->setEmail((string) ($row['email'] ?? ''))
                ->setDownloadOptions('si')
                ->setLogoOptions('logo_sta')
                ->setPhones((string) ($row['telefono'] ?? ''))
                ->setAddress((string) ($row['direccion'] ?? ''))
                ->setTypeAdmin('buscar_resultados,actualizar_datos_cliente')
                ->setIsadmin(false)
                ->setPassword($identification);
            $this->persist($u);
            return $u;
        }

        $row = $this->betaConn()->fetchAssociative(
            "SELECT historia, nom1, ape1, tipodcto_cod, telefono, direccion, sexo, email
             FROM public.paciente
             WHERE historia = :h AND tipodcto_cod = :td
             ORDER BY fecha DESC LIMIT 1",
            ['h' => $identification, 'td' => $identificationType]
        );
        if (!$row) {
            return null;
        }
        $u = new Users();
        $u->setActive(true)
            ->setType('person')
            ->setNames((string) ($row['nom1'] ?? ''))
            ->setLastnames((string) ($row['ape1'] ?? ''))
            ->setIdentification($row['historia'] ?? $identification)
            ->setIdentificationtype($row['tipodcto_cod'] ?? $identificationType)
            ->setSex((string) ($row['sexo'] ?? ''))
            ->setDownloadOptions('si')
            ->setPhones((string) ($row['telefono'] ?? ''))
            ->setAddress((string) ($row['direccion'] ?? ''))
            ->setTypeAdmin('mis_resultados,actualizar_datos_paciente')
            ->setIsadmin(false)
            ->setEmail((string) ($row['email'] ?? ''))
            ->setPassword($identification);
        $this->persist($u);
        return $u;
    }

    public function persist(Users $user): void
    {
        $em = $this->alphaEm();
        $em->persist($user);
        $em->flush();
    }

    public function findUserByIdentification(string $ident, ?string $identType = null): ?Users
    {
        $repo = $this->alphaEm()->getRepository(Users::class);
        if ($identType) {
            return $repo->findOneBy(['identification' => $ident, 'identificationtype' => $identType, 'isadmin' => false]);
        }
        return $repo->findOneBy(['identification' => $ident, 'isadmin' => false]);
    }

    public function findUserById(int $id): ?Users
    {
        return $this->alphaEm()->getRepository(Users::class)->find($id);
    }

    /**
     * Usuario cuyo listado de códigos de empresa (code) incluya $clientCode.
     */
    public function findUserByClientCode(string $clientCode): ?Users
    {
        $rows = $this->alphaEm()->getConnection()->fetchAllAssociative(
            "SELECT * FROM users WHERE active = 1 AND code LIKE :like ORDER BY id LIMIT 50",
            ['like' => '%' . $clientCode . '%']
        );
        foreach ($rows as $row) {
            $codes = array_filter(array_map('trim', explode(',', (string) ($row['code'] ?? ''))));
            if (in_array($clientCode, $codes, true)) {
                $users = $this->alphaEm()->getRepository(Users::class)->findBy(['id' => $row['id']]);
                return $users ? $users[0] : null;
            }
        }
        return null;
    }

    /**
     * Resuelve una ruta relativa de logo/footer (urlimg/footer) al path real en public/.
     */
    public function resolveUploadPath(?string $relative): ?string
    {
        if (!$relative) {
            return null;
        }
        $relative = str_replace('\\', '/', ltrim($relative, '/'));
        if (str_starts_with($relative, 'static/')) {
            $relative = substr($relative, 7);
        }
        $path = dirname(__DIR__, 2) . '/public/static/' . $relative;
        return is_file($path) ? $path : null;
    }

    public function changePassword(int $id, string $currentPassword, string $newPassword): array
    {
        $user = $this->findUserById($id);
        if (!$user) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }
        if ($user->getPassword() !== $currentPassword) {
            return ['success' => false, 'message' => 'La contraseña actual no es correcta'];
        }
        $user->setPassword($newPassword);
        $user->setPasswordChanged(true);
        $this->persist($user);
        return ['success' => true, 'message' => 'Contraseña actualizada'];
    }

    /**
     * Genera un código de verificación de 6 dígitos válido por 10 minutos (replica auth.service de V2026).
     */
    public function requestResetCode(string $email): array
    {
        $user = $this->alphaEm()->getRepository(Users::class)->findOneBy([
            'email' => $email,
            'type' => 'person',
            'active' => true,
        ]);
        if (!$user) {
            return ['success' => false, 'code' => null];
        }
        $code = (string) random_int(100000, 999999);
        $user->setResetCode($code);
        $user->setCodeExpiresAt(new \DateTimeImmutable('+10 minutes'));
        $this->persist($user);
        return ['success' => true, 'code' => $code, 'names' => $user->getNames()];
    }

    /**
     * Valida el código (vigencia 10 minutos) y actualiza la contraseña marcando password_changed.
     */
    public function resetPassword(string $email, string $code, string $password): array
    {
        $user = $this->alphaEm()->getRepository(Users::class)->findOneBy([
            'email' => $email,
            'type' => 'person',
            'reset_code' => $code,
        ]);
        if (!$user) {
            return ['success' => false, 'message' => 'No se encontró un usuario asociado con el código ingresado. Verifica los datos e intenta nuevamente.'];
        }
        $expires = $user->getCodeExpiresAt();
        if ($expires && $expires < new \DateTimeImmutable()) {
            return ['success' => false, 'message' => 'Tu código de verificación ha expirado. Solicita uno nuevo para continuar.'];
        }
        $user->setResetCode(null);
        $user->setCodeExpiresAt(null);
        $user->setPassword($password);
        $user->setPasswordChanged(true);
        $this->persist($user);
        return ['success' => true, 'message' => 'Contraseña actualizada correctamente'];
    }

    public function updateProfile(int $id, array $data): array
    {
        $user = $this->findUserById($id);
        if (!$user) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }
        if (array_key_exists('names', $data)) {
            $user->setNames((string) $data['names']);
        }
        if (array_key_exists('lastnames', $data)) {
            $user->setLastnames((string) $data['lastnames']);
        }
        if (array_key_exists('phones', $data)) {
            $user->setPhones((string) $data['phones']);
        }
        if (array_key_exists('email', $data)) {
            $user->setEmail((string) $data['email']);
        }
        if (array_key_exists('contact', $data)) {
            $user->setContact((string) $data['contact']);
        }
        if (array_key_exists('phone_contact', $data)) {
            $user->setPhoneContact((string) $data['phone_contact']);
        }
        if (array_key_exists('address', $data)) {
            $user->setAddress((string) $data['address']);
        }
        if (array_key_exists('sex', $data)) {
            $user->setSex((string) $data['sex']);
        }
        $this->persist($user);
        return ['success' => true, 'user' => $user->toArray()];
    }

    /**
     * Lista paginada de usuarios con búsqueda por parámetro.
     */
    public function findAll(?int $page = null, ?int $limit = null, string $parameter = '', ?string $type = null, ?bool $active = null): array
    {
        $where = [];
        $params = [];
        if ($parameter !== '') {
            $where[] = '(names LIKE :p OR lastnames LIKE :p OR identification LIKE :p OR code LIKE :p OR email LIKE :p)';
            $params['p'] = '%' . $parameter . '%';
        }
        if ($type !== null && $type !== '') {
            $where[] = 'type = :type';
            $params['type'] = $type;
        }
        if ($active !== null) {
            $where[] = 'active = :active';
            $params['active'] = $active ? 1 : 0;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = (int) $this->alphaEm()->getConnection()->fetchOne("SELECT COUNT(*) FROM users $whereSql", $params);

        $limitSql = '';
        if ($page !== null && $limit !== null) {
            $limit = max(1, (int) $limit);
            $offset = max(0, ((int) $page - 1) * $limit);
            $limitSql = 'LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $rows = $this->alphaEm()->getConnection()->fetchAllAssociative(
            "SELECT id, names, lastnames, identification, identificationtype, phones, email, type,
                    isadmin, active, code, download_options, logo_options, type_admin, address, sex, contact, phone_contact
             FROM users $whereSql ORDER BY id DESC $limitSql",
            $params
        );

        // Correo del último ingreso en WinsisLab para registros sin email (solo lectura, sin persistir).
        $emails = $this->emailsFromWinsislab($rows);
        if ($emails) {
            foreach ($rows as &$r) {
                $key = (string) ($r['identification'] ?? '') . '|' . (string) ($r['identificationtype'] ?? '');
                if (trim((string) ($r['email'] ?? '')) === '' && isset($emails[$key])) {
                    $r['email'] = $emails[$key];
                }
            }
        }

        return ['total_count' => $count, 'items' => $rows];
    }

    /**
     * Correo del último ingreso en WinsisLab para un usuario (solo lectura).
     * Devuelve '' si el usuario ya tiene correo o no se encuentra en WinsisLab.
     */
    public function emailForUser(Users $user): string
    {
        $email = trim((string) $user->getEmail());
        if ($email !== '') {
            return $email;
        }
        $map = $this->emailsFromWinsislab([[
            'identification' => $user->getIdentification(),
            'identificationtype' => $user->getIdentificationtype(),
            'type' => $user->getType(),
            'email' => '',
        ]]);
        return $map[($user->getIdentification() ?? '') . '|' . ($user->getIdentificationtype() ?? '')] ?? '';
    }

    /**
     * Consulta (sin escribir) el correo del último ingreso en WinsisLab para los
     * registros con email vacío. Devuelve un mapa "identificacion|tipodcto" => email.
     */
    public function emailsFromWinsislab(array $rows): array
    {
        $persons = [];
        $companies = [];
        foreach ($rows as $r) {
            if (trim((string) ($r['email'] ?? '')) !== '') {
                continue;
            }
            $id = (string) ($r['identification'] ?? '');
            $td = (string) ($r['identificationtype'] ?? '');
            if ($id === '' || $td === '') {
                continue;
            }
            if (($r['type'] ?? '') === 'company') {
                $companies[$id . '|' . $td] = true;
            } else {
                $persons[$id . '|' . $td] = true;
            }
        }

        $map = [];
        if ($persons) {
            try {
                $in = implode(',', array_map(fn (string $k) => "('" . str_replace("'", "''", (string) explode('|', $k)[0]) . "','" . str_replace("'", "''", (string) explode('|', $k)[1]) . "')", array_keys($persons)));
                $rowsP = $this->betaConn()->fetchAllAssociative(
                    "SELECT DISTINCT ON (historia, tipodcto_cod) historia, tipodcto_cod, email
                     FROM public.paciente
                     WHERE (historia, tipodcto_cod) IN ($in)
                       AND email IS NOT NULL AND email != ''
                     ORDER BY historia, tipodcto_cod, fecha DESC, hora DESC"
                );
                foreach ($rowsP as $row) {
                    $map[$row['historia'] . '|' . $row['tipodcto_cod']] = (string) $row['email'];
                }
            } catch (\Throwable $e) {
                // WinsisLab es solo lectura: si la consulta falla, se continúa sin correo.
            }
        }
        if ($companies) {
            try {
                $in = implode(',', array_map(fn (string $k) => "('" . str_replace("'", "''", (string) explode('|', $k)[0]) . "','" . str_replace("'", "''", (string) explode('|', $k)[1]) . "')", array_keys($companies)));
                $rowsC = $this->betaConn()->fetchAllAssociative(
                    "SELECT nit, tipodcto_cod, email
                     FROM public.clientes
                     WHERE (nit, tipodcto_cod) IN ($in) AND activo = true
                       AND email IS NOT NULL AND email != ''"
                );
                foreach ($rowsC as $row) {
                    $map[$row['nit'] . '|' . $row['tipodcto_cod']] = (string) $row['email'];
                }
            } catch (\Throwable $e) {
                // WinsisLab es solo lectura: si la consulta falla, se continúa sin correo.
            }
        }
        return $map;
    }

    public function create(array $data): array
    {
        $em = $this->alphaEm();
        $existing = $this->findUserByIdentification((string) ($data['identification'] ?? ''), (string) ($data['identificationtype'] ?? 'CC'));
        if ($existing) {
            return ['success' => false, 'message' => 'Ya existe un usuario con esa identificación'];
        }
        $u = new Users();
        $u->setActive((bool) ($data['active'] ?? true));
        $u->setType($data['type'] ?? 'person');
        $u->setCode((string) ($data['code'] ?? ''));
        $u->setNames((string) ($data['names'] ?? ''));
        $u->setLastnames((string) ($data['lastnames'] ?? ''));
        $u->setIdentification((string) ($data['identification'] ?? ''));
        $u->setIdentificationtype((string) ($data['identificationtype'] ?? 'CC'));
        $u->setPhones((string) ($data['phones'] ?? ''));
        $u->setEmail((string) ($data['email'] ?? ''));
        $u->setContact((string) ($data['contact'] ?? ''));
        $u->setPhoneContact((string) ($data['phone_contact'] ?? ''));
        $u->setAddress((string) ($data['address'] ?? ''));
        $u->setSex((string) ($data['sex'] ?? ''));
        $u->setDownloadOptions((string) ($data['download_options'] ?? 'si'));
        $u->setLogoOptions((string) ($data['logo_options'] ?? ''));
        $u->setUrlimg((string) ($data['urlimg'] ?? ''));
        $u->setFooter((string) ($data['footer'] ?? ''));
        $u->setTypeAdmin((string) ($data['type_admin'] ?? ($data['type'] === 'company' ? 'buscar_resultados,actualizar_datos_cliente' : 'mis_resultados,actualizar_datos_paciente')));
        $u->setIsadmin(false);
        $u->setPassword((string) ($data['password'] ?? $data['identification'] ?? ''));
        $em->persist($u);
        $em->flush();
        return ['success' => true, 'user' => $u->toArray()];
    }

    public function update(int $id, array $data): array
    {
        $user = $this->findUserById($id);
        if (!$user) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }
        foreach ([
            'names' => 'setNames', 'lastnames' => 'setLastnames', 'phones' => 'setPhones',
            'email' => 'setEmail', 'contact' => 'setContact', 'phone_contact' => 'setPhoneContact',
            'address' => 'setAddress', 'sex' => 'setSex', 'code' => 'setCode',
            'identification' => 'setIdentification', 'identificationtype' => 'setIdentificationtype',
            'type' => 'setType', 'download_options' => 'setDownloadOptions',
            'logo_options' => 'setLogoOptions', 'type_admin' => 'setTypeAdmin',
            'urlimg' => 'setUrlimg', 'footer' => 'setFooter',
        ] as $field => $setter) {
            if (array_key_exists($field, $data)) {
                $user->{$setter}((string) $data[$field]);
            }
        }
        if (array_key_exists('active', $data)) {
            $user->setActive((bool) $data['active']);
        }
        if (array_key_exists('password', $data) && $data['password'] !== '') {
            $user->setPassword((string) $data['password']);
        }
        $this->persist($user);
        return ['success' => true, 'user' => $user->toArray()];
    }

    public function deactivate(int $id): array
    {
        $user = $this->findUserById($id);
        if (!$user) {
            return ['success' => false, 'message' => 'Usuario no encontrado'];
        }
        $user->setActive(false);
        $this->persist($user);
        return ['success' => true];
    }
}
