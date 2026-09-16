<?php

namespace App\Controller\Api;

use App\Controller\Concerns\SessionUserTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base para los endpoints /api/*: todas las rutas exigen sesión iniciada
 * (el acceso a datos personales y mutaciones no puede ser público).
 */
abstract class ApiBaseController extends AbstractController
{
    use SessionUserTrait;

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
        return $this->forbiddenAccess();
    }

    /**
     * Alias coherente con los controladores que usan el trait directamente.
     */
    protected function hasAction(array $user, string $action): bool
    {
        return $this->userHasAction($user, $action);
    }
}
