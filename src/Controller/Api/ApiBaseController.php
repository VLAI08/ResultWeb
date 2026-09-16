<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base para los endpoints /api/*: todas las rutas exigen sesión iniciada
 * (el acceso a datos personales y mutaciones no puede ser público).
 */
abstract class ApiBaseController extends AbstractController
{
    protected function sessionUser(Request $request): ?array
    {
        $user = $request->getSession()->get('user');
        return is_array($user) ? $user : null;
    }

    /**
     * Devuelve el usuario de sesión o responde 401 (y retorna null).
     */
    protected function requireSession(Request $request): ?array
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return null;
        }
        return $user;
    }

    protected function unauthorized(): JsonResponse
    {
        return $this->json(['message' => 'No autorizado'], 401);
    }

    protected function forbidden(): JsonResponse
    {
        return $this->json(['message' => 'No tienes permisos para esta acción'], 403);
    }

    /**
     * ¿El usuario de sesión tiene la acción (o es admin)?
     */
    protected function hasAction(array $user, string $action): bool
    {
        if (($user['type'] ?? '') === 'admin') {
            return true;
        }
        return in_array($action, (array) ($user['actions'] ?? []), true);
    }
}
