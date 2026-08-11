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
        }

        if (!$user) {
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

        $session->remove('login_attempts');
        $session->remove('login_blocked_until');
        $session->set('type', $user['type']);
        $session->set('user', $user);

        return $this->json([
            'state' => '111',
            'message' => 'Bienvenido',
            'user' => $user,
        ]);
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
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Ingrese un correo electrónico válido']);
        }

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
