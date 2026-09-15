<?php

namespace App\Controller;

use App\Service\LegacyAuthService;
use App\Service\UsersService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SecurityController extends AbstractController
{
    public function __construct(private LegacyAuthService $auth, private UsersService $usersService, private \App\Service\DomainsService $domains)
    {
    }

    #[Route('/login', name: 'security_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        if ($request->getSession()->has('user')) {
            return $this->redirectToRoute('root');
        }
        return $this->render('security/login.html.twig', [
            'identification_types' => $this->domains->listDomainsActive('identificationtype'),
        ]);
    }

    /**
     * Login compatible con el legacy (loginAdminView.js) y con el flujo nuevo:
     * POST con _username, _password y _identification_type.
     * Incluye auto-registro de pacientes/empresas desde WinsisLab.
     * Incluye protección anti fuerza bruta (5 intentos fallidos → 5 minutos de bloqueo).
     */
    #[Route('/login_check', name: 'security_login_check', methods: ['POST'])]
    public function loginCheck(Request $request): JsonResponse
    {
        $username = (string) $request->request->get('_username');
        $password = (string) $request->request->get('_password');
        $identificationType = (string) $request->request->get('_identification_type', 'CC');

        if (!$this->isCsrfTokenValid('authenticate', (string) $request->request->get('_csrf_token'))) {
            return $this->json([
                'state' => '000',
                'message' => 'Sesión inválida. Recargue la página e intente de nuevo.',
            ]);
        }

        if ($username === '' || $password === '') {
            return $this->json([
                'state' => '000',
                'message' => 'Debe ingresar usuario y contraseña',
            ]);
        }

        $session = $request->getSession();
        $blockedUntil = (int) $session->get('login_blocked_until', 0);
        if ($blockedUntil > time()) {
            $mins = (int) ceil(($blockedUntil - time()) / 60);
            return $this->json([
                'state' => '000',
                'message' => 'Demasiados intentos fallidos. Intente nuevamente en ' . $mins . ' minuto(s).',
            ]);
        }

        try {
            $user = $this->auth->authenticate($username, $password, $identificationType);
        } catch (\Throwable $e) {
            $user = null;
            try {
                $dir = $this->getParameter('kernel.project_dir') . '/var/log';
                file_put_contents($dir . '/login-error.log', date('Y-m-d H:i:s') . ' | ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND | LOCK_EX);
            } catch (\Throwable $ignored) {
            }
        }

        if (!$user) {
            $this->accessLog($request, $username, false);
            $attempts = (int) $session->get('login_attempts', 0) + 1;
            $session->set('login_attempts', $attempts);
            if ($attempts >= 5) {
                $session->set('login_blocked_until', time() + 300);
                $session->set('login_attempts', 0);
                $message = 'Demasiados intentos fallidos. Intente nuevamente en 5 minutos.';
            } else {
                $message = 'Usuario o contraseña incorrectos (' . $attempts . '/5 intentos)';
            }
            return $this->json(['state' => '000', 'message' => $message]);
        }

        $this->accessLog($request, $username, true);

        // Segundo factor (2FA) para administradores: solo si hay correo válido al cual enviar el código.
        $adminEmail = (string) ($user['email'] ?? '');
        if (($user['type'] ?? '') === 'admin' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $code = (string) random_int(100000, 999999);
            $session->set('2fa_pending', ['user' => $user, 'code' => $code, 'exp' => time() + 600]);
            $this->send2faEmail($user, $code);
            return $this->json([
                'state' => '1112',
                'message' => 'Código de verificación enviado a su correo. Tiene 10 minutos para ingresarlo.',
                'email_hint' => $this->maskEmail($adminEmail),
            ]);
        }

        $session->migrate(true);
        $session->remove('login_attempts');
        $session->remove('login_blocked_until');
        $session->set('type', $user['type']);
        $user['last_login'] = $this->lastLoginFor($username);
        $session->set('user', $user);
        $this->markLastLogin($username);

        return $this->json([
            'state' => '111',
            'message' => 'Bienvenido',
            'user' => $user,
        ]);
    }

    /**
     * Segundo paso del 2FA de administradores: valida el código de correo y completa la sesión.
     */
    #[Route('/verify_2fa', name: 'security_verify_2fa', methods: ['POST'])]
    public function verify2fa(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $pending = $session->get('2fa_pending');
        if (!$pending || (int) $pending['exp'] < time()) {
            $session->remove('2fa_pending');
            return $this->json(['state' => '000', 'message' => 'La verificación expiró. Inicie sesión nuevamente.']);
        }
        $code = trim((string) $request->request->get('code', ''));
        if ($code === '' || !hash_equals((string) $pending['code'], $code)) {
            return $this->json(['state' => '000', 'message' => 'Código incorrecto. Verifique su correo e intente de nuevo.']);
        }
        $user = $pending['user'];
        $session->remove('2fa_pending');
        $session->migrate(true);
        $session->set('type', $user['type'] ?? 'admin');
        $identification = (string) ($user['identification'] ?? '');
        $user['last_login'] = $this->lastLoginFor($identification);
        $session->set('user', $user);
        $this->markLastLogin($identification);
        return $this->json(['state' => '111', 'message' => 'Bienvenido', 'user' => $user]);
    }

    /**
     * Bitácora de accesos (login exitosos y fallidos) en var/log/accesos-AAAA-MM.log.
     * Nunca interrumpe el flujo de login si falla la escritura.
     */
    private function accessLog(Request $request, string $username, bool $ok): void
    {
        try {
            $dir = $this->getParameter('kernel.project_dir') . '/var/log';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $entry = json_encode([
                'date' => date('Y-m-d H:i:s'),
                'ip' => $request->getClientIp(),
                'user' => $username,
                'ok' => $ok,
            ], JSON_UNESCAPED_UNICODE);
            file_put_contents($dir . '/accesos-' . date('Y-m') . '.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    private function lastLoginFor(string $identification): ?string
    {
        try {
            $file = $this->getParameter('kernel.project_dir') . '/var/log/ultimo-ingreso.json';
            if (!is_file($file)) { return null; }
            $map = json_decode((string) file_get_contents($file), true) ?: [];
            return $map[$identification] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function markLastLogin(string $identification): void
    {
        try {
            if ($identification === '') { return; }
            $file = $this->getParameter('kernel.project_dir') . '/var/log/ultimo-ingreso.json';
            $map = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $map[$identification] = date('Y-m-d H:i:s');
            file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (\Throwable $e) {
        }
    }

    private function send2faEmail(array $user, string $code): void
    {
        $email = (string) ($user['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return; }
        $names = htmlspecialchars((string) ($user['names'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html = "<div style=\"font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;\">
            <table width=\"100%\" style=\"max-width:600px;margin:auto;background:#ffffff;padding:20px;border-radius:8px;\">
            <tr><td style=\"text-align:center;\">
            <h1 style=\"color:#0B4F6C;\">Verificación de acceso</h1>
            <p style=\"font-size:16px;color:#333;\">Hola <strong>{$names}</strong>,</p>
            <p style=\"font-size:16px;color:#333;\">Use el siguiente código para completar su ingreso al portal de resultados:</p>
            <p style=\"font-size:26px;color:#0FA3B1;font-weight:bold;margin:20px 0;letter-spacing:6px;\">{$code}</p>
            <p style=\"font-size:14px;color:#777;\">Este código es válido por solo 10 minutos. Si no solicitó este acceso, ignore este mensaje y cambie su contraseña.</p>
            </td></tr></table></div>";
        @mail($email, 'Código de verificación - Resultados en línea', $html, "Content-Type: text/html; charset=UTF-8\r\nFrom: no-responder@labsantalucia.com.co");
    }

    private function maskEmail(string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'su correo registrado'; }
        [$u, $d] = explode('@', $email, 2);
        $masked = substr($u, 0, 2) . str_repeat('•', max(1, strlen($u) - 2));
        return $masked . '@' . $d;
    }

    /**
     * Cambio de contraseña (usuario autenticado).
     */
    #[Route('/change_password', name: 'change_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $user = $session->get('user');
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Sesión no válida']);
        }
        $current = (string) $request->request->get('_current_password', '');
        $new = (string) $request->request->get('_new_password', '');
        $confirm = (string) $request->request->get('_confirm_password', '');

        if (!$this->isCsrfTokenValid('change_pw', (string) $request->request->get('_csrf_token'))) {
            return $this->json(['success' => false, 'message' => 'Sesión inválida. Recargue la página e intente de nuevo.']);
        }

        if ($new === '' || $new !== $confirm) {
            return $this->json(['success' => false, 'message' => 'Las contraseñas no coinciden o están vacías']);
        }
        if ($new === $current) {
            return $this->json(['success' => false, 'message' => 'La nueva contraseña no puede ser igual a la actual. Por favor, verifica.']);
        }

        $result = $this->usersService->changePassword((int) $user['id'], $current, $new);
        if ($result['success']) {
            $user['password'] = $new;
            $user['password_changed'] = true;
            $session->set('user', $user);
            $session->remove('change_password');
        }
        return $this->json($result);
    }

    /**
     * Paso 1 de recuperación: envía código de verificación de 6 dígitos al correo (10 min de validez).
     */
    #[Route('/request_reset_password', name: 'request_reset_password', methods: ['POST'])]
    public function requestResetPassword(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->request->get('email', '')));
        if (!$this->isCsrfTokenValid('reset_pw', (string) $request->request->get('_csrf_token'))) {
            return $this->json(['success' => false, 'message' => 'Sesión inválida. Recargue la página e intente de nuevo.']);
        }
        if (trim((string) $request->request->get('website', '')) !== '') {
            // Honeypot: bots que rellenan campos ocultos. Respuesta idéntica a éxito real.
            return $this->json(['success' => true, 'message' => 'Si el correo está registrado, recibirá un código de verificación.']);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Ingrese un correo electrónico válido']);
        }

        // Throttle anti-abuso: 1 solicitud por minuto por correo y máximo 5 por hora por sesión.
        $session = $request->getSession();
        $now = time();
        $perEmailKey = 'pwreset_t_' . md5($email);
        if ($now - (int) $session->get($perEmailKey, 0) < 60) {
            return $this->json(['success' => false, 'message' => 'Acaba de solicitar un código. Espere un momento antes de reintentar.']);
        }
        $times = array_values(array_filter((array) $session->get('pwreset_times', []), fn ($t) => $t > $now - 3600));
        if (count($times) >= 5) {
            return $this->json(['success' => false, 'message' => 'Demasiadas solicitudes. Intente de nuevo en una hora.']);
        }
        $session->set($perEmailKey, $now);
        $times[] = $now;
        $session->set('pwreset_times', $times);

        $result = $this->usersService->requestResetCode($email);
        if (!$result['success']) {
            // No revelar la existencia del usuario (replica V2026: 404 silencioso)
            return $this->json(['success' => true, 'message' => 'Si el correo está registrado, recibirá un código de verificación.']);
        }

        $subject = 'Recuperación de contraseña - Resultados en línea';
        $names = htmlspecialchars((string) ($result['names'] ?? ''), ENT_QUOTES, 'UTF-8');
        $code = $result['code'];
        $html = "<div style=\"font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;\">
            <table width=\"100%\" style=\"max-width:600px;margin:auto;background:#ffffff;padding:20px;border-radius:8px;\">
            <tr><td style=\"text-align:center;\">
            <h1 style=\"color:#2c3e50;\">Recuperación de Contraseña</h1>
            <p style=\"font-size:16px;color:#333;\">Hola <strong>{$names}</strong>,</p>
            <p style=\"font-size:16px;color:#333;\">Hemos recibido una solicitud para restablecer tu contraseña.</p>
            <p style=\"font-size:16px;color:#333;\">Usa el siguiente código para completar el proceso:</p>
            <p style=\"font-size:24px;color:#e67e22;font-weight:bold;margin:20px 0;\">{$code}</p>
            <p style=\"font-size:14px;color:#777;\">Este código es válido por solo 10 minutos.</p>
            <hr style=\"border:none;border-top:1px solid #eee;margin:30px 0;\">
            <p style=\"font-size:14px;color:#999;\">Si no solicitaste esta acción, puedes ignorar este mensaje. Tu cuenta está segura.</p>
            <p style=\"font-size:14px;color:#999;\">Gracias, <br>El equipo de soporte de laboratorios Santa Lucía</p>
            </td></tr></table></div>";
        @mail($email, $subject, $html, "Content-Type: text/html; charset=UTF-8\r\nFrom: no-responder@labsantalucia.com.co");

        $response = ['success' => true, 'message' => 'Te hemos enviado un código de verificación a tu correo electrónico. Tiene una validez de 10 minutos.'];
        // En dev se incluye el código para poder probar sin SMTP.
        if (($this->getParameter('kernel.environment') ?? '') === 'dev') {
            $response['debug_code'] = $code;
        }
        return $this->json($response);
    }

    /**
     * Paso 2 de recuperación: valida código + vigencia y actualiza la contraseña.
     */
    #[Route('/reset_password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $code = trim((string) $request->request->get('code', ''));
        $password = (string) $request->request->get('password', '');

        if (!$this->isCsrfTokenValid('reset_pw', (string) $request->request->get('_csrf_token'))) {
            return $this->json(['success' => false, 'message' => 'Sesión inválida. Recargue la página e intente de nuevo.']);
        }
        if ($email === '' || $code === '' || $password === '') {
            return $this->json(['success' => false, 'message' => 'Debe completar todos los campos']);
        }
        $result = $this->usersService->resetPassword($email, $code, $password);
        return $this->json($result);
    }

    /**
     * Página de recuperación de contraseña (2 pasos: correo → código + nueva contraseña).
     */
    #[Route('/reset-password', name: 'reset_password_page', methods: ['GET'])]
    public function resetPasswordPage(Request $request): Response
    {
        return $this->render('security/reset-password.html.twig');
    }

    #[Route('/logout', name: 'security_logout', methods: ['GET', 'POST'])]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();
        if ($request->isXmlHttpRequest() || $request->isMethod('POST')) {
            return $this->json(['success' => true]);
        }
        return $this->redirectToRoute('security_login');
    }
}
